<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\AuditEventRepository;
use App\Repository\UserRepository;
use App\Security\IpBlocklist;
use App\Security\PasswordSetter;
use App\Security\UserSuspender;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * People sign up on the site, so admins only edit and delete accounts here,
 * reset passwords for people who can't use the reset email, and suspend
 * accounts (UserSuspender) or block the address they signed up from
 * (IpBlocklist), one at a time or ticked in bulk. The detail page lists the
 * account's recent audit events.
 *
 * @extends AbstractCrudController<User>
 */
final class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokens,
        private readonly PasswordSetter $passwords,
        private readonly AuditEventRepository $auditEvents,
        private readonly UserSuspender $suspender,
        private readonly IpBlocklist $blocklist,
        private readonly UserRepository $users,
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

        // The Audit log, showing just what this account did.
        $activity = Action::new('activity', 'Audit log', 'fa fa-clipboard-list')
            ->linkToRoute('admin_audit_event_index', static fn (User $user): array => [
                'filters' => ['actor' => ['comparison' => '=', 'value' => $user->getId()]],
            ]);

        $suspend = Action::new('suspend', 'Suspend', 'fa fa-user-slash')
            ->linkToRoute('admin_user_suspend', fn (User $user): array => [
                'id' => $user->getId(),
                'token' => $this->csrfTokens->getToken('suspend'.$user->getId())->getValue(),
            ])
            ->renderAsForm()
            ->displayIf(static fn (User $user): bool => !$user->isSuspended() && !$user->isAdmin())
            ->askConfirmation('Suspend this account? They’re signed out, can’t log in, and their listings are hidden until you unsuspend it.', 'Suspend');
        $unsuspend = Action::new('unsuspend', 'Unsuspend', 'fa fa-user-check')
            ->linkToRoute('admin_user_unsuspend', fn (User $user): array => [
                'id' => $user->getId(),
                'token' => $this->csrfTokens->getToken('suspend'.$user->getId())->getValue(),
            ])
            ->renderAsForm()
            ->displayIf(static fn (User $user): bool => $user->isSuspended());

        $suspendSelected = Action::new('suspendSelected', 'Suspend', 'fa fa-user-slash')->linkToCrudAction('suspendSelected');
        $blockSelected = Action::new('blockSignUpIps', 'Block sign-up IPs', 'fa fa-ban')->linkToCrudAction('blockSignUpIps');

        return $actions
            ->disable(Action::NEW)
            ->addBatchAction($suspendSelected)
            ->addBatchAction($blockSelected)
            ->add(Crud::PAGE_DETAIL, $suspend)
            ->add(Crud::PAGE_DETAIL, $unsuspend)
            ->add(Crud::PAGE_INDEX, $suspend)
            ->add(Crud::PAGE_INDEX, $unsuspend)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $activity)
            ->add(Crud::PAGE_INDEX, $resetPassword)
            ->add(Crud::PAGE_DETAIL, $resetPassword)
            ->add(Crud::PAGE_EDIT, $resetPassword);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('verified')->add('suspendedAt')->add('createdAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email');
        yield TextField::new('displayName', 'Name');
        yield BooleanField::new('verified', 'Email confirmed')->renderAsSwitch(false);
        yield BooleanField::new('admin')->renderAsSwitch(false)->setHelp('Admins see this panel.');
        yield DateTimeField::new('suspendedAt', 'Suspended')->hideOnForm()->setFormat('d MMM yyyy');
        yield TextField::new('signUpIp', 'Signed up from')->onlyOnDetail()
            ->formatValue(fn ($value, User $user): string => $this->auditEvents->findSignUpIp($user) ?? 'Not recorded');
        yield TelephoneField::new('phone')->hideOnIndex();
        yield BooleanField::new('showPhone', 'Shows phone')->renderAsSwitch(false)->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Joined')->hideOnForm();
        yield Field::new('recentActivity', 'Recent activity')->onlyOnDetail()
            ->setTemplatePath('admin/field/user_activity.html.twig')
            ->formatValue(fn ($value, User $user): array => $this->auditEvents->findRecentFor($user));
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

    #[AdminRoute('/{id}/suspend', name: 'suspend', options: ['methods' => ['POST']])]
    public function suspend(User $user, Request $request): Response
    {
        $this->checkToken('suspend'.$user->getId(), $request->query->getString('token'));
        if ($user->isAdmin()) {
            $this->addFlash('danger', 'Admins can’t be suspended. Take away their admin access first.');
        } else {
            $this->suspender->suspend($user);
            $this->addFlash('success', \sprintf('%s is suspended.', $user->getEmail()));
        }

        return $this->redirectToRoute('admin_user_detail', ['entityId' => $user->getId()]);
    }

    #[AdminRoute('/{id}/unsuspend', name: 'unsuspend', options: ['methods' => ['POST']])]
    public function unsuspend(User $user, Request $request): Response
    {
        $this->checkToken('suspend'.$user->getId(), $request->query->getString('token'));
        $this->suspender->unsuspend($user);
        $this->addFlash('success', \sprintf('%s can log in again, and their listings are back.', $user->getEmail()));

        return $this->redirectToRoute('admin_user_detail', ['entityId' => $user->getId()]);
    }

    #[AdminRoute('/suspend-selected', name: 'suspend_selected', options: ['methods' => ['POST']])]
    public function suspendSelected(BatchActionDto $batch): Response
    {
        $suspended = 0;
        foreach ($this->selected($batch, 'suspendSelected') as $user) {
            if (!$user->isAdmin() && !$user->isSuspended()) {
                $this->suspender->suspend($user);
                ++$suspended;
            }
        }
        $this->addFlash('success', \sprintf('Suspended %d %s. Admins are never suspended.', $suspended, $suspended === 1 ? 'account' : 'accounts'));

        return $this->redirectToRoute('admin_user_index');
    }

    /** Blocks the address each ticked account signed up from (recorded in the audit log). */
    #[AdminRoute('/block-sign-up-ips', name: 'block_sign_up_ips', options: ['methods' => ['POST']])]
    public function blockSignUpIps(BatchActionDto $batch): Response
    {
        $ips = [];
        foreach ($this->selected($batch, 'blockSignUpIps') as $user) {
            if (!$user->isAdmin() && null !== $ip = $this->auditEvents->findSignUpIp($user)) {
                $ips[$ip] = true;
            }
        }
        if ($ips === []) {
            $this->addFlash('warning', 'None of those accounts has a recorded sign-up address.');

            return $this->redirectToRoute('admin_user_index');
        }

        $result = $this->blocklist->addFromText(implode("\n", array_keys($ips)), 'Sign-up address of a blocked account');
        $message = \sprintf('Blocked %d %s.', \count($result['added']), \count($result['added']) === 1 ? 'address' : 'addresses');
        foreach ($result['rejected'] as $address => $reason) {
            $message .= \sprintf(' %s not blocked: %s.', $address, $reason);
        }
        $this->addFlash($result['rejected'] === [] ? 'success' : 'warning', $message);

        return $this->redirectToRoute('admin_blocked_ip_index');
    }

    /** @return list<User> */
    private function selected(BatchActionDto $batch, string $action): array
    {
        $this->checkToken('ea-batch-action-'.$action.'-'.User::class, $batch->getCsrfToken());

        return $this->users->findBy(['id' => $batch->getEntityIds()]);
    }

    private function checkToken(string $id, ?string $token): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
