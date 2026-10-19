<?php

namespace App\Controller;

use App\Entity\Paper;
use App\Repository\PaperRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class PaperController extends AbstractController
{
    /**
     * Suggestions for the paper-tags Stimulus controller on the listing form.
     */
    #[Route('/papers/search', name: 'app_paper_search', methods: ['GET'])]
    public function search(PaperRepository $papers, #[MapQueryParameter] string $q = ''): JsonResponse
    {
        $response = $this->json(array_map(static fn(Paper $paper): array => [
            'code'  => $paper->getCode(),
            'title' => $paper->getTitle(),
        ], $papers->search($q)));

        // Papers rarely change; let browsers reuse suggestions for a while.
        return $response->setPublic()->setMaxAge(600);
    }
}
