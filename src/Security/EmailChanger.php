<?php

namespace App\Security;

use App\Audit\AuditLog;
use App\Entity\User;
use App\Enum\AuditType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Moves an account to a new email address. The new address has to be confirmed
 * like a new sign-up, and the old one is told, in case it wasn't them.
 */
class EmailChanger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailVerifier $emailVerifier,
        private readonly MailerInterface $mailer,
        private readonly AuditLog $audit,
    ) {}

    public function change(User $user, string $email): void
    {
        $previous = (string) $user->getEmail();
        $user->setEmail($email);
        $user->setVerified(false);
        $this->entityManager->flush();
        $this->audit->record(AuditType::EmailChanged, $user, ['from' => $previous, 'to' => $user->getEmail()], actor: $user);

        $this->emailVerifier->sendConfirmation($user);
        $this->mailer->send((new TemplatedEmail())
            ->to(new Address($previous, (string) $user->getDisplayName()))
            ->subject('Your ScarfieTextbooks email has changed')
            ->htmlTemplate('emails/email_changed.html.twig')
            ->context(['user' => $user, 'previous' => $previous]));
    }
}
