<?php

namespace App\Captcha;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Cloudflare Turnstile widget. The turnstile Stimulus controller renders it
 * and has it post its token under this field's name.
 */
class TurnstileType extends AbstractType
{
    public function __construct(
        #[Autowire('%env(TURNSTILE_SITE_KEY)%')]
        private readonly string $siteKey,
    ) {}

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['site_key'] = $this->siteKey;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'mapped'         => false,
            'label'          => false,
            'compound'       => false,
            'constraints'    => [new Turnstile()],
            'error_bubbling' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'turnstile';
    }
}
