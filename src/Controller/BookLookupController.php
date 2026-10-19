<?php

namespace App\Controller;

use App\Book\BookLookup;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class BookLookupController extends AbstractController
{
    /**
     * Used by the isbn-lookup Stimulus controller on the listing form.
     */
    #[Route('/sell/lookup-isbn', name: 'app_listing_isbn_lookup', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function lookup(
        #[MapQueryParameter]
        string $isbn,
        BookLookup $books,
        #[CurrentUser]
        User $user,
        #[Target('isbn_lookup.limiter')]
        RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        if (!$limiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many lookups. Wait a minute and try again.'], 429);
        }

        if (BookLookup::normalise($isbn) === null) {
            return $this->json(['error' => 'That doesn’t look like an ISBN.'], 400);
        }

        $book = $books->find($isbn);
        if ($book === null) {
            return $this->json(['error' => 'We couldn’t find that ISBN. Fill the details in yourself.'], 404);
        }

        return $this->json([
            'isbn'  => $book->isbn,
            'title' => $book->title,
            // BookDetails::$author holds up to 255 characters.
            'author'  => mb_strimwidth(implode(', ', $book->authors), 0, 255, '…'),
            'edition' => $book->edition,
            'year'    => $book->publishedYear,
            'source'  => $book->source,
        ]);
    }
}
