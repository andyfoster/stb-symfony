<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * New password, typed twice. Used from a reset-password link, and on the
 * Account page, where `current_password` asks for the old one first.
 */
class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['current_password']) {
            $builder->add('currentPassword', PasswordType::class, [
                'label'       => 'Current password',
                'mapped'      => false,
                'attr'        => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Enter your current password.'),
                    new UserPassword(message: 'That isn’t your current password.'),
                ],
            ]);
        }

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

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('current_password', false);
        $resolver->setAllowedTypes('current_password', 'bool');
    }
}
