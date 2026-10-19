<?php

namespace App\Entity;

use App\Repository\PaperRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A University of Otago paper (course), e.g. CHEM191. Textbooks are tagged
 * with the papers they're for. The list is seeded from Otago's catalogue
 * (app:papers:import); codes people type that aren't on it are added too.
 */
#[ORM\Entity(repositoryClass: PaperRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_PAPER_CODE', fields: ['code'])]
#[UniqueEntity(fields: ['code'])]
class Paper extends Base
{
    /** Four letters for the subject and three digits, as Otago writes them. */
    public const string CODE_PATTERN = '/^[A-Z]{4}[0-9]{3}$/';

    #[ORM\Column(length: 7)]
    #[Assert\NotBlank]
    #[Assert\Regex(self::CODE_PATTERN, message: 'Paper codes look like CHEM191: four letters, then three numbers.')]
    private ?string $code = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    /** True for papers from Otago's catalogue, false for ones people typed in. */
    #[ORM\Column(options: ['default' => false])]
    private bool $official = false;

    public function __construct(?string $code = null, ?string $title = null, bool $official = false)
    {
        parent::__construct();
        if ($code !== null) {
            $this->setCode($code);
        }
        $this->title    = $title;
        $this->official = $official;
    }

    /** "chem 191" → "CHEM191"; null if it isn't a paper code at all. */
    public static function normalise(string $code): ?string
    {
        $code = strtoupper((string) preg_replace('/[\s\-_.]+/', '', $code));

        return preg_match(self::CODE_PATTERN, $code) ? $code : null;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = self::normalise($code) ?? strtoupper(trim($code));

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

    public function isOfficial(): bool
    {
        return $this->official;
    }

    public function setOfficial(bool $official): static
    {
        $this->official = $official;

        return $this;
    }

    public function getUnique(): string
    {
        return (string) $this->code;
    }
}
