<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Book-only information for a listing. Kept out of Listing so other kinds of
 * goods don't carry book fields.
 */
#[ORM\Entity]
class BookDetails extends Base
{
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $author = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $edition = null;

    #[ORM\Column(length: 17, nullable: true)]
    #[Assert\Isbn]
    private ?string $isbn = null;

    /**
     * Otago papers the book is for, e.g. CHEM191 and CHEM192.
     *
     * @var Collection<int, Paper>
     */
    #[ORM\ManyToMany(targetEntity: Paper::class, cascade: ['persist'])]
    #[ORM\OrderBy(['code' => \SortDirection::Ascending])]
    #[Assert\Count(max: 8, maxMessage: 'Add up to {{ limit }} papers.')]
    #[Assert\Valid]
    private Collection $papers;

    public function __construct()
    {
        parent::__construct();
        $this->papers = new ArrayCollection();
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getEdition(): ?string
    {
        return $this->edition;
    }

    public function setEdition(?string $edition): static
    {
        $this->edition = $edition;

        return $this;
    }

    public function getIsbn(): ?string
    {
        return $this->isbn;
    }

    public function setIsbn(?string $isbn): static
    {
        $this->isbn = $isbn;

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
}
