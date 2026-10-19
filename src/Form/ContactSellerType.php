<?php

namespace App\Form;

use App\Captcha\TurnstileType;
use App\Entity\Message;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Contact the seller" on a listing. Anyone can send one; guests and
 * unverified users also get a Turnstile check.
 */
class ContactSellerType extends AbstractType
{
    /** Hidden field people never see; bots fill it in. */
    public const string HONEYPOT = 'website';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Logged-in senders always send as themselves: disabled fields keep the account's details.
        $known = $options['known_sender'];

        $builder
            ->add('senderName', TextType::class, [
                'label'    => 'Your name',
                'disabled' => $known,
                'attr'     => ['autocomplete' => 'name', 'maxlength' => 80],
            ])
            ->add('senderEmail', EmailType::class, [
                'label'    => 'Your email',
                'help'     => 'The seller replies to this address.',
                'disabled' => $known,
                'attr'     => ['autocomplete' => 'email', 'maxlength' => 180],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Message',
                'attr'  => ['rows' => 5, 'maxlength' => 5000, 'placeholder' => 'Hi, is this still available?'],
            ])
            ->add(self::HONEYPOT, TextType::class, [
                'label'    => 'Leave this empty',
                'mapped'   => false,
                'required' => false,
                'attr'     => ['autocomplete' => 'off', 'tabindex' => '-1'],
                'row_attr' => ['class' => 'honeypot', 'aria-hidden' => 'true'],
            ]);

        if ($options['captcha']) {
            $builder->add('captcha', TurnstileType::class);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'   => Message::class,
            'captcha'      => true,
            'known_sender' => false,
        ]);
        $resolver->setAllowedTypes('captcha', 'bool');
        $resolver->setAllowedTypes('known_sender', 'bool');
    }
}
