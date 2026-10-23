<?php

namespace App\Controller\Admin;

use App\Audit\AuditLog;
use App\Entity\AuditEvent;
use App\Enum\AuditType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * The audit log: logins, account changes, listings and admin edits, newest
 * first. Read-only; rows older than AuditLog::RETENTION_DAYS are pruned nightly.
 *
 * @extends AbstractCrudController<AuditEvent>
 */
final class AuditEventCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AuditEvent::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Audit event')
            ->setEntityLabelInPlural('Audit log')
            ->setDefaultSort(['createdAt' => 'DESC', 'id' => 'DESC'])
            ->setSearchFields(['email', 'ip', 'subjectLabel', 'userAgent'])
            ->setPaginatorPageSize(50)
            ->setHelp(Crud::PAGE_INDEX, \sprintf('Kept for %d days. Search by email, IP, listing title or browser.', AuditLog::RETENTION_DAYS));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('type')->add('actor')->add('email')->add('ip')->add('subjectType')->add('createdAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'When');
        yield ChoiceField::new('type', 'Event')->setChoices(AuditType::cases())
            ->setFormTypeOption('choice_label', static fn (AuditType $type): string => $type->label())
            ->renderAsBadges(array_combine(
                array_map(static fn (AuditType $type): string => $type->value, AuditType::cases()),
                array_map(static fn (AuditType $type): string => $type->badge(), AuditType::cases()),
            ));
        // formatValue() output is rendered as HTML, and failed logins store whatever was typed.
        yield TextField::new('email', 'Who')->formatValue(static fn (?string $email): string => $email === null ? 'Guest' : htmlspecialchars($email));
        yield AssociationField::new('actor', 'Account')->hideOnIndex();
        yield Field::new('subject', 'What')->setTemplatePath('admin/field/audit_subject.html.twig')->setSortable(false);
        yield TextField::new('ip', 'IP');
        yield TextField::new('userAgent', 'Browser')->hideOnIndex();
        yield Field::new('context', 'Details')->setTemplatePath('admin/field/audit_context.html.twig')->setSortable(false);
    }
}
