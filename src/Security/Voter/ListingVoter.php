<?php

namespace App\Security\Voter;

use App\Entity\Listing;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Anyone can view a live listing. Sellers and admins can also view their
 * drafts, sold and expired listings, and change them.
 *
 * @extends Voter<string, Listing>
 */
final class ListingVoter extends Voter
{
    public const string VIEW = 'LISTING_VIEW';
    public const string EDIT = 'LISTING_EDIT';

    public function __construct(private readonly AccessDecisionManagerInterface $decisions) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof Listing;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($attribute === self::VIEW && $subject->isPublic()) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $subject->getSeller()?->getId() === $user->getId() || $this->decisions->decide($token, ['ROLE_ADMIN']);
    }
}
