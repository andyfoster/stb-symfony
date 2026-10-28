<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Signs a suspended account out on its next request, wherever it's logged in.
 * Runs after the firewall (priority 8) has loaded the user.
 */
final class SuspendedUserListener
{
    public function __construct(private readonly Security $security) {}

    #[AsEventListener(priority: 0)]
    public function __invoke(RequestEvent $event): void
    {
        $user = $this->security->getUser();
        if (!$event->isMainRequest() || !$user instanceof User || !$user->isSuspended()) {
            return;
        }
        $response = $this->security->logout(false);
        if (null !== $response) {
            $event->setResponse($response);
        }
    }
}
