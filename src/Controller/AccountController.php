<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Form\ChangeEmailFormType;
use App\Form\ChangePasswordFormType;
use App\Form\ProfileFormType;
use App\Repository\ListingRepository;
use App\Repository\MessageRepository;
use App\Security\EmailChanger;
use App\Security\PasswordSetter;
use App\Settings\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/account')]
#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    /**
     * Your name, phone, email, password and Google sign-in. Accounts that only
     * sign in with Google have no password to check, so changing their email or
     * setting a password waits on signing in to Google again (GoogleController).
     */
    #[Route('', name: 'app_account', methods: ['GET', 'POST'])]
    public function account(Request $request, EntityManagerInterface $manager, EmailChanger $emailChanger, PasswordSetter $passwords, #[CurrentUser] User $user): Response
    {
        $confirmWithGoogle = $user->getPassword() === null && $user->getGoogleId() !== null;

        $emailForm = $this->createForm(ChangeEmailFormType::class, options: ['user' => $user, 'confirm_with_google' => $confirmWithGoogle]);
        $emailForm->handleRequest($request);

        if ($emailForm->isSubmitted() && $emailForm->isValid()) {
            $email = $emailForm->get('email')->getData();
            if ($confirmWithGoogle) {
                return $this->confirmWithGoogle($request, ['email' => $email]);
            }

            $emailChanger->change($user, $email);
            $this->addFlash('success', \sprintf('Your email is now %s. We’ve sent a link there to confirm it.', $user->getEmail()));

            return $this->redirectToRoute('app_account', [], Response::HTTP_SEE_OTHER);
        }

        $passwordForm = $this->createForm(ChangePasswordFormType::class, options: ['current_password' => $user->getPassword() !== null]);
        $passwordForm->handleRequest($request);

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $hash = $passwords->hash($user, $passwordForm->get('plainPassword')->getData());
            if ($confirmWithGoogle) {
                return $this->confirmWithGoogle($request, ['password' => $hash]);
            }

            $passwords->change($user, $hash);
            $this->addFlash('success', 'Your password is changed.');

            return $this->redirectToRoute('app_account', [], Response::HTTP_SEE_OTHER);
        }

        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager->flush();
            $this->addFlash('success', 'Your details are saved.');

            return $this->redirectToRoute('app_account', [], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted()) {
            // Don't leave the invalid values on the logged-in user (e.g. a blank name in the header).
            $manager->refresh($user);
        }

        return $this->render('account/account.html.twig', [
            'form'                => $form,
            'email_form'          => $emailForm,
            'password_form'       => $passwordForm,
            'confirm_with_google' => $confirmWithGoogle,
        ]);
    }

    /** The page's old address. */
    #[Route('/details', name: 'app_account_details', methods: ['GET'])]
    public function details(): Response
    {
        return $this->redirectToRoute('app_account', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/listings', name: 'app_account_listings', methods: ['GET'])]
    public function listings(ListingRepository $listings, SiteSettingsProvider $settings, #[CurrentUser] User $user): Response
    {
        return $this->render('account/listings.html.twig', [
            'listings'      => $listings->findBySeller($user),
            'lifetime_days' => $settings->listingLifetimeDays(),
        ]);
    }

    #[Route('/messages', name: 'app_account_messages', methods: ['GET'])]
    public function messages(MessageRepository $messages, #[CurrentUser] User $user): Response
    {
        return $this->render('account/messages.html.twig', [
            'messages' => $messages->findInbox($user),
        ]);
    }

    #[Route('/messages/{id}', name: 'app_account_message', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function message(Message $message, EntityManagerInterface $manager, #[CurrentUser] User $user): Response
    {
        if ($message->getRecipient()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Message not found.');
        }

        if (!$message->isRead()) {
            $message->markRead();
            $manager->flush();
        }

        return $this->render('account/message.html.twig', ['message' => $message]);
    }

    /** @param array{email: string}|array{password: string} $change */
    private function confirmWithGoogle(Request $request, array $change): Response
    {
        $request->getSession()->set(GoogleController::PENDING_CHANGE, $change);

        return $this->redirectToRoute('app_account_google', [], Response::HTTP_SEE_OTHER);
    }
}
