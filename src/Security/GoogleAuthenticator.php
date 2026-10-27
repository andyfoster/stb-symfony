<?php

namespace App\Security;

use App\Enum\Feature;
use App\Feature\FeatureFlags;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use KnpU\OAuth2ClientBundle\Security\Exception\IdentityProviderAuthenticationException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Google's redirect back to /connect/google/check (GoogleController starts it).
 * GoogleAccounts picks the account; failures go back to the login page.
 */
final class GoogleAuthenticator extends OAuth2Authenticator
{
    use TargetPathTrait;

    /** Set when this sign-in made a new account, for the welcome message. */
    private bool $created = false;

    public function __construct(
        private readonly ClientRegistry $clients,
        private readonly GoogleAccounts $accounts,
        private readonly UrlGeneratorInterface $urls,
        private readonly FeatureFlags $features,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'app_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        // Someone may come back from Google after an admin switched it off.
        if (!$this->features->isEnabled(Feature::GoogleSignIn)) {
            throw new CustomUserMessageAuthenticationException('Signing in with Google is turned off. Log in with your email instead.');
        }

        $client = $this->clients->getClient('google');
        $token  = $this->fetchAccessToken($client);

        try {
            /** @var GoogleUser $google */
            $google = $client->fetchUserFromToken($token);
        } catch (IdentityProviderException $e) {
            throw new IdentityProviderAuthenticationException($e);
        }

        if (!$google->getEmail() || $google->getEmailVerified() !== true) {
            throw new CustomUserMessageAuthenticationException('Google hasn’t confirmed the email on that account. Use another account, or sign up with your email.');
        }

        return new SelfValidatingPassport(new UserBadge(
            mb_strtolower($google->getEmail()),
            fn () => $this->accounts->userFor($google, $this->created),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if ($this->created) {
            $request->getSession()->getFlashBag()->add('success', \sprintf('Welcome, %s!', $token->getUser()?->getDisplayName()));
        }

        return new RedirectResponse($this->getTargetPath($request->getSession(), $firewallName) ?? $this->urls->generate('app_listings'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // The login page shows it, as it does a wrong password.
        $this->saveAuthenticationErrorToSession($request, $exception);

        return new RedirectResponse($this->urls->generate('app_login'));
    }
}
