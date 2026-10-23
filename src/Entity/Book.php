<?php

namespace App\Entity;

use App\Repository\BookRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

use function Symfony\Component\String\u;

/**
 * A textbook that has been listed, once per book however many times or ways
 * it was typed in: the history behind title suggestions, which admins can tidy
 * under Books in /admin. Built from the old site's listings by app:legacy:import.
 */
#[ORM\Entity(repositoryClass: BookRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_BOOK_MATCH_KEY', fields: ['matchKey'])]
class Book extends Base
{
    /**
     * titleKey() and the most common of its listings' surnames(), e.g.
     * "campbell biology|reece": how imports find this book again.
     */
    #[ORM\Column(length: 255)]
    private ?string $matchKey = null;

    /** @var list<string> match keys of Books merged into this one */
    #[ORM\Column]
    private array $aliases = [];

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $author = '';

    /** Editions seen, e.g. "4th, 6th". */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $editions = null;

    /**
     * @var Collection<int, Paper>
     */
    #[ORM\ManyToMany(targetEntity: Paper::class)]
    #[ORM\OrderBy(['code' => \SortDirection::Ascending])]
    private Collection $papers;

    #[ORM\Column(options: ['default' => 0])]
    private int $timesListed = 0;

    /** Listings that sold, or (on the old site) that the seller took down. */
    #[ORM\Column(options: ['default' => 0])]
    private int $timesSold = 0;

    /** Middle asking price over its listings, in cents; null when none had a price. */
    #[ORM\Column(nullable: true)]
    private ?int $medianPriceCents = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $firstListedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastListedAt = null;

    public function __construct(string $matchKey = '')
    {
        parent::__construct();
        $this->matchKey = mb_substr($matchKey, 0, 255);
        $this->papers   = new ArrayCollection();
    }

    /** Words in an author field that aren't anyone's surname. */
    private const array NOT_SURNAMES = ['and', 'the', 'with', 'eds', 'edited', 'editor', 'editors', 'others', 'etal', 'various', 'assorted', 'authors', 'author', 'prof', 'education', 'publishing', 'press', 'university'];

    /**
     * A title as people type it, folded so spellings of one book match:
     * "The Campbell Biology, 9th ed. (BIOL112)" and "Campbell biology: global
     * edition 2013" are both "campbell biology". No punctuation, accents,
     * "and", leading article, editions, years or paper codes.
     */
    public static function titleKey(string $title): string
    {
        $edition = '(\d+(st|nd|rd|th|e)?|first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth)';
        $title   = self::fold($title);
        $title   = (string) preg_replace([
            '/\b[a-z]{4} ?\d{3}\b/',                                                         // paper codes
            '/\b' . $edition . '( (global|international|custom|revised|updated|australian|nz))* (edition|ed|edn)\b/',
            '/\b((global|international|custom|revised|updated) )?(edition|ed|edn)( \d+)?\b/',
            '/\b\d+(st|nd|rd|th|e)?\b/',                                                     // years, "9e"
            '/^ *(the|a|an) /',
            '/ and /',
        ], ' ', ' ' . $title . ' ');

        return trim((string) preg_replace('/ +/', ' ', $title));
    }

    /**
     * Words in an author field that may be surnames ("N. A. Campbell & Reece"
     * gives campbell and reece). Two listings with the same titleKey() are the
     * same book when they share one, so author order and first names don't matter.
     *
     * @return list<string>
     */
    public static function surnames(string $author): array
    {
        preg_match_all('/\b[a-z]{3,}\b/', self::fold($author), $words);

        return array_values(array_unique(array_diff($words[0], self::NOT_SURNAMES)));
    }

    /** Whether two title and author spellings are the same book; a missing author matches any. */
    public static function sameBook(string $title, string $author, string $otherTitle, string $otherAuthor): bool
    {
        if (self::titleKey($title) !== self::titleKey($otherTitle)) {
            return false;
        }
        $surnames      = self::surnames($author);
        $otherSurnames = self::surnames($otherAuthor);

        return $surnames === [] || $otherSurnames === [] || array_intersect($surnames, $otherSurnames) !== [];
    }

    private static function fold(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', u($text)->ascii()->lower()->toString()));
    }

    /**
     * Takes in another Book that turned out to be this one: its listings,
     * papers and editions, and its match keys so imports find this one.
     */
    public function merge(self $other): static
    {
        if ($other->timesListed > $this->timesListed) {
            $this->medianPriceCents = $other->medianPriceCents ?? $this->medianPriceCents;
        } else {
            $this->medianPriceCents ??= $other->medianPriceCents;
        }
        $this->timesListed += $other->timesListed;
        $this->timesSold += $other->timesSold;
        $this->firstListedAt = min(array_filter([$this->firstListedAt, $other->firstListedAt])) ?: null;
        $this->lastListedAt  = max(array_filter([$this->lastListedAt, $other->lastListedAt])) ?: null;
        $this->aliases       = array_values(array_unique(array_diff([...$this->aliases, (string) $other->matchKey, ...$other->aliases], [$this->matchKey])));

        $editions = array_unique(array_filter([...explode(', ', (string) $this->editions), ...explode(', ', (string) $other->editions)]));
        natsort($editions);
        $this->editions = mb_substr(implode(', ', $editions), 0, 255) ?: null;
        foreach ($other->papers as $paper) {
            $this->addPaper($paper);
        }

        return $this;
    }

    /**
     * Match keys of Books merged into this one.
     *
     * @return list<string>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getMatchKey(): ?string
    {
        return $this->matchKey;
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

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): static
    {
        $this->author = (string) $author;

        return $this;
    }

    public function getEditions(): ?string
    {
        return $this->editions;
    }

    public function setEditions(?string $editions): static
    {
        $this->editions = $editions;

        return $this;
    }

    /**
     * @return Collection<int, Paper>
     */
    public function getPapers(): Collection
    {
        return $this->papers;
    }

    public function addPaper(Paper $paper): static
    {
        if (!$this->papers->contains($paper)) {
            $this->papers->add($paper);
        }

        return $this;
    }

    public function removePaper(Paper $paper): static
    {
        $this->papers->removeElement($paper);

        return $this;
    }

    public function getTimesListed(): int
    {
        return $this->timesListed;
    }

    public function setTimesListed(int $timesListed): static
    {
        $this->timesListed = $timesListed;

        return $this;
    }

    public function getTimesSold(): int
    {
        return $this->timesSold;
    }

    public function setTimesSold(int $timesSold): static
    {
        $this->timesSold = $timesSold;

        return $this;
    }

    public function getMedianPriceCents(): ?int
    {
        return $this->medianPriceCents;
    }

    public function setMedianPriceCents(?int $medianPriceCents): static
    {
        $this->medianPriceCents = $medianPriceCents;

        return $this;
    }

    public function getFirstListedAt(): ?\DateTimeImmutable
    {
        return $this->firstListedAt;
    }

    public function setFirstListedAt(?\DateTimeImmutable $firstListedAt): static
    {
        $this->firstListedAt = $firstListedAt;

        return $this;
    }

    public function getLastListedAt(): ?\DateTimeImmutable
    {
        return $this->lastListedAt;
    }

    public function setLastListedAt(?\DateTimeImmutable $lastListedAt): static
    {
        $this->lastListedAt = $lastListedAt;

        return $this;
    }

    public function getUnique(): string
    {
        return (string) $this->title;
    }
}
