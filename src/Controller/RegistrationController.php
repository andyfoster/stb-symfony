<?php

namespace App\Controller;

use App\Audit\AuditLog;
use App\Entity\User;
use App\Enum\AuditType;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

final class RegistrationController extends AbstractController
{
    public function __construct(private EmailVerifier $emailVerifier) {}

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $entityManager, Security $security, AuditLog $audit): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_listings');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $entityManager->persist($user);
            $entityManager->flush();
            $audit->record(AuditType::UserRegistered, $user, actor: $user);

            $this->emailVerifier->sendConfirmation($user);
            $security->login($user, 'form_login', 'main');
            $this->addFlash('success', \sprintf('Welcome, %s! We’ve sent a link to %s to confirm your email.', $user->getDisplayName(), $user->getEmail()));

            return $this->redirectToRoute('app_listings');
        }

        return $this->render('registration/register.html.twig', ['form' => $form]);
    }

    /**
     * The link from the confirmation email. Doesn't need a session, so it works on any device.
     */
    #[Route('/verify/email', name: 'app_verify_email', methods: ['GET'])]
    public function verifyEmail(Request $request, UserRepository $users, TranslatorInterface $translator): Response
    {
        $user = $users->find($request->query->getInt('id'));

        if ($user === null) {
            return $this->redirectToRoute('app_listings');
        }

        try {
            $this->emailVerifier->confirm($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute($this->getUser() ? 'app_listings' : 'app_login');
        }

        $this->addFlash('success', 'Thanks, your email is confirmed.');

        return $this->redirectToRoute('app_listings');
    }

    #[Route('/verify/resend', name: 'app_verify_resend', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[IsCsrfTokenValid('resend-verification')]
    public function resendVerification(#[CurrentUser] User $user): Response
    {
        if (!$user->isVerified()) {
            $this->emailVerifier->sendConfirmation($user);
            $this->addFlash('success', \sprintf('We’ve sent a new confirmation link to %s.', $user->getEmail()));
        }

        return $this->redirectToRoute('app_listings');
    }
}
