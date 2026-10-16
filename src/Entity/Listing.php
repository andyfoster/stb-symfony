<?php

namespace App\Entity;

use App\Enum\Category;
use App\Enum\Condition;
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
class Listing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

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

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?ListingType
    {
        return $this->type;
    }

    public function setType(ListingType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
