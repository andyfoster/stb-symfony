<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * New password, typed twice. Used from a reset-password link.
 */
class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type'            => PasswordType::class,
            'options'         => ['attr' => ['autocomplete' => 'new-password']],
            'first_options'   => ['label' => 'New password', 'help' => 'At least 8 characters.'],
            'second_options'  => ['label' => 'Repeat password'],
            'invalid_message' => 'The passwords don’t match.',
            'mapped'          => false,
            'constraints'     => [
                new Assert\NotBlank(message: 'Choose a password.'),
                new Assert\Length(min: 8, max: 4096, minMessage: 'Use at least {{ limit }} characters.'),
            ],
        ]);
    }
}
