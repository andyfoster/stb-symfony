<?php

namespace App\Listing;

use App\Entity\Listing;
use App\Repository\ListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Emails sellers about listings that expire soon, one email per seller, so
 * they can refresh them. Each listing is only mentioned once per publish.
 */
class ExpiryReminder
{
    public function __construct(
        private readonly ListingRepository $listings,
        private readonly EntityManagerInterface $manager,
        private readonly MailerInterface $mailer,
    ) {}

    /**
     * @return int how many sellers were emailed
     */
    public function send(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        /** @var array<int, Listing[]> $bySeller */
        $bySeller = [];
        foreach ($this->listings->findNeedingExpiryReminder(Listing::EXPIRY_REMINDER_DAYS, $now) as $listing) {
            $bySeller[$listing->getSeller()->getId()][] = $listing;
        }

        foreach ($bySeller as $listings) {
            $seller = $listings[0]->getSeller();
            $this->mailer->send((new TemplatedEmail())
                ->to(new Address((string) $seller->getEmail(), (string) $seller->getDisplayName()))
                ->subject(\count($listings) === 1
                    ? \sprintf('“%s” expires soon', $listings[0]->getTitle())
                    : \sprintf('%d of your listings expire soon', \count($listings)))
                ->htmlTemplate('emails/expiry_reminder.html.twig')
                ->context(['seller' => $seller, 'listings' => $listings]));

            foreach ($listings as $listing) {
                $listing->setExpiryReminderSentAt($now);
            }
            // Flush per seller, so a mailer failure doesn't re-send to earlier sellers.
            $this->manager->flush();
        }

        return \count($bySeller);
    }
}
