<?php

/*
 * This file is part of Monsieur Biz' Homepage plugin for Sylius.
 *
 * (c) Monsieur Biz <sylius@monsieurbiz.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MonsieurBiz\SyliusHomepagePlugin\Form\Type;

use MonsieurBiz\SyliusRichEditorPlugin\Form\Type\RichEditorType;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class HomepageTranslationType extends AbstractResourceType
{
    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('content', RichEditorType::class, [
                'label' => 'monsieurbiz_homepage.ui.form.content',
                'locale' => $builder->getName(),
            ])
            ->add('name', TextType::class, [
                'label' => 'monsieurbiz_homepage.ui.form.name',
            ])
            ->add('metaTitle', TextType::class, [
                'required' => false,
                'label' => 'monsieurbiz_homepage.ui.form.meta_title',
            ])
            ->add('metaDescription', TextType::class, [
                'required' => false,
                'label' => 'monsieurbiz_homepage.ui.form.meta_description',
            ])
            ->add('metaKeywords', TextType::class, [
                'required' => false,
                'label' => 'monsieurbiz_homepage.ui.form.meta_keywords',
            ])
        ;
    }

    /**
     * @inheritdoc
     */
    public function getBlockPrefix(): string
    {
        return 'monsieurbiz_homepage_homepage_translation';
    }
}
