<?php

namespace App\Form;

use App\Captcha\TurnstileType;
use App\Messaging\ContactMessage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The contact form on /about, which emails the site admins. Guests and
 * unverified users also get a Turnstile check.
 */
class ContactAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Your name',
                'attr'  => ['autocomplete' => 'name', 'maxlength' => 80],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Your email',
                'attr'  => ['autocomplete' => 'email', 'maxlength' => 180],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Message',
                'attr'  => ['rows' => 6, 'maxlength' => 5000],
            ])
            ->add(ContactSellerType::HONEYPOT, TextType::class, [
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
            'data_class' => ContactMessage::class,
            'captcha'    => true,
        ]);
        $resolver->setAllowedTypes('captcha', 'bool');
    }
}
