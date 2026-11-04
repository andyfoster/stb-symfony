<?php

namespace App\Form;

use App\Captcha\TurnstileType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Forgot your password?": the email to send a reset link to. Turnstile checks
 * it unless the flag is off.
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

        if ($options['captcha']) {
            $builder->add('captcha', TurnstileType::class);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['captcha' => true]);
        $resolver->setAllowedTypes('captcha', 'bool');
    }
}
