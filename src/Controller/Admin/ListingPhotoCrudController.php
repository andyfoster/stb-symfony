<?php

namespace App\Controller\Admin;

use App\Entity\ListingPhoto;
use App\Photo\GeneratePhotoSizes;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Every listing photo, newest first, to look through and clean up. Deleting
 * one removes its files too (App\Photo\RemovePhotoFiles). "Regenerate sizes"
 * queues GeneratePhotoSizes for the worker.
 *
 * @extends AbstractCrudController<ListingPhoto>
 */
final class ListingPhotoCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokens,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ListingPhoto::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Photo')
            ->setEntityLabelInPlural('Photos')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['listing.title', 'filename'])
            ->setPaginatorPageSize(50)
            ->setHelp(Crud::PAGE_INDEX, 'Photos of unverified sellers, drafts and expired listings are hidden on the site. Deleting a photo removes its files too.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $regenerate = Action::new('regenerate', 'Regenerate sizes', 'fa fa-rotate')
            // EasyAdmin doesn't put a CSRF token on custom actions, so it travels in the URL.
            ->linkToRoute('admin_listing_photo_regenerate', fn (ListingPhoto $photo): array => [
                'id' => $photo->getId(),
                'token' => $this->csrfTokens->getToken('regenerate-photo'.$photo->getId())->getValue(),
            ])
            ->renderAsForm();

        $viewListing = Action::new('viewListing', 'View listing', 'fa fa-eye')
            ->linkToRoute('app_listing_show', static fn (ListingPhoto $photo): array => ['id' => $photo->getListing()?->getId()]);

        $regenerateSelected = Action::new('regenerateSelected', 'Regenerate sizes', 'fa fa-rotate')
            ->linkToCrudAction('regenerateSelected');

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $viewListing)
            ->add(Crud::PAGE_DETAIL, $viewListing)
            ->add(Crud::PAGE_INDEX, $regenerate)
            ->add(Crud::PAGE_DETAIL, $regenerate)
            ->addBatchAction($regenerateSelected);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('listing')->add('createdAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield Field::new('filename', 'Photo')->setTemplatePath('admin/field/photo.html.twig')->setSortable(false);
        yield AssociationField::new('listing')->formatValue(static fn ($value, ListingPhoto $photo): string => (string) $photo->getListing()?->getTitle());
        yield TextField::new('listing.seller.email', 'Seller')->setSortable(false);
        yield BooleanField::new('public', 'Shown on the site')->renderAsSwitch(false)->setSortable(false);
        yield IntegerField::new('position')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Uploaded');
    }

    #[AdminRoute('/{id}/regenerate', name: 'regenerate', options: ['methods' => ['POST']])]
    public function regenerate(ListingPhoto $photo, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('regenerate-photo'.$photo->getId(), $request->query->getString('token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->bus->dispatch(new GeneratePhotoSizes((int) $photo->getId()));
        $this->addFlash('success', 'The photo’s sizes are being remade. It takes a few seconds.');

        return $this->redirectToRoute('admin_listing_photo_detail', ['entityId' => $photo->getId()]);
    }

    #[AdminRoute('/regenerate-selected', name: 'regenerate_selected', options: ['methods' => ['POST']])]
    public function regenerateSelected(BatchActionDto $batch): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('ea-batch-action-regenerateSelected-'.ListingPhoto::class, $batch->getCsrfToken())) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        foreach ($batch->getEntityIds() as $id) {
            $this->bus->dispatch(new GeneratePhotoSizes((int) $id));
        }
        $this->addFlash('success', \sprintf('Remaking the sizes of %d photos.', \count($batch->getEntityIds())));

        return $this->redirectToRoute('admin_listing_photo_index');
    }
}
