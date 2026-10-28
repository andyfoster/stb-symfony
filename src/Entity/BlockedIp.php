<?php

namespace App\Entity;

use App\Repository\BlockedIpRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An IP address or range (CIDR, like 203.0.113.0/24) that can't sign up, log
 * in or send any form. Add them with IpBlocklist, which checks them.
 */
#[ORM\Entity(repositoryClass: BlockedIpRepository::class)]
class BlockedIp extends Base
{
    #[ORM\Column(length: 50, unique: true)]
    private string $address;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note;

    public function __construct(string $address, ?string $note = null)
    {
        parent::__construct();
        $this->address = $address;
        $this->note    = $note;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getUnique(): string
    {
        return $this->address;
    }
}
