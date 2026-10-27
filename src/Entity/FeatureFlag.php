<?php

namespace App\Entity;

use App\Enum\Feature;
use App\Repository\FeatureFlagRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Whether one Feature is on. Read them through FeatureFlags. The name is a
 * plain string rather than the enum, so a row left behind by a removed
 * Feature is ignored instead of breaking hydration.
 */
#[ORM\Entity(repositoryClass: FeatureFlagRepository::class)]
class FeatureFlag extends Base
{
    #[ORM\Column(length: 50, unique: true)]
    private string $name;

    #[ORM\Column]
    private bool $enabled;

    public function __construct(Feature $feature, ?bool $enabled = null)
    {
        parent::__construct();
        $this->name    = $feature->value;
        $this->enabled = $enabled ?? $feature->default();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFeature(): ?Feature
    {
        return Feature::tryFrom($this->name);
    }

    public function getLabel(): string
    {
        return $this->getFeature()?->label() ?? $this->name;
    }

    public function getDescription(): string
    {
        return $this->getFeature()?->description() ?? '';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getUnique(): string
    {
        return $this->getLabel();
    }
}
