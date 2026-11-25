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

namespace MonsieurBiz\SyliusHomepagePlugin\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageInterface;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageSwitch;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Channel\Model\ChannelInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class HomepageWriteGuard
{
    public function __construct(
        private EntityManagerInterface $homepageManager,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    #[AsEventListener(event: 'monsieurbiz_homepage.homepage.pre_create')]
    public function validateCreation(ResourceControllerEvent $event): void
    {
        $this->validateAssignments($event);
        if ($event->isStopped()) {
            // The configured update redirect needs an ID, which the rejected page does not have.
            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate('monsieurbiz_homepage_admin_homepage_create'),
                Response::HTTP_SEE_OTHER
            ));
        }
    }

    #[AsEventListener(event: 'monsieurbiz_homepage.homepage.pre_update')]
    public function validateAssignments(ResourceControllerEvent $event): void
    {
        /** @var HomepageInterface $page */
        $page = $event->getSubject();
        $connection = $this->homepageManager->getConnection();
        $desired = $page->getChannels()->map(static fn (ChannelInterface $channel): int => (int) $channel->getId())->toArray();
        $current = null === $page->getId() ? [] : $connection->fetchFirstColumn(
            'SELECT channel_id FROM monsieurbiz_homepage_homepage_channels WHERE page_id = ?',
            [$page->getId()]
        );

        if ([] !== array_diff($current, $desired)) {
            $event->stop('monsieurbiz_homepage.switch.assigned_channel', errorCode: 409);

            return;
        }
        if ([] !== $desired && method_exists($page, 'isEnabled') && !$page->isEnabled()) {
            $event->stop('monsieurbiz_homepage.switch.assigned_page', errorCode: 409);

            return;
        }
        foreach (array_diff($desired, $current) as $channelId) {
            if ($connection->fetchOne(
                'SELECT page_id FROM monsieurbiz_homepage_homepage_channels WHERE channel_id = ?',
                [$channelId]
            )) {
                $event->stop('monsieurbiz_homepage.switch.assigned_channel', errorCode: 409);

                return;
            }
        }
    }

    #[AsEventListener(event: 'monsieurbiz_homepage.homepage.pre_delete')]
    public function preventDeletion(ResourceControllerEvent $event): void
    {
        /** @var HomepageInterface $page */
        $page = $event->getSubject();
        $connection = $this->homepageManager->getConnection();
        if ($connection->fetchOne('SELECT 1 FROM monsieurbiz_homepage_homepage_channels WHERE page_id = ? LIMIT 1', [$page->getId()])) {
            $event->stop('monsieurbiz_homepage.switch.assigned_page', errorCode: 409);

            return;
        }
        if ($connection->fetchOne(
            'SELECT 1 FROM monsieurbiz_homepage_switch WHERE target_id = ? OR previous_id = ? LIMIT 1',
            [$page->getId(), $page->getId()]
        )) {
            $event->stop('monsieurbiz_homepage.switch.referenced_page', errorCode: 409);
        }
    }

    #[AsEventListener(event: 'monsieurbiz_homepage.homepage.post_update')]
    public function warnAboutPendingSwitches(ResourceControllerEvent $event): void
    {
        /** @var HomepageInterface $page */
        $page = $event->getSubject();
        if (method_exists($page, 'isEnabled') && !$page->isEnabled() && $this->homepageManager->getConnection()->fetchOne(
            'SELECT 1 FROM monsieurbiz_homepage_switch WHERE target_id = ? AND status = ? LIMIT 1',
            [$page->getId(), HomepageSwitch::PENDING]
        )) {
            $request = $this->requestStack->getCurrentRequest();
            $session = $request?->hasSession() ? $request->getSession() : null;
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('warning', 'monsieurbiz_homepage.switch.page_disabled_with_pending');
            }
        }
    }
}
