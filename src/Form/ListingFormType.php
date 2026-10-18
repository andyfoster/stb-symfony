<?php

namespace App\Form;

use App\Entity\Listing;
use App\Enum\Category;
use App\Enum\Condition;
use App\Enum\ListingType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Create and edit a listing. Named ListingFormType so it doesn't clash with
 * the App\Enum\ListingType enum.
 */
class ListingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Listing $listing */
        $listing = $builder->getData();

        $builder
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
                'required' => false,
                'placeholder' => 'Not specified',
                'choice_label' => static fn (Condition $condition): string => $condition->label(),
            ])
            ->add('location', TextType::class, [
                'label' => 'Pick-up location',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. North Dunedin', 'maxlength' => 100],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 6, 'placeholder' => 'Condition, edition, reason for selling, and included extras'],
            ])
            ->add('book', BookDetailsFormType::class, [
                'label' => false,
            ]);

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
        }, 10);

    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Listing::class,
        ]);
    }
}
