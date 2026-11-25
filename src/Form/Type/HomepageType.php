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

use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageInterface;
use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Sylius\Bundle\ResourceBundle\Form\Type\ResourceTranslationsType;
use Sylius\Component\Channel\Model\ChannelInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

class HomepageType extends AbstractResourceType
{
    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('translations', ResourceTranslationsType::class, [
            'entry_type' => HomepageTranslationType::class,
        ]);

        if (method_exists($options['data_class'], 'isEnabled') && method_exists($options['data_class'], 'setEnabled')) {
            $builder->add('enabled', CheckboxType::class, [
                'label' => 'monsieurbiz_homepage.ui.form.enabled',
                'help' => 'monsieurbiz_homepage.ui.form.enabled_pending',
                'required' => false,
            ]);
        }

        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $page = $event->getData();
            $assigned = $page instanceof HomepageInterface && null !== $page->getId() ? $page->getChannels() : [];
            $locked = [];
            foreach ($assigned as $channel) {
                $locked[] = $channel->getCode();
            }

            $event->getForm()->add('channels', ChannelChoiceType::class, [
                'label' => 'monsieurbiz_homepage.ui.form.channels',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choice_attr' => static fn (ChannelInterface $channel): array => in_array($channel->getCode(), $locked, true) ? ['disabled' => true] : [],
            ]);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            $page = $event->getForm()->getData();
            if (!is_array($data) || !$page instanceof HomepageInterface || null === $page->getId()) {
                return;
            }
            $channels = $data['channels'] ?? [];
            if (!is_array($channels) || $page->getChannels()->isEmpty()) {
                return;
            }
            // Disabled checkboxes are omitted by the browser; active assignments are never editable here.
            foreach ($page->getChannels() as $channel) {
                if (!in_array($channel->getCode(), $channels, true)) {
                    $channels[] = $channel->getCode();
                }
            }
            $data['channels'] = $channels;
            $event->setData($data);
        });
    }

    /**
     * @inheritdoc
     */
    public function getBlockPrefix(): string
    {
        return 'monsieurbiz_homepage_homepage';
    }
}
