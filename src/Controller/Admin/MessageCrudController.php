<?php

namespace App\Controller\Admin;

use App\Entity\Message;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Read-only view of contact messages, for spotting and deleting spam.
 *
 * @extends AbstractCrudController<Message>
 */
final class MessageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Message::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Message')
            ->setEntityLabelInPlural('Messages')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['senderName', 'senderEmail', 'senderIp', 'body']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('senderEmail')->add('senderIp')->add('recipient')->add('createdAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield DateTimeField::new('createdAt', 'Sent');
        yield TextField::new('senderName', 'From');
        yield EmailField::new('senderEmail', 'Email');
        yield TextField::new('senderIp', 'IP');
        yield AssociationField::new('recipient', 'To')->formatValue(static fn ($value, Message $message): string => (string) $message->getRecipient()?->getEmail());
        yield AssociationField::new('listing');
        yield TextareaField::new('body')->hideOnIndex();
        yield DateTimeField::new('readAt', 'Read')->hideOnIndex();
    }
}
