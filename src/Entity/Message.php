<?php

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An enquiry sent to a seller through a listing. Emailed to them (Reply-To the
 * sender, when they gave an email) and kept for their inbox. Senders don't need
 * an account.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Index(columns: ['recipient_id', 'read_at'])]
class Message extends Base
{
    /** Null once the listing is deleted; the message stays in the inbox. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Listing $listing = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $recipient = null;

    /** Set when the sender was logged in. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $sender = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private ?string $senderName = null;

    /** The sender gives an email, a phone number or both, so the seller can reply. */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[Assert\Expression('value or this.getSenderPhone()', message: 'Add an email address or a phone number so the seller can reply.')]
    private ?string $senderEmail = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    #[Assert\Regex('/^\+?[0-9 ()-]{6,}$/', message: 'Enter a phone number using digits, spaces and an optional leading +.')]
    private ?string $senderPhone = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 5000)]
    private ?string $body = null;

    /** Kept to help admins spot and block spam. */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $senderIp = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function getListing(): ?Listing
    {
        return $this->listing;
    }

    public function setListing(?Listing $listing): static
    {
        $this->listing = $listing;

        return $this;
    }

    public function getRecipient(): ?User
    {
        return $this->recipient;
    }

    public function setRecipient(User $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function getSender(): ?User
    {
        return $this->sender;
    }

    public function setSender(?User $sender): static
    {
        $this->sender = $sender;

        return $this;
    }

    public function getSenderName(): ?string
    {
        return $this->senderName;
    }

    public function setSenderName(?string $senderName): static
    {
        $this->senderName = $senderName === null ? null : trim($senderName);

        return $this;
    }

    public function getSenderEmail(): ?string
    {
        return $this->senderEmail;
    }

    public function setSenderEmail(?string $senderEmail): static
    {
        $senderEmail       = $senderEmail === null ? null : mb_strtolower(trim($senderEmail));
        $this->senderEmail = $senderEmail === '' ? null : $senderEmail;

        return $this;
    }

    public function getSenderPhone(): ?string
    {
        return $this->senderPhone;
    }

    public function setSenderPhone(?string $senderPhone): static
    {
        $senderPhone       = $senderPhone === null ? null : trim($senderPhone);
        $this->senderPhone = $senderPhone === '' ? null : $senderPhone;

        return $this;
    }

    /** The phone number for tel: and sms: links, without spaces or brackets. */
    public function getSenderPhoneDigits(): ?string
    {
        return $this->senderPhone === null ? null : preg_replace('/[^0-9+]/', '', $this->senderPhone);
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body === null ? null : trim($body);

        return $this;
    }

    public function getSenderIp(): ?string
    {
        return $this->senderIp;
    }

    public function setSenderIp(?string $senderIp): static
    {
        $this->senderIp = $senderIp;

        return $this;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function setReadAt(?\DateTimeImmutable $readAt): static
    {
        $this->readAt = $readAt;

        return $this;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function markRead(): static
    {
        $this->readAt ??= new \DateTimeImmutable();

        return $this;
    }
}
