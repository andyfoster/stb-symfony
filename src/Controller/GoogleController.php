<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\EmailChanger;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Exception\OAuth2ClientException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Continue with Google". Google sends people back to app_google_check, which
 * App\Security\GoogleAuthenticator handles.
 *
 * Also confirms an email change on an account that only signs in with Google,
 * which has no password to ask for: they sign in to Google again instead.
 */
final class GoogleController extends AbstractController
{
    /** The new address from Your details, waiting for Google to confirm it's them. */
    public const string PENDING_EMAIL = 'google_pending_email';

    public function __construct(
        private readonly ClientRegistry $clients,
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')] private readonly string $clientId,
    ) {}

    #[Route('/connect/google', name: 'app_google_start', methods: ['GET'])]
    public function start(): Response
    {
        if ($this->clientId === '') {
            throw $this->createNotFoundException('Google sign-in isn’t set up.');
        }
        if ($this->getUser()) {
            return $this->redirectToRoute('app_listings');
        }

        return $this->clients->getClient('google')->redirect(['openid', 'email', 'profile'], ['prompt' => 'select_account']);
    }

    #[Route('/connect/google/check', name: 'app_google_check', methods: ['GET'])]
    public function check(): never
    {
        throw new \LogicException('Intercepted by App\Security\GoogleAuthenticator on the firewall.');
    }

    /**
     * After Your details accepted the new address. max_age=0 asks Google to
     * have them sign in again, not just reuse a session on this device.
     */
    #[Route('/account/email/google', name: 'app_account_email_google', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function confirmEmailStart(Request $request): Response
    {
        if ($this->clientId === '' || !$request->getSession()->has(self::PENDING_EMAIL)) {
            return $this->redirectToRoute('app_account_details');
        }

        return $this->clients->getClient('google_confirm')->redirect(['openid'], ['prompt' => 'select_account', 'max_age' => 0]);
    }

    #[Route('/account/email/google/check', name: 'app_account_email_google_check', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function confirmEmail(Request $request, UserRepository $users, EmailChanger $emailChanger, #[CurrentUser] User $user): Response
    {
        $email = $request->getSession()->remove(self::PENDING_EMAIL);
        if (!\is_string($email)) {
            return $this->redirectToRoute('app_account_details');
        }

        try {
            $google = $this->clients->getClient('google_confirm')->fetchUser();
        } catch (OAuth2ClientException|IdentityProviderException) {
            $this->addFlash('error', 'We couldn’t confirm it’s you with Google, so your email hasn’t changed.');

            return $this->redirectToRoute('app_account_details');
        }

        if ($user->getGoogleId() === null || (string) $google->getId() !== $user->getGoogleId()) {
            $this->addFlash('error', 'That isn’t the Google account you sign in with, so your email hasn’t changed.');

            return $this->redirectToRoute('app_account_details');
        }

        // Someone may have taken it while they were at Google.
        if ($users->findOneByEmail($email) !== null) {
            $this->addFlash('error', 'There is already an account with this email.');

            return $this->redirectToRoute('app_account_details');
        }

        $emailChanger->change($user, $email);
        $this->addFlash('success', \sprintf('Your email is now %s. We’ve sent a link there to confirm it.', $user->getEmail()));

        return $this->redirectToRoute('app_account_details');
    }
}
