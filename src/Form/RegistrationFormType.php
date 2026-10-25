<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sign-up with email and password, typed twice. The password is hashed by the controller,
 * so it isn't mapped onto the User.
 */
class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Your name',
                'help'  => 'Shown to other students on your listings.',
                'attr'  => ['autocomplete' => 'name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr'  => ['autocomplete' => 'email'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'options'         => ['attr' => ['autocomplete' => 'new-password']],
                'first_options'   => ['label' => 'Password', 'help' => 'At least 8 characters.'],
                'second_options'  => ['label' => 'Confirm password'],
                'invalid_message' => 'The passwords don’t match.',
                'mapped'          => false,
                'constraints'     => [
                    new Assert\NotBlank(message: 'Choose a password.'),
                    // 4096 is Symfony's maximum for hashing.
                    new Assert\Length(min: 8, max: 4096, minMessage: 'Use at least {{ limit }} characters.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
