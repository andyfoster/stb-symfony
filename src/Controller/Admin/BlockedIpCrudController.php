<?php

namespace App\Controller\Admin;

use App\Entity\BlockedIp;
use App\Security\IpBlocklist;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * IP addresses and ranges that can't send forms (IpBlocklist). "Block
 * addresses" takes a pasted list; deleting a row unblocks it.
 *
 * @extends AbstractCrudController<BlockedIp>
 */
final class BlockedIpCrudController extends AbstractCrudController
{
    public function __construct(private readonly IpBlocklist $blocklist) {}

    public static function getEntityFqcn(): string
    {
        return BlockedIp::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Blocked IP')
            ->setEntityLabelInPlural('Blocked IPs')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['address', 'note'])
            ->setPaginatorPageSize(100)
            ->setHelp(Crud::PAGE_INDEX, 'These addresses can still browse, but can’t sign up, log in or send any form. Delete one to unblock it.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $block = Action::new('block', 'Block addresses', 'fa fa-ban')
            ->linkToCrudAction('block')
            ->createAsGlobalAction()
            ->addCssClass('btn btn-primary');

        return $actions
            ->disable(Action::NEW, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $block);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('address', 'Address or range')->hideOnForm();
        yield TextField::new('note');
        yield DateTimeField::new('createdAt', 'Blocked')->hideOnForm();
    }

    #[AdminRoute('/block', name: 'block')]
    public function block(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $form = $this->createFormBuilder()
            ->add('addresses', TextareaType::class, [
                'label'       => 'Addresses',
                'help'        => 'One per line (or separated by commas or spaces). Single addresses like 203.0.113.7, or ranges like 203.0.113.0/24. Anything after # is ignored.',
                'attr'        => ['rows' => 12],
                'constraints' => [new Assert\NotBlank(message: 'Paste at least one address.')],
            ])
            ->add('note', TextType::class, [
                'required' => false,
                'help'     => 'Why, for later. Saved on each address.',
                'attr'     => ['maxlength' => 255],
            ])
            ->getForm()
            ->handleRequest($request);

        $result = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $note   = trim((string) $form->get('note')->getData());
            $result = $this->blocklist->addFromText((string) $form->get('addresses')->getData(), $note === '' ? null : mb_substr($note, 0, 255));
            if ($result['rejected'] === []) {
                $this->addFlash('success', \sprintf('Blocked %d %s%s.', \count($result['added']), \count($result['added']) === 1 ? 'address' : 'addresses', $result['skipped'] === [] ? '' : \sprintf(' (%d already blocked)', \count($result['skipped']))));

                return $this->redirectToRoute('admin_blocked_ip_index');
            }
        }

        return $this->render('admin/blocked_ip_block.html.twig', ['form' => $form, 'result' => $result]);
    }
}
