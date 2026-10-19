<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Forgot your password?": the email to send a reset link to.
 */
class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label'       => 'Email',
            'attr'        => ['autocomplete' => 'email'],
            'constraints' => [
                new Assert\NotBlank(message: 'Enter your email.'),
                new Assert\Email(),
            ],
        ]);
    }
}
