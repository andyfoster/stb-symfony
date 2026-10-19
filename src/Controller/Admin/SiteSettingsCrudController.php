<?php

namespace App\Controller\Admin;

use App\Entity\SiteSettings;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

/**
 * The single settings row; the dashboard menu links straight to its edit page.
 *
 * @extends AbstractCrudController<SiteSettings>
 */
final class SiteSettingsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SiteSettings::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Settings')
            ->setEntityLabelInPlural('Settings')
            ->setPageTitle(Crud::PAGE_EDIT, 'Site settings');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::DELETE, Action::INDEX, Action::DETAIL, Action::SAVE_AND_RETURN)
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE, static fn(Action $action): Action => $action->setLabel('Save settings'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('listingLifetimeDays', 'Listing lifetime (days)')
            ->setHelp('How long a listing stays up after it’s published or refreshed. Applies to listings published or refreshed from now on.');
    }
}
