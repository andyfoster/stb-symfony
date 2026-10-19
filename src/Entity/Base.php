<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Shared identity and timestamps for every entity. Mapped superclass, so each
 * subclass keeps its own table with these columns in it.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class Base
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column]
    protected ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    protected ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->getUnique();
    }

    /**
     * Short label for logs and admin screens, e.g. "Listing 12".
     */
    public function getUnique(): string
    {
        $name = $this->getEntityType();

        if ($this->id !== null) {
            $name .= ' ' . $this->id;
        }

        return $name;
    }

    /**
     * Short class name, e.g. "Listing". Not getType(): Listing already uses
     * that for its ListingType.
     */
    public function getEntityType(): string
    {
        return new \ReflectionClass($this)->getShortName();
    }
}
