<?php

namespace App\Form;

use App\Enum\Category;
use App\Enum\ListingType;
use App\Search\ListingFilter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * GET form for the home page filters. No block prefix, so the query string
 * stays readable (?q=chem&type=wanted&categories[]=textbooks).
 */
class ListingFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('q', SearchType::class, [
                'label'    => 'Search',
                'required' => false,
                'attr'     => ['placeholder' => 'Search by title, author, paper or ISBN'],
            ])
            // Rendered as the "For sale" / "Wanted" tabs above the results.
            ->add('type', EnumType::class, [
                'class'        => ListingType::class,
                'expanded'     => true,
                'empty_data'   => ListingType::ForSale->value,
                'choice_label' => static fn(ListingType $type): string => $type->label(),
            ])
            ->add('categories', EnumType::class, [
                'class'         => Category::class,
                'label'         => 'Categories',
                'property_path' => 'categories',
                'required'      => false,
                'multiple'      => true,
                'expanded'      => true,
                'choice_label'  => static fn(Category $category): string => $category->label(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ListingFilter::class,
            'method'             => 'GET',
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
