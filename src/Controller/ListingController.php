<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Entity\User;
use App\Enum\Category;
use App\Enum\ListingType;
use App\Form\ListingFilterType;
use App\Form\ListingFormType;
use App\Listing\ListingEditor;
use App\Repository\ListingRepository;
use App\Search\ListingFilter;
use App\Security\Voter\ListingVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListingController extends AbstractController
{
    #[Route('/', name: 'app_listings', methods: ['GET'])]
    public function index(Request $request, ListingRepository $listings): Response
    {
        $filter = new ListingFilter();
        $form = $this->createForm(ListingFilterType::class, $filter);
        $form->handleRequest($request);

        // Invalid filters (e.g. a negative price) show their errors and fall back to everything.
        $applied = $form->isSubmitted() && !$form->isValid() ? new ListingFilter() : $filter;

        return $this->render('listing/index.html.twig', [
            // A view, not the form, so a bad filter doesn't turn the page into a 422.
            'form' => $form->createView(),
            'listings' => $listings->findByFilter($applied),
            'filtered' => $form->isSubmitted() && $form->isValid() && $filter != new ListingFilter(),
        ]);
    }

    #[Route('/listings/{id}', name: 'app_listing_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(ListingVoter::VIEW, 'listing', 'Listing not found.', 404)]
    public function show(Listing $listing): Response
    {
        return $this->render('listing/show.html.twig', ['listing' => $listing]);
    }

    #[Route('/sell', name: 'app_listing_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request, ListingEditor $editor, #[CurrentUser] User $user): Response
    {
        $listing = (new Listing())->setSeller($user)->setType(ListingType::ForSale)->setCategory(Category::Textbooks);

        return $this->edit($listing, $request, $editor);
    }

    #[Route('/listings/{id}/edit', name: 'app_listing_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    #[IsGranted(ListingVoter::EDIT, 'listing')]
    public function edit(Listing $listing, Request $request, ListingEditor $editor): Response
    {
        $isNew = null === $listing->getId();
        $form = $this->createForm(ListingFormType::class, $listing);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $publish = !$form->get('saveDraft')->isClicked();
            $editor->save($listing, $publish);

            $this->addFlash('success', match (true) {
                !$publish => 'Saved as a draft. Publish it when you’re ready.',
                $isNew => 'Your listing is live.',
                default => 'Listing updated.',
            });

            return $this->redirectToRoute(
                $publish ? 'app_listing_show' : 'app_account_listings',
                $publish ? ['id' => $listing->getId()] : [],
                Response::HTTP_SEE_OTHER,
            );
        }

        return $this->render($isNew ? 'listing/new.html.twig' : 'listing/edit.html.twig', [
            'listing' => $listing,
            'form' => $form,
        ]);
    }

    #[Route('/listings/{id}/refresh', name: 'app_listing_refresh', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(ListingVoter::EDIT, 'listing')]
    #[IsCsrfTokenValid('submit', tokenKey: '_csrf_token')]
    public function refresh(Listing $listing, ListingEditor $editor): Response
    {
        if (!$listing->canRefresh()) {
            $this->addFlash('error', \sprintf('You can refresh a listing in the last %d days before it expires.', Listing::REFRESH_WINDOW_DAYS));
        } else {
            $editor->refresh($listing);
            $this->addFlash('success', \sprintf('“%s” is refreshed until %s.', $listing->getTitle(), $listing->getExpiresAt()?->format('j M Y')));
        }

        return $this->redirectToRoute('app_account_listings', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/listings/{id}/sold', name: 'app_listing_sold', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(ListingVoter::EDIT, 'listing')]
    #[IsCsrfTokenValid('submit', tokenKey: '_csrf_token')]
    public function sold(Listing $listing, ListingEditor $editor): Response
    {
        $editor->markSold($listing);
        $this->addFlash('success', \sprintf('“%s” is marked as sold.', $listing->getTitle()));

        return $this->redirectToRoute('app_account_listings', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/listings/{id}/delete', name: 'app_listing_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(ListingVoter::EDIT, 'listing')]
    #[IsCsrfTokenValid('submit', tokenKey: '_csrf_token')]
    public function delete(Listing $listing, ListingEditor $editor): Response
    {
        $title = $listing->getTitle();
        $editor->delete($listing);
        $this->addFlash('success', \sprintf('“%s” is deleted.', $title));

        return $this->redirectToRoute('app_account_listings', [], Response::HTTP_SEE_OTHER);
    }
}
