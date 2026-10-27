<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\Feature;
use App\Feature\FeatureFlags;
use App\Form\ContactAdminType;
use App\Form\ContactSellerType;
use App\Messaging\AdminContact;
use App\Messaging\ContactMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AboutController extends AbstractController
{
    #[Route('/about', name: 'app_about', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, AdminContact $contact, FeatureFlags $features, #[CurrentUser] ?User $user): Response
    {
        $message = new ContactMessage();
        $message->name  = $user?->getDisplayName();
        $message->email = $user?->getEmail();

        $form = $this->createForm(ContactAdminType::class, $message, [
            'action'  => $this->generateUrl('app_about').'#contact',
            'captcha' => !$user?->isVerified() && $features->isEnabled(Feature::ContactCaptcha),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Bots fill in the hidden field: act as if it worked and send nothing.
            if (trim((string) $form->get(ContactSellerType::HONEYPOT)->getData()) === '') {
                if (!$contact->allow($request, $message)) {
                    $this->addFlash('error', 'You’ve sent a lot of messages recently. Please try again later.');

                    return $this->redirectToRoute('app_about', status: Response::HTTP_SEE_OTHER);
                }
                $contact->send($message);
            }

            $this->addFlash('success', 'Thanks, your message has been sent. We’ll reply by email.');

            return $this->redirectToRoute('app_about', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('about/index.html.twig', ['contact' => $form]);
    }
}
