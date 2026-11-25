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

use DateTimeImmutable;
use DateTimeZone;
use MonsieurBiz\SyliusHomepagePlugin\Exception\HomepageSwitchRejected;
use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

final class HomepageSwitchType extends AbstractType
{
    public const LOCAL_FORMAT = 'Y-m-d\TH:i';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ('update' !== $options['mode']) {
            $builder->add('channel', ChannelChoiceType::class, [
                'label' => 'monsieurbiz_homepage.ui.switch.form.channel',
                'constraints' => [new NotNull()],
            ]);
        }

        if ('activate_now' !== $options['mode']) {
            $builder->add('scheduledAt', TextType::class, [
                'label' => 'monsieurbiz_homepage.ui.switch.form.scheduled_at',
            ]);
        }
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        if (isset($view->children['scheduledAt'])) {
            $view->children['scheduledAt']->vars['type'] = 'datetime-local';
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('mode');
        $resolver->setAllowedValues('mode', ['create', 'activate_now', 'update']);
        $resolver->setDefault('csrf_protection', true);
    }

    public static function parseLocalTime(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value)) {
            throw new HomepageSwitchRejected('invalid_local_time');
        }

        $wallClock = DateTimeImmutable::createFromFormat('!' . self::LOCAL_FORMAT, $value, new DateTimeZone('UTC'));
        if (false === $wallClock || $wallClock->format(self::LOCAL_FORMAT) !== $value) {
            throw new HomepageSwitchRejected('invalid_local_time');
        }

        // Try the offsets on either side of any nearby transition, without normalizing the wall time.
        $timestamp = $wallClock->getTimestamp();
        $offsets = [$timezone->getOffset($wallClock)];
        foreach ($timezone->getTransitions($timestamp - 172800, $timestamp + 172800) ?: [] as $transition) {
            $offsets[] = $transition['offset'];
        }

        $matches = [];
        foreach (array_unique($offsets) as $offset) {
            $candidate = $wallClock->setTimestamp($timestamp - $offset);
            if ($candidate->setTimezone($timezone)->format(self::LOCAL_FORMAT) === $value) {
                $matches[] = $candidate;
            }
        }
        if (1 !== count($matches)) {
            throw new HomepageSwitchRejected('invalid_local_time');
        }

        return $matches[0];
    }
}
