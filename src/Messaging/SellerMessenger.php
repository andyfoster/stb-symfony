<?php

namespace App\Messaging;

use App\Audit\AuditLog;
use App\Entity\Listing;
use App\Entity\Message;
use App\Enum\AuditType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Delivers "contact the seller" messages: rate-limited, saved for the
 * seller's inbox, and emailed to them with Reply-To set to the sender.
 */
class SellerMessenger
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly MailerInterface $mailer,
        #[Target('contact_seller.limiter')]
        private readonly RateLimiterFactoryInterface $perIpLimiter,
        #[Target('contact_seller_listing.limiter')]
        private readonly RateLimiterFactoryInterface $perListingLimiter,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Uses up one message from the sender's allowance; false when they've sent too many.
     */
    public function allow(Request $request, Listing $listing, Message $message): bool
    {
        $perIp      = $this->perIpLimiter->create((string) $request->getClientIp())->consume();
        $perListing = $this->perListingLimiter->create($listing->getId() . '|' . $message->getSenderEmail())->consume();

        if ($perIp->isAccepted() && $perListing->isAccepted()) {
            return true;
        }

        $this->audit->record(AuditType::MessageThrottled, $listing, email: $message->getSenderEmail());

        return false;
    }

    public function send(Request $request, Listing $listing, Message $message): void
    {
        $seller = $listing->getSeller() ?? throw new \LogicException(\sprintf('%s has no seller to contact.', $listing));

        $message
            ->setListing($listing)
            ->setRecipient($seller)
            ->setSenderIp($request->getClientIp());
        $this->manager->persist($message);
        $this->manager->flush();
        // Who wrote to whom, never what they wrote.
        $this->audit->record(AuditType::MessageSent, $listing, ['message' => $message->getId(), 'seller' => $seller->getId()], $message->getSender(), $message->getSenderEmail());

        $this->mailer->send((new TemplatedEmail())
            ->to(new Address((string) $seller->getEmail(), (string) $seller->getDisplayName()))
            ->replyTo(new Address((string) $message->getSenderEmail(), (string) $message->getSenderName()))
            ->subject(\sprintf('%s asked about “%s”', $message->getSenderName(), $listing->getTitle()))
            ->htmlTemplate('emails/contact_seller.html.twig')
            ->context(['listing' => $listing, 'message' => $message, 'seller' => $seller]));
    }
}
