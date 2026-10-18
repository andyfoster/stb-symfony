<?php

namespace App\Form;

use App\Enum\Category;
use App\Enum\ListingType;
use App\Search\ListingFilter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * GET form for the home page filters. No block prefix, so the query string
 * stays readable (?q=chem&category=textbooks).
 */
class ListingFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('q', SearchType::class, [
                'label' => 'Search',
                'required' => false,
                'attr' => ['placeholder' => 'Search titles, authors, paper codes or ISBNs'],
            ])
            ->add('type', EnumType::class, [
                'class' => ListingType::class,
                'label' => 'Show',
                'required' => false,
                'placeholder' => 'Everything',
                'choice_label' => static fn (ListingType $type): string => $type->label(),
            ])
            ->add('category', EnumType::class, [
                'class' => Category::class,
                'label' => 'Category',
                'required' => false,
                'placeholder' => 'All categories',
                'choice_label' => static fn (Category $category): string => $category->label(),
            ])
            ->add('maxPrice', IntegerType::class, [
                'label' => 'Max price ($)',
                'required' => false,
                'attr' => ['min' => 0, 'placeholder' => 'Any'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ListingFilter::class,
            'method' => 'GET',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
