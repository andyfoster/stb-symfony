<?php

namespace App\Entity;

use App\Enum\AuditType;
use App\Repository\AuditEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing that happened on the site, for admins: who did it (or tried to),
 * to what, from where. Written by App\Audit\AuditLog and never edited. Never
 * holds passwords or message bodies. Pruned after AuditLog::RETENTION_DAYS.
 */
#[ORM\Entity(repositoryClass: AuditEventRepository::class)]
#[ORM\Index(columns: ['created_at'])]
#[ORM\Index(columns: ['type', 'created_at'])]
#[ORM\Index(columns: ['actor_id', 'created_at'])]
#[ORM\Index(columns: ['subject_type', 'subject_id'])]
class AuditEvent extends Base
{
    #[ORM\Column(length: 40, enumType: AuditType::class)]
    private AuditType $type;

    /**
     * The account that acted, or that a failed login or reset request was for.
     * Null for guests, and once the account is deleted (email is kept).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $actor = null;

    /** The actor's email at the time, or the one typed into a failed login. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    /** What it happened to, e.g. "Listing" 12 "Chemistry: The Central Science". */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $subjectType = null;

    #[ORM\Column(nullable: true)]
    private ?int $subjectId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subjectLabel = null;

    /** Null when it happened outside a web request (console, worker). */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $context = [];

    public function __construct(AuditType $type)
    {
        parent::__construct();
        $this->type = $type;
    }

    public function getType(): AuditType
    {
        return $this->type;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function setActor(?User $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $email = $email === null ? '' : mb_strtolower(trim($email));
        $this->email = $email === '' ? null : mb_substr($email, 0, 180);

        return $this;
    }

    public function getSubjectType(): ?string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?int
    {
        return $this->subjectId;
    }

    public function getSubjectLabel(): ?string
    {
        return $this->subjectLabel;
    }

    public function setSubject(?string $type, ?int $id, ?string $label = null): static
    {
        $this->subjectType = $type;
        $this->subjectId = $id;
        $this->subjectLabel = $label === null ? null : mb_substr($label, 0, 255);

        return $this;
    }

    /** "Listing 12: Chemistry: The Central Science", for lists and logs. */
    public function getSubject(): ?string
    {
        if ($this->subjectType === null) {
            return null;
        }

        $name = trim($this->subjectType . ' ' . $this->subjectId);

        return $this->subjectLabel === null ? $name : $name . ': ' . $this->subjectLabel;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent === null || $userAgent === '' ? null : mb_substr($userAgent, 0, 255);

        return $this;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    /** @param array<string, mixed> $context */
    public function setContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }
}
