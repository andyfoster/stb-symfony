<?php

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An enquiry sent to a seller through a listing. Emailed to them (Reply-To the
 * sender) and kept for their inbox. Senders don't need an account.
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

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $senderEmail = null;

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
        $this->senderEmail = $senderEmail === null ? null : mb_strtolower(trim($senderEmail));

        return $this;
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
