<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label'      => 'Display name',
                'empty_data' => '',
                'attr'       => ['autocomplete' => 'nickname', 'maxlength' => 80],
            ])
            ->add('phone', TelType::class, [
                'label'    => 'Cellphone',
                'required' => false,
                'attr'     => ['autocomplete' => 'tel', 'placeholder' => '021 123 4567'],
            ])
            ->add('showPhone', CheckboxType::class, [
                'label'    => 'Show my number on my listings',
                'required' => false,
                'help'     => 'People can always message you through the site. Your email address is never shown.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
