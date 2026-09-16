<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/up', name: 'app_health')]
    public function __invoke(): Response
    {
        return new Response('OK');
    }
}
