<?php

namespace App\Twig;

use App\Entity\ListingPhoto;
use App\Enum\Feature;
use App\Feature\FeatureFlags;
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
        private readonly FeatureFlags $features,
    ) {}

    /** Whether a Feature is on, by its value: feature_enabled('google_sign_in'). */
    #[AsTwigFunction('feature_enabled')]
    public function featureEnabled(string $feature): bool
    {
        return $this->features->isEnabled(Feature::from($feature));
    }

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
