<?php

namespace App\Controller;

use App\Entity\ListingPhoto;
use App\Photo\PhotoStorage;
use App\Security\Voter\ListingVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class PhotoController extends AbstractController
{
    /**
     * Photos are public only once the seller has confirmed their email; until
     * then just the seller (and admins) can see them.
     */
    #[Route('/photos/{id}/{size}', name: 'app_photo', requirements: ['id' => Requirement::DIGITS, 'size' => 'large|thumb'], methods: ['GET'])]
    public function show(ListingPhoto $photo, string $size, Request $request, PhotoStorage $storage): BinaryFileResponse
    {
        $listing = $photo->getListing();
        $public  = $listing->arePhotosPublic() && $listing->isLive();
        if (!$public && !$this->isGranted(ListingVoter::EDIT, $listing)) {
            throw $this->createNotFoundException('Photo not found.');
        }

        $path = $storage->path((string) $photo->getFilename(), $size);
        if (!is_file($path)) {
            throw $this->createNotFoundException('Photo not found.');
        }

        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'image/webp'], autoEtag: true, autoLastModified: true);
        // Files never change (a new upload gets a new id), but visibility can.
        $public ? $response->setPublic()->setMaxAge(86400) : $response->setPrivate()->setMaxAge(0);
        $response->isNotModified($request);

        return $response;
    }
}
