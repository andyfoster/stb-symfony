<?php

namespace App\Controller\Admin;

use App\Entity\Paper;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Otago papers. Typed-in ones (not official) are worth a look: fix a typo by
 * editing the code, or mark a real paper official.
 *
 * @extends AbstractCrudController<Paper>
 */
final class PaperCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Paper::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Paper')
            ->setEntityLabelInPlural('Papers')
            ->setDefaultSort(['official' => 'ASC', 'code' => 'ASC'])
            ->setSearchFields(['code', 'title']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('official');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('code')->setHelp('Four letters and three numbers, like CHEM191.');
        yield TextField::new('title');
        yield BooleanField::new('official', 'From Otago’s list')->renderAsSwitch(false);
    }
}
