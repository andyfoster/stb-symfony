<?php

namespace App\Entity;

use App\Enum\Category;
use App\Enum\Condition;
use App\Enum\ListingStatus;
use App\Enum\ListingType;
use App\Repository\ListingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Something a student is selling, or something they're looking for.
 */
#[ORM\Entity(repositoryClass: ListingRepository::class)]
#[ORM\Index(columns: ['type', 'category'])]
#[ORM\Index(columns: ['status', 'expires_at'])]
class Listing extends Base
{
    /** Refreshing opens up this many days before a listing expires. */
    public const int REFRESH_WINDOW_DAYS = 7;

    /** Null only for listings imported from before accounts existed. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?User $seller = null;

    #[ORM\Column(length: 20, enumType: ListingStatus::class)]
    private ListingStatus $status = ListingStatus::Draft;

    /** When it was last published or refreshed; the home page sorts by this. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(length: 20, enumType: ListingType::class)]
    #[Assert\NotNull]
    private ?ListingType $type = null;

    #[ORM\Column(length: 30, enumType: Category::class)]
    #[Assert\NotNull]
    private ?Category $category = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Asking price for sale listings, budget for wanted ones; null means "make an offer". */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $priceCents = null;

    // "condition" is reserved in some SQL dialects.
    #[ORM\Column(name: 'item_condition', length: 20, nullable: true, enumType: Condition::class)]
    private ?Condition $condition = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $location = null;

    #[ORM\OneToOne(cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private ?BookDetails $book = null;

    public function getSeller(): ?User
    {
        return $this->seller;
    }

    public function setSeller(?User $seller): static
    {
        $this->seller = $seller;

        return $this;
    }

    public function getStatus(): ListingStatus
    {
        return $this->status;
    }

    public function setStatus(ListingStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isDraft(): bool
    {
        return ListingStatus::Draft === $this->status;
    }

    public function isSold(): bool
    {
        return ListingStatus::Sold === $this->status;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return ListingStatus::Active === $this->status
            && null !== $this->expiresAt
            && $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /** Active and not expired: what everyone can see and search. */
    public function isLive(?\DateTimeImmutable $now = null): bool
    {
        return ListingStatus::Active === $this->status && !$this->isExpired($now);
    }

    /**
     * Unsold listings can be refreshed once they're close to expiring (or have
     * expired), so people can't bump themselves to the top every day.
     */
    public function canRefresh(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return ListingStatus::Active === $this->status
            && null !== $this->expiresAt
            && $this->expiresAt <= $now->modify(\sprintf('+%d days', self::REFRESH_WINDOW_DAYS));
    }

    /** Puts a draft live (or re-lists a sold one) for $days days. */
    public function publish(int $days, ?\DateTimeImmutable $now = null): static
    {
        $now ??= new \DateTimeImmutable();
        $this->status = ListingStatus::Active;
        $this->publishedAt = $now;
        $this->expiresAt = $now->modify(\sprintf('+%d days', $days));

        return $this;
    }

    /** Another $days days from now, and back to the top of the list. */
    public function refresh(int $days, ?\DateTimeImmutable $now = null): static
    {
        if (!$this->canRefresh($now)) {
            throw new \LogicException(\sprintf('%s can\'t be refreshed yet.', $this));
        }

        return $this->publish($days, $now);
    }

    public function markSold(): static
    {
        $this->status = ListingStatus::Sold;

        return $this;
    }

    public function getType(): ?ListingType
    {
        return $this->type;
    }

    public function setType(?ListingType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPriceCents(): ?int
    {
        return $this->priceCents;
    }

    public function setPriceCents(?int $priceCents): static
    {
        $this->priceCents = $priceCents;

        return $this;
    }

    public function getCondition(): ?Condition
    {
        return $this->condition;
    }

    public function setCondition(?Condition $condition): static
    {
        $this->condition = $condition;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getBook(): ?BookDetails
    {
        return $this->book;
    }

    public function setBook(?BookDetails $book): static
    {
        $this->book = $book;

        return $this;
    }
}
