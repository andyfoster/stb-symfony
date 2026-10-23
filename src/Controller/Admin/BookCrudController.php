<?php

namespace App\Controller\Admin;

use App\Audit\AuditLog;
use App\Entity\Book;
use App\Entity\Paper;
use App\Enum\AuditType;
use App\Repository\BookRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every textbook listed (on the old site so far), once per book, most listed
 * first. Fixing a title or author changes what the listing form suggests; the
 * counts come from app:legacy:import, so they aren't editable. "Merge" joins
 * books that are really one (Book::merge()); imports then add to the one kept.
 *
 * @extends AbstractCrudController<Book>
 */
final class BookCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly EntityManagerInterface $manager,
        private readonly AuditLog $audit,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Book::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Book')
            ->setEntityLabelInPlural('Books')
            ->setDefaultSort(['timesListed' => 'DESC', 'title' => 'ASC'])
            ->setSearchFields(['title', 'author', 'editions', 'papers.code'])
            ->setPaginatorPageSize(50)
            ->setHelp(Crud::PAGE_INDEX, 'Tick books that are the same one and choose Merge: the most listed keeps its title and author.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $merge = Action::new('mergeSelected', 'Merge', 'fa fa-object-group')->linkToCrudAction('mergeSelected');

        // Books come from listings, not from here.
        return $actions->disable(Action::NEW)->add(Crud::PAGE_INDEX, Action::DETAIL)->addBatchAction($merge);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('papers')->add('timesListed')->add('timesSold')->add('lastListedAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title');
        yield TextField::new('author')->setRequired(false);
        yield TextField::new('editions')->setHelp('Editions seen, e.g. "4th, 6th".');
        yield AssociationField::new('papers')->setSortable(false)->autocomplete()
            ->formatValue(static fn ($value, Book $book): string => implode(', ', $book->getPapers()->map(static fn (Paper $paper): string => (string) $paper->getCode())->toArray()));
        yield IntegerField::new('timesListed', 'Listed')->hideOnForm();
        yield IntegerField::new('timesSold', 'Sold or taken down')->hideOnForm();
        yield MoneyField::new('medianPriceCents', 'Typical price')->setCurrency('NZD')->setStoredAsCents()->hideOnForm();
        yield DateTimeField::new('firstListedAt', 'First listed')->setFormat('d MMM yyyy')->hideOnForm();
        yield DateTimeField::new('lastListedAt', 'Last listed')->setFormat('d MMM yyyy')->hideOnForm();
        yield TextField::new('matchKey', 'Matched on')->onlyOnDetail()->setHelp('Listings whose title and author fold to this belong to this book.');
    }

    #[AdminRoute('/merge-selected', name: 'merge_selected', options: ['methods' => ['POST']])]
    public function mergeSelected(BatchActionDto $batch): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('ea-batch-action-mergeSelected-' . Book::class, $batch->getCsrfToken())) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $books = $this->books->findBy(['id' => $batch->getEntityIds()], ['timesListed' => 'DESC', 'id' => 'ASC']);
        if (\count($books) < 2) {
            $this->addFlash('danger', 'Tick at least two books to merge.');

            return $this->redirectToRoute('admin_book_index');
        }

        $kept = array_shift($books);
        $merged = array_map(static fn (Book $book): ?int => $book->getId(), $books);
        foreach ($books as $book) {
            $kept->merge($book);
            $this->manager->remove($book);
        }
        $this->manager->flush();
        $this->audit->record(AuditType::AdminUpdated, $kept, ['merged' => $merged]);
        $this->addFlash('success', \sprintf('Merged %d books into “%s”.', \count($books) + 1, $kept->getTitle()));

        return $this->redirectToRoute('admin_book_detail', ['entityId' => $kept->getId()]);
    }
}
