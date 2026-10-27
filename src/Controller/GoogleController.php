<?php

namespace App\Controller;

use App\Enum\Feature;
use App\Feature\FeatureFlags;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Continue with Google". Google sends people back to app_google_check, which
 * App\Security\GoogleAuthenticator handles.
 */
final class GoogleController extends AbstractController
{
    #[Route('/connect/google', name: 'app_google_start', methods: ['GET'])]
    public function start(ClientRegistry $clients, FeatureFlags $features, #[Autowire('%env(GOOGLE_CLIENT_ID)%')] string $clientId): Response
    {
        if ($clientId === '' || !$features->isEnabled(Feature::GoogleSignIn)) {
            throw $this->createNotFoundException('Google sign-in isn’t available.');
        }
        if ($this->getUser()) {
            return $this->redirectToRoute('app_listings');
        }

        return $clients->getClient('google')->redirect(['openid', 'email', 'profile'], ['prompt' => 'select_account']);
    }

    #[Route('/connect/google/check', name: 'app_google_check', methods: ['GET'])]
    public function check(): never
    {
        throw new \LogicException('Intercepted by App\Security\GoogleAuthenticator on the firewall.');
    }
}
