<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileFormType;
use App\Repository\ListingRepository;
use App\Settings\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/account')]
#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    #[Route('', name: 'app_account', methods: ['GET', 'POST'])]
    public function profile(Request $request, EntityManagerInterface $manager, #[CurrentUser] User $user): Response
    {
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

        return $this->render('account/profile.html.twig', ['form' => $form]);
    }

    #[Route('/listings', name: 'app_account_listings', methods: ['GET'])]
    public function listings(ListingRepository $listings, SiteSettingsProvider $settings, #[CurrentUser] User $user): Response
    {
        return $this->render('account/listings.html.twig', [
            'listings' => $listings->findBySeller($user),
            'lifetime_days' => $settings->listingLifetimeDays(),
        ]);
    }
}
