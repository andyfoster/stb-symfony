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
        $public  = $listing->arePhotosPublic() && $listing->isPublic();
        if (!$public && !$this->isGranted(ListingVoter::EDIT, $listing)) {
            throw $this->createNotFoundException('Photo not found.');
        }

        try {
            // Normally made in the background after upload; made now if it isn't there yet.
            $path = $storage->ensure($photo, $size);
        } catch (\RuntimeException) {
            throw $this->createNotFoundException('Photo not found.');
        }

        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'image/webp'], autoEtag: true, autoLastModified: true);
        // Visibility can change, and sizes can be remade (the ETag covers that).
        $public ? $response->setPublic()->setMaxAge(86400) : $response->setPrivate()->setMaxAge(0);
        $response->isNotModified($request);

        return $response;
    }
}
