<?php

namespace App\Security;

use App\Captcha\Turnstile;
use App\Enum\Feature;
use App\Feature\FeatureFlags;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Turnstile on the login form, which form_login handles without a Symfony
 * form to add a field to. Runs after the CSRF check (priority 512) and before
 * the user is loaded or the password checked (256 and 0), so a bot learns
 * nothing about accounts. A rejected check is a failed login like any other:
 * throttled, audited, and shown on the login page.
 */
final class LoginCaptchaListener
{
    /** The login form's field that the Turnstile widget posts its token under. */
    public const string FIELD = 'captcha';

    public function __construct(
        private readonly FeatureFlags $features,
        private readonly ValidatorInterface $validator,
        private readonly RequestStack $requestStack,
    ) {}

    #[AsEventListener(priority: 384)]
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        if (!$event->getAuthenticator() instanceof FormLoginAuthenticator || !$this->features->isEnabled(Feature::LoginCaptcha)) {
            return;
        }

        $token = $this->requestStack->getCurrentRequest()?->request->all()[self::FIELD] ?? '';
        $constraint = new Turnstile();
        if (!\is_string($token) || \count($this->validator->validate($token, $constraint)) > 0) {
            throw new CustomUserMessageAuthenticationException($constraint->message);
        }
    }
}
