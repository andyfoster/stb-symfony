<?php

namespace App\Messaging;

use App\Audit\AuditLog;
use App\Enum\AuditType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Delivers messages from the contact form on /about: rate-limited and emailed
 * to CONTACT_EMAIL with Reply-To set to the sender.
 */
class AdminContact
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Target('contact_admin.limiter')]
        private readonly RateLimiterFactoryInterface $limiter,
        private readonly AuditLog $audit,
        #[Autowire('%env(CONTACT_EMAIL)%')]
        private readonly string $recipient,
    ) {}

    /**
     * Uses up one message from the sender's allowance; false when they've sent too many.
     */
    public function allow(Request $request, ContactMessage $message): bool
    {
        if ($this->limiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return true;
        }

        $this->audit->record(AuditType::ContactThrottled, email: $message->email);

        return false;
    }

    public function send(ContactMessage $message): void
    {
        $this->audit->record(AuditType::ContactSent, context: ['messageBody' => $message->body], email: $message->email);

        $this->mailer->send((new TemplatedEmail())
            ->to($this->recipient)
            ->replyTo(new Address((string) $message->email, (string) $message->name))
            ->subject(\sprintf('Contact form: %s', $message->name))
            ->htmlTemplate('emails/contact_admin.html.twig')
            ->context(['message' => $message]));
    }
}
