<?php

namespace App\Controller\Admin;

use App\Entity\Listing;
use App\Enum\Category;
use App\Enum\ListingStatus;
use App\Enum\ListingType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Admins can hide (move to drafts), mark sold, or change a listing's expiry
 * by editing it. Sellers create listings on the site, not here.
 *
 * @extends AbstractCrudController<Listing>
 */
final class ListingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Listing::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Listing')
            ->setEntityLabelInPlural('Listings')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['title', 'description', 'seller.email', 'seller.displayName']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $view = Action::new('viewOnSite', 'View on site', 'fa fa-eye')
            ->linkToRoute('app_listing_show', static fn(Listing $listing): array => ['id' => $listing->getId()]);

        // The Photos admin, showing just this listing's photos.
        $photos = Action::new('managePhotos', 'Photos', 'fa fa-images')
            ->linkToRoute('admin_listing_photo_index', static fn(Listing $listing): array => [
                'filters' => ['listing' => ['comparison' => '=', 'value' => $listing->getId()]],
            ])
            ->displayIf(static fn(Listing $listing): bool => !$listing->getPhotos()->isEmpty());

        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, $view)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $photos)
            ->add(Crud::PAGE_DETAIL, $photos);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('status')->add('type')->add('category')->add('seller')->add('expiresAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield Field::new('photos')->setTemplatePath('admin/field/listing_photos.html.twig')->setSortable(false)->hideOnForm();
        yield TextField::new('title');
        yield AssociationField::new('seller')->setDisabled()->formatValue(static fn($value, Listing $listing): string => (string) $listing->getSeller()?->getEmail());
        yield ChoiceField::new('status')->setChoices(ListingStatus::cases())
            ->setFormTypeOption('choice_label', static fn(ListingStatus $status): string => $status->label())
            ->renderAsBadges(['draft' => 'secondary', 'active' => 'success', 'sold' => 'info']);
        yield ChoiceField::new('type')->setChoices(ListingType::cases())
            ->setFormTypeOption('choice_label', static fn(ListingType $type): string => $type->label())->hideOnIndex();
        yield ChoiceField::new('category')->setChoices(Category::cases())
            ->setFormTypeOption('choice_label', static fn(Category $category): string => $category->label());
        yield MoneyField::new('priceCents', 'Price')->setCurrency('NZD')->setStoredAsCents()->setRequired(false);
        yield TextareaField::new('description')->hideOnIndex();
        yield DateTimeField::new('publishedAt')->hideOnForm();
        yield DateTimeField::new('expiresAt');
        yield DateTimeField::new('createdAt')->onlyOnDetail();
    }
}
