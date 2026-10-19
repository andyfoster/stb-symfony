<?php

namespace App\Form;

use App\Entity\Paper;
use App\Listing\PaperCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Otago papers as a comma-separated text field ("CHEM191, CHEM192"), which
 * the paper-tags Stimulus controller turns into tags with suggestions.
 * Codes that aren't in the list yet are added to it.
 */
class PaperTagsType extends AbstractType
{
    public function __construct(private readonly PaperCatalog $catalog)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?iterable $papers): string => implode(', ', array_map(
                static fn (Paper $paper): string => (string) $paper->getCode(),
                null === $papers ? [] : [...$papers],
            )),
            function (?string $text): array {
                $papers = [];
                foreach (preg_split('/[,;&\/\n]+|\s+and\s+/i', (string) $text) ?: [] as $code) {
                    if ('' === trim($code)) {
                        continue;
                    }
                    try {
                        $paper = $this->catalog->get($code);
                    } catch (\InvalidArgumentException) {
                        throw new TransformationFailedException(invalidMessage: '"{{ code }}" isn’t a paper code. Use codes like CHEM191: four letters, then three numbers.', invalidMessageParameters: ['{{ code }}' => trim($code)]);
                    }
                    $papers[$paper->getCode()] = $paper;
                }

                return array_values($papers);
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'by_reference' => false,
            'empty_data' => '',
        ]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'paper_tags';
    }
}
