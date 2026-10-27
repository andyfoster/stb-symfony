<?php

namespace App\Controller\Admin;

use App\Entity\FeatureFlag;
use App\Feature\FeatureFlags;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;

/**
 * One switch per App\Enum\Feature. Rows are added for new Features when the
 * page opens; admins can't add or delete them.
 *
 * @extends AbstractCrudController<FeatureFlag>
 */
final class FeatureFlagCrudController extends AbstractCrudController
{
    public function __construct(private readonly FeatureFlags $flags) {}

    public static function getEntityFqcn(): string
    {
        return FeatureFlag::class;
    }

    public function index(AdminContext $context): KeyValueStore|Response
    {
        $this->flags->sync();

        return parent::index($context);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Feature flag')
            ->setEntityLabelInPlural('Feature flags')
            ->setDefaultSort(['id' => 'ASC'])
            ->setPaginatorPageSize(100)
            ->setSearchFields(null)
            ->setHelp(Crud::PAGE_INDEX, 'Changes take effect straight away.');
    }

    public function configureActions(Actions $actions): Actions
    {
        // Edit stays enabled: the switches save through it.
        return $actions->disable(Action::NEW, Action::DELETE, Action::DETAIL, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('label', 'Feature')->hideOnForm()->setSortable(false);
        yield TextField::new('description')->hideOnForm()->setSortable(false)->setMaxLength(500);
        yield BooleanField::new('enabled', 'On')->renderAsSwitch()->setSortable(false);
    }
}
