<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\Category;
use App\Enum\ListingType;
use App\Form\ContactSellerType;
use App\Form\ListingFilterType;
use App\Form\ListingFormType;
use App\Listing\ListingEditor;
use App\Messaging\SellerMessenger;
use App\Repository\ListingRepository;
use App\Search\ListingFilter;
use App\Security\Voter\ListingVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
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
        $form   = $this->createForm(ListingFilterType::class, $filter);
        $form->handleRequest($request);

        // Invalid filters (e.g. an unknown category) show their errors and fall back to everything.
        $applied = $form->isSubmitted() && !$form->isValid() ? new ListingFilter() : $filter;

        $counts = $listings->countMatching($applied);
        $total = $counts[$applied->type->value];
        $pages = max(1, (int) ceil($total / ListingRepository::PAGE_SIZE));
        $requestedPage = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT);
        $page = min($pages, max(1, $requestedPage === false ? 1 : $requestedPage));

        return $this->render('listing/index.html.twig', [
            'form' => $form->createView(),
            'filter' => $applied,
            'listings' => $listings->findPage($applied, $page),
            'counts' => $counts,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pagination_query' => [
                'q' => $applied->q,
                'type' => $applied->type->value,
                'categories' => array_map(static fn (Category $category) => $category->value, $applied->categories),
            ],
        ]);
    }

    #[Route('/listings/{id}', name: 'app_listing_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(ListingVoter::VIEW, 'listing', 'Listing not found.', 404)]
    public function show(Listing $listing, #[CurrentUser] ?User $user): Response
    {
        return $this->render('listing/show.html.twig', [
            'listing' => $listing,
            'contact' => $this->contactForm($listing, $user),
        ]);
    }

    #[Route('/listings/{id}/contact', name: 'app_listing_contact', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(ListingVoter::VIEW, 'listing', 'Listing not found.', 404)]
    public function contact(Listing $listing, Request $request, SellerMessenger $messenger, #[CurrentUser] ?User $user): Response
    {
        if ($listing->getSeller() === null || $listing->getSeller()->getId() === $user?->getId() || !$listing->isLive()) {
            throw $this->createNotFoundException('This listing can’t be contacted.');
        }

        $form = $this->contactForm($listing, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $message = $form->getData();
            // Bots fill in the hidden field: act as if it worked and send nothing.
            if (trim((string) $form->get(ContactSellerType::HONEYPOT)->getData()) === '') {
                if (!$messenger->allow($request, $listing, $message)) {
                    $this->addFlash('error', 'You’ve sent a lot of messages recently. Please try again later.');

                    return $this->redirectToRoute('app_listing_show', ['id' => $listing->getId()], Response::HTTP_SEE_OTHER);
                }
                $messenger->send($request, $listing, $message->setSender($user));
            }

            $this->addFlash('success', \sprintf('Message sent to %s. They’ll reply to your email.', $listing->getSeller()->getDisplayName()));

            return $this->redirectToRoute('app_listing_show', ['id' => $listing->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('listing/show.html.twig', [
            'listing' => $listing,
            'contact' => $form,
        ]);
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
        $isNew = $listing->getId() === null;
        $form  = $this->createForm(ListingFormType::class, $listing);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $publish = !$form->get('saveDraft')->isClicked();
            $editor->save(
                $listing,
                $publish,
                array_filter($form->get('photos')->getData() ?? []),
                $form->has('removePhotos') ? $form->get('removePhotos')->getData() : [],
            );

            $this->addFlash('success', match (true) {
                !$publish => 'Saved as a draft. Publish it when you’re ready.',
                $isNew    => 'Your listing is live.',
                default   => 'Listing updated.',
            });

            return $this->redirectToRoute(
                $publish ? 'app_listing_show' : 'app_account_listings',
                $publish ? ['id' => $listing->getId()] : [],
                Response::HTTP_SEE_OTHER,
            );
        }

        return $this->render($isNew ? 'listing/new.html.twig' : 'listing/edit.html.twig', [
            'listing' => $listing,
            'form'    => $form,
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

    /**
     * @return FormInterface<Message>
     */
    private function contactForm(Listing $listing, ?User $user): FormInterface
    {
        $message = new Message();
        if ($user !== null) {
            $message->setSenderName($user->getDisplayName())->setSenderEmail($user->getEmail());
        }

        return $this->createForm(ContactSellerType::class, $message, [
            'action'       => $this->generateUrl('app_listing_contact', ['id' => $listing->getId()]),
            'captcha'      => !$user?->isVerified(),
            'known_sender' => $user !== null,
        ]);
    }
}
