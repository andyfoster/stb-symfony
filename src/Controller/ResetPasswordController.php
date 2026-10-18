<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Route('/reset-password')]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(Request $request, UserRepository $users, MailerInterface $mailer): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $users->findOneByEmail((string) $form->get('email')->getData());

            return $this->sendResetEmail($user, $mailer);
        }

        return $this->render('reset_password/request.html.twig', ['form' => $form]);
    }

    /**
     * Shown whether or not the email has an account, so the form can't be used to find out.
     */
    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        // A fake token when no email was sent, so the page looks the same either way.
        $resetToken = $this->getTokenObjectFromSession() ?? $this->resetPasswordHelper->generateFakeResetToken();

        return $this->render('reset_password/check_email.html.twig', ['resetToken' => $resetToken]);
    }

    #[Route('/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, UserPasswordHasherInterface $hasher, TranslatorInterface $translator, ?string $token = null): Response
    {
        if ($token) {
            // Move the token from the URL to the session, so it doesn't leak via Referer or history.
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();

        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('error', $translator->trans($e->getReason(), [], 'ResetPasswordBundle'));

            return $this->redirectToRoute('app_forgot_password');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Each token works once.
            $this->resetPasswordHelper->removeResetRequest($token);

            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            // They proved they own the inbox.
            $user->setVerified(true);
            $this->entityManager->flush();
            $this->cleanSessionAfterReset();

            $this->addFlash('success', 'Your password has been changed. Log in with your new password.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', ['form' => $form]);
    }

    private function sendResetEmail(?User $user, MailerInterface $mailer): RedirectResponse
    {
        if (null === $user) {
            return $this->redirectToRoute('app_check_email');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Usually "already requested recently". Don't say so: it would reveal the account exists.
            return $this->redirectToRoute('app_check_email');
        }

        $mailer->send((new TemplatedEmail())
            ->to(new Address((string) $user->getEmail(), (string) $user->getDisplayName()))
            ->subject('Reset your ScarfieTextbooks password')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context(['resetToken' => $resetToken]));

        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('app_check_email');
    }
}
