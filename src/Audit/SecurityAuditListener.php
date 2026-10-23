<?php

namespace App\Audit;

use App\Entity\User;
use App\Enum\AuditType;
use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Logins (form, remember-me, and signing in after sign-up), failed and
 * throttled logins, and logouts. Firewall events bubble up to these global
 * listeners.
 */
final class SecurityAuditListener
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly UserRepository $users,
    ) {}

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $rememberMe = $event->getAuthenticator() instanceof RememberMeAuthenticator;
        $this->audit->record(
            $rememberMe ? AuditType::RememberMeLogin : AuditType::LoginSucceeded,
            context: ['firewall' => $event->getFirewallName()],
            actor: $user,
        );
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        // The email they typed; never the password.
        $badge = $event->getPassport()?->getBadge(UserBadge::class);
        $email = $badge instanceof UserBadge ? $badge->getUserIdentifier() : (string) $event->getRequest()->getPayload()->get('email', '');
        $account = $email === '' ? null : $this->users->findOneByEmail($email);
        $exception = $event->getException();

        $this->audit->record(
            $exception instanceof TooManyLoginAttemptsAuthenticationException ? AuditType::LoginThrottled : AuditType::LoginFailed,
            context: ['reason' => $exception->getMessageKey(), 'account' => $account !== null],
            actor: $account,
            email: $email,
        );
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof User) {
            $this->audit->record(AuditType::Logout, actor: $user);
        }
    }
}
