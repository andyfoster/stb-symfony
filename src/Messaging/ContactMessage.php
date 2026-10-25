<?php

namespace App\Messaging;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A message to the site admins from the contact form on /about.
 */
class ContactMessage
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Please give an email address so we can reply.')]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 5000)]
    public ?string $body = null;
}
