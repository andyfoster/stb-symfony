<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AboutController extends AbstractController
{
    #[Route('/about', name: 'app_about', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('about/index.html.twig');
    }
}
