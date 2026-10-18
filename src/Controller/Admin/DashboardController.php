<?php

namespace App\Controller\Admin;

use App\Settings\SiteSettingsProvider;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly SiteSettingsProvider $settings)
    {
    }

    public function index(): Response
    {
        return $this->redirectToRoute('admin_listing_index');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('ScarfieTextbooks admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkTo(ListingCrudController::class, 'Listings', 'fa fa-book');
        yield MenuItem::linkTo(MessageCrudController::class, 'Messages', 'fa fa-envelope');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-user');
        yield MenuItem::linkToRoute('Settings', 'fa fa-gear', 'admin_site_settings_edit', ['entityId' => $this->settings->get()->getId()]);
        yield MenuItem::section();
        yield MenuItem::linkToRoute('Back to the site', 'fa fa-arrow-left', 'app_listings');
    }
}
