<?php

namespace App\Controller;

use App\Form\ListingFilterType;
use App\Repository\ListingRepository;
use App\Search\ListingFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
}
