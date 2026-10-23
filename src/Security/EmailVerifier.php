<?php

namespace App\Security;

use App\Audit\AuditLog;
use App\Entity\User;
use App\Enum\AuditType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Sends and checks "confirm your email" links. The link carries the user id,
 * so it works even if it's opened on a device where they aren't signed in.
 */
class EmailVerifier
{
    public function __construct(
        private VerifyEmailHelperInterface $helper,
        private MailerInterface $mailer,
        private EntityManagerInterface $entityManager,
        private AuditLog $audit,
    ) {}

    public function sendConfirmation(User $user): void
    {
        $signature = $this->helper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),
            (string) $user->getEmail(),
            ['id' => $user->getId()],
        );

        $this->mailer->send((new TemplatedEmail())
            ->to(new Address((string) $user->getEmail(), (string) $user->getDisplayName()))
            ->subject('Confirm your ScarfieTextbooks email')
            ->htmlTemplate('registration/confirmation_email.html.twig')
            ->context([
                'user'                 => $user,
                'signedUrl'            => $signature->getSignedUrl(),
                'expiresAtMessageKey'  => $signature->getExpirationMessageKey(),
                'expiresAtMessageData' => $signature->getExpirationMessageData(),
            ]));
    }

    /**
     * @throws VerifyEmailExceptionInterface when the link is invalid or expired
     */
    public function confirm(Request $request, User $user): void
    {
        $this->helper->validateEmailConfirmationFromRequest($request, (string) $user->getId(), (string) $user->getEmail());

        $user->setVerified(true);
        $this->entityManager->flush();
        $this->audit->record(AuditType::EmailVerified, $user, actor: $user);
    }
}
