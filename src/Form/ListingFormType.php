<?php

namespace App\Form;

use App\Entity\Listing;
use App\Entity\ListingPhoto;
use App\Enum\Category;
use App\Enum\Condition;
use App\Enum\ListingType;
use App\Listing\ListingExpiry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Create and edit a listing. Named ListingFormType so it doesn't clash with
 * the App\Enum\ListingType enum.
 */
class ListingFormType extends AbstractType
{
    public function __construct(private readonly ListingExpiry $expiry)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Listing $listing */
        $listing = $builder->getData();

        $hideAfter = $this->expiry->dateFor($listing->getExpiresAt());
        if (null !== $hideAfter && ($hideAfter < $this->expiry->today() || $hideAfter > $this->expiry->latestDate())) {
            $hideAfter = null;
        }

        $builder
            ->add('hideAfter', DateType::class, [
                'label' => 'Hide after',
                'mapped' => false,
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => 'Pacific/Auckland',
                'view_timezone' => 'Pacific/Auckland',
                'data' => $hideAfter,
                'help' => 'Optional. Up to '.$this->expiry->latestDate()->format('j M Y').'.',
                'attr' => ['min' => $this->expiry->today()->format('Y-m-d'), 'max' => $this->expiry->latestDate()->format('Y-m-d')],
                'constraints' => [
                    new Assert\GreaterThanOrEqual($this->expiry->today(), message: 'Choose today or a later date.'),
                    new Assert\LessThanOrEqual($this->expiry->latestDate(), message: 'Choose a date within the next year.'),
                ],
            ])
            ->add('type', EnumType::class, [
                'class' => ListingType::class,
                'label' => 'I want to',
                'expanded' => true,
                'choice_label' => static fn (ListingType $type): string => ListingType::ForSale === $type ? 'Sell something' : 'Find something',
            ])
            ->add('title', TextType::class, [
                'label' => 'Title',
                'empty_data' => '',
                'attr' => ['placeholder' => 'e.g. CHEM191 Textbook + lab manual', 'maxlength' => 255],
            ])
            ->add('category', EnumType::class, [
                'class' => Category::class,
                'label' => 'Category',
                'choice_label' => static fn (Category $category): string => $category->label(),
            ])
            ->add('priceCents', MoneyType::class, [
                'label' => 'Price (NZD)',
                'required' => false,
                'divisor' => 100,
                'input' => 'integer',
                'currency' => false,
                'html5' => true,
                'help' => 'Leave blank for “make an offer”. 0 means free.',
                'attr' => ['min' => 0, 'step' => '0.01', 'placeholder' => '0.00'],
            ])
            ->add('condition', EnumType::class, [
                'class' => Condition::class,
                'label' => 'Condition',
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'choice_label' => static fn (Condition $condition): string => $condition->label(),
                'choice_attr' => static fn (Condition $condition): array => ['data-description' => $condition->description()],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 6, 'placeholder' => 'Condition, edition, reason for selling, and included extras'],
            ])
            ->add('book', BookDetailsFormType::class, [
                'label' => false,
            ])
            ->add('photos', FileType::class, [
                'label' => \sprintf('Photos (up to %d)', Listing::MAX_PHOTOS),
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [
                    new Assert\All([
                        new Assert\Image(
                            maxSize: '10M',
                            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                            mimeTypesMessage: 'Photos must be JPEG, PNG or WebP images.',
                        ),
                    ]),
                ],
            ]);

        if (!$listing->getPhotos()->isEmpty()) {
            $builder->add('removePhotos', ChoiceType::class, [
                'label' => 'Remove photos',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => $listing->getPhotos()->toArray(),
                'choice_value' => static fn (?ListingPhoto $photo): string => (string) $photo?->getId(),
                'choice_label' => static fn (ListingPhoto $photo, int $index): string => \sprintf('Photo %d', $index + 1),
            ]);
        }

        $builder
            ->add('publish', SubmitType::class, ['label' => $listing->isLive() ? 'Save changes' : 'Publish listing'])
            ->add('saveDraft', SubmitType::class, ['label' => $listing->isDraft() || null === $listing->getId() ? 'Save draft' : 'Move to drafts']);

        // Before validation: only textbooks keep book details, so other
        // categories don't need an author and don't get a book row.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $listing = $event->getData();
            if ($listing instanceof Listing && Category::Textbooks !== $listing->getCategory()) {
                $listing->setBook(null);
            }
            // Wanted listings don't have a condition (the field is hidden for them).
            if ($listing instanceof Listing && ListingType::Wanted === $listing->getType()) {
                $listing->setCondition(null);
            }
        }, 10);

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            /** @var Listing $listing */
            $listing = $event->getData();

            $removing = $form->has('removePhotos') ? \count($form->get('removePhotos')->getData() ?? []) : 0;
            $adding = \count(array_filter($form->get('photos')->getData() ?? []));
            if ($listing->getPhotos()->count() - $removing + $adding > Listing::MAX_PHOTOS) {
                $form->get('photos')->addError(new FormError(\sprintf('You can add up to %d photos.', Listing::MAX_PHOTOS)));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Listing::class,
        ]);
    }
}
