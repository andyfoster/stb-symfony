<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\PasswordSetter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * People sign up on the site, so admins only edit and delete accounts here,
 * and reset passwords for people who can't use the reset email.
 *
 * @extends AbstractCrudController<User>
 */
final class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokens,
        private readonly PasswordSetter $passwords,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['email', 'displayName', 'phone']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $resetPassword = Action::new('resetPassword', 'Reset password', 'fa fa-key')
            // EasyAdmin doesn't put a CSRF token on custom actions, so it travels in the URL.
            ->linkToRoute('admin_user_reset_password', fn (User $user): array => [
                'id' => $user->getId(),
                'token' => $this->csrfTokens->getToken('reset-password'.$user->getId())->getValue(),
            ])
            ->renderAsForm()
            ->askConfirmation(
                \sprintf('Reset the password to “%s”? They’ll log in with it and should change it straight away. Their remembered devices are signed out.', PasswordSetter::DEFAULT_PASSWORD),
                'Reset password',
            );

        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $resetPassword)
            ->add(Crud::PAGE_DETAIL, $resetPassword)
            ->add(Crud::PAGE_EDIT, $resetPassword);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('verified')->add('createdAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email');
        yield TextField::new('displayName', 'Name');
        yield BooleanField::new('verified', 'Email confirmed')->renderAsSwitch(false);
        yield BooleanField::new('admin')->renderAsSwitch(false)->setHelp('Admins see this panel.');
        yield TelephoneField::new('phone')->hideOnIndex();
        yield BooleanField::new('showPhone', 'Shows phone')->renderAsSwitch(false)->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Joined')->hideOnForm();
    }

    #[AdminRoute('/{id}/reset-password', name: 'reset_password', options: ['methods' => ['POST']])]
    public function resetPassword(User $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('reset-password'.$user->getId(), $request->query->getString('token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->passwords->set($user);
        $this->addFlash('success', \sprintf('%s can now log in with “%s”. Ask them to change it.', $user->getEmail(), PasswordSetter::DEFAULT_PASSWORD));

        return $this->redirectToRoute('admin_user_detail', ['entityId' => $user->getId()]);
    }
}
