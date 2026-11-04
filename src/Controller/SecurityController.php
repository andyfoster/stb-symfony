<?php

namespace App\Controller;

use App\Enum\Feature;
use App\Feature\FeatureFlags;
use App\Security\LoginCaptchaListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    /**
     * Shows the form; the firewall's form_login handles the POST, and
     * LoginCaptchaListener checks its Turnstile token.
     */
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(
        AuthenticationUtils $authenticationUtils,
        FeatureFlags $features,
        #[Autowire('%env(TURNSTILE_SITE_KEY)%')]
        string $turnstileSiteKey,
    ): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_listings');
        }

        return $this->render('security/login.html.twig', [
            'last_email' => $authenticationUtils->getLastUsername(),
            'error'      => $authenticationUtils->getLastAuthenticationError(),
            'turnstile_site_key' => $features->isEnabled(Feature::LoginCaptcha) ? $turnstileSiteKey : null,
            'turnstile_field'    => LoginCaptchaListener::FIELD,
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key on the firewall.');
    }
}
