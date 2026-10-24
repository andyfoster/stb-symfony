<?php

namespace App\Form;

use App\Book\Edition;
use App\Entity\BookDetails;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The textbook part of the listing form. Not required, so it stays null when
 * every field is left empty.
 */
class BookDetailsFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('isbn', TextType::class, [
                'label' => 'ISBN',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'placeholder' => '978…'],
            ])
            ->add('author', TextType::class, [
                'label' => 'Author(s)',
                'empty_data' => '',
            ])
            ->add('edition', TextType::class, [
                'label' => 'Edition',
                'required' => false,
                'invalid_message' => 'Enter the edition as a number, like 12.',
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'placeholder' => 'e.g. 12'],
            ])
            ->add('year', IntegerType::class, [
                'label' => 'Year',
                'required' => false,
                'attr' => ['min' => 1000, 'max' => 9999, 'placeholder' => 'e.g. 2024'],
            ])
            ->add('papers', PaperTagsType::class, [
                'label' => 'Papers (optional)',
                'attr' => ['placeholder' => 'CHEM191, CHEM192', 'autocomplete' => 'off'],
            ]);

        // People type "12th", "12e" or "Twelfth"; keep just the number.
        $builder->get('edition')->addModelTransformer(new CallbackTransformer(
            static fn (?int $edition): string => (string) $edition,
            static function (?string $edition): ?int {
                if (null === $edition || '' === trim($edition)) {
                    return null;
                }

                return Edition::parse($edition) ?? throw new TransformationFailedException('Not an edition number.');
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BookDetails::class,
            'required' => false,
        ]);
    }
}
