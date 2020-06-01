<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusHomepagePlugin\Form\Type;

use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Sylius\Bundle\ResourceBundle\Form\Type\ResourceTranslationsType;
use Symfony\Component\Form\FormBuilderInterface;

class HomepageType extends AbstractResourceType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('channels', ChannelChoiceType::class, [
                'label' => 'monsieurbiz_homepage.ui.form.channels',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
            ])
            ->add('translations', ResourceTranslationsType::class, [
                'entry_type' => HomepageTranslationType::class,
            ])
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'monsieurbiz_homepage_homepage';
    }
}
