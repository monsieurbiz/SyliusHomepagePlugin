<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusHomepagePlugin\Form\Type;

use MonsieurBiz\SyliusRichEditorPlugin\Form\Type\RichEditorType;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class HomepageTranslationType extends AbstractResourceType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('content', RichEditorType::class, [
                'label' => 'monsieurbiz_homepage.ui.form.content'
            ])
            ->add('name', TextType::class, [
                'label' => 'monsieurbiz_homepage.ui.form.name',
//                'constraints' => [
//                    new Assert\NotBlank([])
//                ],
            ])
            ->add('metaTitle', TextType::class, [
                'label' => 'monsieurbiz_homepage.ui.form.meta_title',
//                'constraints' => [
//                    new Assert\NotBlank([])
//                ],
            ])
            ->add('metaDescription', TextType::class, [
                'label' => 'monsieurbiz_homepage.ui.form.meta_description',
//                'constraints' => [
//                    new Assert\NotBlank([])
//                ],
            ])
            ->add('metaKeywords', TextType::class, [
                'label' => 'monsieurbiz_homepage.ui.form.meta_keywords',
//                'constraints' => [
//                    new Assert\NotBlank([])
//                ],
            ])
        ;
    }
    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'monsieurbiz_homepage_homepage_translation';
    }
}
