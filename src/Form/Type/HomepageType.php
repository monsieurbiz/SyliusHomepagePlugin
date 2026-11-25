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

use Doctrine\Common\Collections\Collection;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageInterface;
use MonsieurBiz\SyliusHomepagePlugin\Repository\HomepageRepository;
use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Sylius\Bundle\ResourceBundle\Form\Type\ResourceTranslationsType;
use Sylius\Component\Channel\Model\ChannelInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class HomepageType extends AbstractResourceType
{
    public const SWITCH_NOW_INTENT = '_monsieurbiz_homepage_switch_now_intent';

    public function __construct(
        string $dataClass,
        array $validationGroups = [],
        private ?HomepageRepository $homepageRepository = null,
        private ?RequestStack $requestStack = null,
        private ?TranslatorInterface $translator = null
    ) {
        parent::__construct($dataClass, $validationGroups);
    }

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

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $page = $event->getData();
            $creating = $page instanceof HomepageInterface && null === $page->getId();
            $supportsSwitchNow = $creating && '' !== $event->getForm()->getName() && null !== $this->homepageRepository && null !== $this->requestStack;
            $assigned = $page instanceof HomepageInterface && !$creating ? $page->getChannels() : [];
            $locked = [];
            foreach ($assigned as $channel) {
                $locked[] = $channel->getCode();
            }
            $occupied = $supportsSwitchNow ? array_flip($this->homepageRepository?->findAssignedChannelIds() ?? []) : [];

            $event->getForm()->add('channels', ChannelChoiceType::class, [
                'label' => 'monsieurbiz_homepage.ui.form.channels',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choice_attr' => static function (ChannelInterface $channel) use ($locked, $occupied): array {
                    if (in_array($channel->getCode(), $locked, true)) {
                        return ['disabled' => true];
                    }

                    return isset($occupied[$channel->getId()]) ? ['data-homepage-channel-occupied' => '1'] : [];
                },
            ]);
            if ($supportsSwitchNow) {
                $event->getForm()->add('switchNow', ChannelChoiceType::class, [
                    'label' => 'monsieurbiz_homepage.ui.form.switch_now',
                    'mapped' => false,
                    'required' => false,
                    'multiple' => true,
                    'expanded' => true,
                    'error_bubbling' => true,
                    'choice_filter' => static fn (ChannelInterface $channel): bool => isset($occupied[$channel->getId()]),
                ]);
            }
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

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $request = $this->requestStack?->getCurrentRequest();
            $name = $form->getName();
            if (!$request?->isMethod('POST')
                || 'monsieurbiz_homepage_admin_homepage_create' !== $request->attributes->get('_route')
                || '' === $name || !$request->request->has($name)
                || !$form->isRoot() || !$form->has('switchNow') || !$form->isValid()
                || null === $this->homepageRepository
            ) {
                return;
            }

            $page = $form->getData();
            $switches = $form->get('switchNow')->getData();
            if (!$page instanceof HomepageInterface || !$switches instanceof Collection) {
                return;
            }
            $config = $form->getConfig();
            $protected = $config->getOption('csrf_protection', false) && $config->hasAttribute('csrf_token_id');
            /** @var Collection<array-key, ChannelInterface> $switches */
            $switchIds = $switches->map(static fn (ChannelInterface $channel): int => (int) $channel->getId())->getValues();
            $error = !$protected && [] !== $switchIds
                ? 'monsieurbiz_homepage.switch.assigned_channel'
                : $this->homepageRepository->findAssignmentError($page, $switchIds);
            if (null !== $error) {
                $form->addError(new FormError($this->translator?->trans($error, [], 'flashes') ?? $error));
            } elseif ($protected && [] !== $switchIds) {
                $request->attributes->set(self::SWITCH_NOW_INTENT, [$page, $switches]);
            }
        }, -2048);
    }

    /**
     * @inheritdoc
     */
    public function getBlockPrefix(): string
    {
        return 'monsieurbiz_homepage_homepage';
    }
}
