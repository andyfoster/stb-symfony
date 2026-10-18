<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\MessageRepository;
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
}
