<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Entity\Message;
use App\Entity\User;
use App\Form\ChangeEmailFormType;
use App\Form\ProfileFormType;
use App\Repository\ListingRepository;
use App\Repository\MessageRepository;
use App\Security\EmailChanger;
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
     * The dashboard: what needs doing, at a glance.
     */
    #[Route('', name: 'app_account', methods: ['GET'])]
    public function overview(ListingRepository $listings, MessageRepository $messages, #[CurrentUser] User $user): Response
    {
        $mine = $listings->findBySeller($user);

        return $this->render('account/overview.html.twig', [
            'live'        => array_filter($mine, static fn(Listing $listing): bool => $listing->isLive()),
            'refreshable' => array_filter($mine, static fn(Listing $listing): bool => $listing->canRefresh()),
            'drafts'      => array_filter($mine, static fn(Listing $listing): bool => $listing->isDraft()),
            'messages'    => \array_slice($messages->findInbox($user), 0, 3),
            'unread'      => $messages->countUnread($user),
        ]);
    }

    #[Route('/details', name: 'app_account_details', methods: ['GET', 'POST'])]
    public function details(Request $request, EntityManagerInterface $manager, EmailChanger $emailChanger, #[CurrentUser] User $user): Response
    {
        $emailForm = $this->createForm(ChangeEmailFormType::class, options: ['user' => $user]);
        $emailForm->handleRequest($request);

        if ($emailForm->isSubmitted() && $emailForm->isValid()) {
            $emailChanger->change($user, $emailForm->get('email')->getData());
            $this->addFlash('success', \sprintf('Your email is now %s. We’ve sent a link there to confirm it.', $user->getEmail()));

            return $this->redirectToRoute('app_account_details', [], Response::HTTP_SEE_OTHER);
        }

        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager->flush();
            $this->addFlash('success', 'Your details are saved.');

            return $this->redirectToRoute('app_account_details', [], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted()) {
            // Don't leave the invalid values on the logged-in user (e.g. a blank name in the header).
            $manager->refresh($user);
        }

        return $this->render('account/details.html.twig', ['form' => $form, 'email_form' => $emailForm]);
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
}
