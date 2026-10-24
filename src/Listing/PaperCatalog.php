<?php

namespace App\Listing;

use App\Entity\Paper;
use App\Repository\PaperRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds papers by code, adding ones that aren't known yet (persisted, not
 * flushed), so the same code always maps to one Paper.
 */
class PaperCatalog
{
    /** @var array<string, Paper> new papers not flushed yet, by code */
    private array $pending = [];

    public function __construct(
        private readonly PaperRepository $papers,
        private readonly EntityManagerInterface $manager,
    ) {}

    /**
     * @throws \InvalidArgumentException when it isn't a paper code
     */
    public function get(string $code): Paper
    {
        $normalised = Paper::normalise($code)
            ?? throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a paper code like CHEM191.', $code));

        if (isset($this->pending[$normalised]) && $this->manager->contains($this->pending[$normalised])) {
            return $this->pending[$normalised];
        }

        $paper = $this->papers->findOneBy(['code' => $normalised]);
        if ($paper === null) {
            $paper = new Paper($normalised);
            $this->manager->persist($paper);
            $this->pending[$normalised] = $paper;
        }

        return $paper;
    }

    /**
     * Imports a file of "CODE; Title" lines, or a CSV of "code,title" rows. The
     * first line picks the separator, and a header row is optional.
     *
     * @throws \RuntimeException when the file can't be read
     * @return array{added: int, updated: int, skipped: int}
     *
     */
    public function importCsv(string $file): array
    {
        $handle = is_readable($file) ? fopen($file, 'r') : false;
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Can’t read %s.', $file));
        }

        try {
            $separator = str_contains((string) fgets($handle), ';') ? ';' : ',';
            rewind($handle);
            $rows = [];
            while (false !== $row = fgetcsv($handle, separator: $separator, escape: '')) {
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        if ($rows !== [] && Paper::normalise((string) $rows[0][0]) === null) {
            array_shift($rows); // header
        }

        return $this->import($rows);
    }

    /**
     * Adds or updates official papers from rows of [code, title].
     *
     * @param iterable<array{0: string, 1?: string|null}> $rows
     *
     * @return array{added: int, updated: int, skipped: int}
     */
    public function import(iterable $rows): array
    {
        $counts = ['added' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            $code = Paper::normalise((string) ($row[0] ?? ''));
            if ($code === null) {
                ++$counts['skipped'];
                continue;
            }

            $title = trim((string) ($row[1] ?? '')) ?: null;
            $paper = $this->papers->findOneBy(['code' => $code]) ?? $this->pending[$code] ?? null;
            if ($paper === null) {
                $this->manager->persist($this->pending[$code] = new Paper($code, $title, official: true));
                ++$counts['added'];
            } else {
                $paper->setOfficial(true)->setTitle($title ?? $paper->getTitle());
                ++$counts['updated'];
            }
        }
        $this->manager->flush();
        $this->pending = [];

        return $counts;
    }
}
