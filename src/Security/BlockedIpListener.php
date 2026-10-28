<?php

namespace App\Security;

use App\Audit\AuditLog;
use App\Enum\AuditType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Twig\Environment;

/**
 * Blocked IP addresses (IpBlocklist) can still browse, but every form they
 * send (sign-up, login, listings, messages) gets a 403 page instead. Runs
 * before the firewall so login attempts are turned away too.
 */
final class BlockedIpListener
{
    public function __construct(
        private readonly IpBlocklist $blocklist,
        private readonly AuditLog $audit,
        private readonly Environment $twig,
        #[Autowire('%env(CONTACT_EMAIL)%')]
        private readonly string $contactEmail,
    ) {}

    #[AsEventListener(priority: 16)]
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe() || !$this->blocklist->isBlocked($request->getClientIp())) {
            return;
        }

        $this->audit->record(AuditType::BlockedRequest, context: ['path' => $request->getPathInfo()]);
        $event->setResponse(new Response(
            $this->twig->render('security/blocked.html.twig', ['contact_email' => $this->contactEmail]),
            Response::HTTP_FORBIDDEN,
        ));
    }
}
