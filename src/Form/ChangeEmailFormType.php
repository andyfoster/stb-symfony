<?php

namespace App\Form;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A new email address for the signed-in `user`, confirmed with their current password,
 * or with Google when `confirm_with_google` (accounts without a password).
 * Not mapped, so a rejected address never touches the logged-in User.
 */
class ChangeEmailFormType extends AbstractType
{
    public function __construct(private readonly UserRepository $users) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $user */
        $user = $options['user'];

        $builder
            ->add('email', EmailType::class, [
                'label'       => 'New email',
                'attr'        => ['autocomplete' => 'email'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Enter your new email address.'),
                    new Assert\Email(),
                    new Assert\Length(max: 180),
                    new Assert\Callback(function (?string $email, ExecutionContextInterface $context) use ($user): void {
                        if ($email === null) {
                            return;
                        }
                        if (mb_strtolower(trim($email)) === $user->getEmail()) {
                            $context->addViolation('That’s already your email.');
                        } elseif ($this->users->findOneByEmail($email) !== null) {
                            $context->addViolation('There is already an account with this email.');
                        }
                    }),
                ],
            ]);

        // Accounts that only sign in with Google prove it's them there instead (GoogleController).
        if (!$options['confirm_with_google']) {
            $builder->add('currentPassword', PasswordType::class, [
                'label'       => 'Your password',
                'attr'        => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Enter your password.'),
                    new UserPassword(message: 'That isn’t your password.'),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('user');
        $resolver->setAllowedTypes('user', User::class);
        $resolver->setDefault('confirm_with_google', false);
        $resolver->setAllowedTypes('confirm_with_google', 'bool');
    }
}
