<?php

namespace App\Twig;

use App\Entity\User;
use App\Entity\Listing;
use App\Repository\MessageRepository;
use App\Search\ListingFilter;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

final class AppExtension
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly Security $security,
    ) {
    }

    /** For the "Messages" badge in the header. */
    #[AsTwigFunction('unread_message_count')]
    public function unreadMessageCount(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->messages->countUnread($user) : 0;
    }

    /** What the home page's search matches a listing against (its data-search attribute). */
    #[AsTwigFunction('listing_search_text')]
    public function listingSearchText(Listing $listing): string
    {
        return ListingFilter::searchText($listing);
    }
}
