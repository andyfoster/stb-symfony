<?php

namespace App\Twig;

use App\Entity\ListingPhoto;
use App\Photo\PhotoStorage;
use App\Entity\User;
use App\Repository\MessageRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

final class AppExtension
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly Security $security,
        private readonly PhotoStorage $photos,
    ) {}

    /** For the "Messages" badge in the header. */
    #[AsTwigFunction('unread_message_count')]
    public function unreadMessageCount(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->messages->countUnread($user) : 0;
    }

    /**
     * Sizes not made yet, for the Photos admin.
     *
     * @return list<string>
     */
    #[AsTwigFunction('photo_missing_sizes')]
    public function photoMissingSizes(ListingPhoto $photo): array
    {
        return $this->photos->missingSizes($photo);
    }
}
