<?php

namespace App\Feature;

use App\Entity\FeatureFlag;
use App\Enum\Feature;
use App\Repository\FeatureFlagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which Features are on: the FeatureFlag rows admins switch in /admin, or
 * each Feature's default() until its row exists. Read once per request.
 */
class FeatureFlags implements ResetInterface
{
    /** @var array<string, bool>|null */
    private ?array $enabled = null;

    public function __construct(
        private readonly FeatureFlagRepository $repository,
        private readonly EntityManagerInterface $manager,
    ) {}

    public function isEnabled(Feature $feature): bool
    {
        if (null === $this->enabled) {
            $this->enabled = [];
            foreach ($this->repository->findAll() as $flag) {
                $this->enabled[$flag->getName()] = $flag->isEnabled();
            }
        }

        return $this->enabled[$feature->value] ?? $feature->default();
    }

    public function set(Feature $feature, bool $enabled): void
    {
        $this->sync();
        $this->repository->findOneBy(['name' => $feature->value])?->setEnabled($enabled);
        $this->manager->flush();
        $this->reset();
    }

    /** Adds a row, at its default, for every Feature that doesn't have one yet. */
    public function sync(): void
    {
        $existing = array_map(static fn (FeatureFlag $flag): string => $flag->getName(), $this->repository->findAll());
        $added    = false;
        foreach (Feature::cases() as $feature) {
            if (!\in_array($feature->value, $existing, true)) {
                $this->manager->persist(new FeatureFlag($feature));
                $added = true;
            }
        }
        if ($added) {
            $this->manager->flush();
            $this->reset();
        }
    }

    public function reset(): void
    {
        $this->enabled = null;
    }
}
