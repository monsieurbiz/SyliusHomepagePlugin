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

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageInterface;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageSwitch;
use MonsieurBiz\SyliusHomepagePlugin\Form\Type\HomepageType;
use MonsieurBiz\SyliusHomepagePlugin\Repository\HomepageRepository;
use MonsieurBiz\SyliusHomepagePlugin\Service\HomepageAdminPermissions;
use MonsieurBiz\SyliusHomepagePlugin\Service\HomepageSwitchScheduler;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Resource\ResourceActions;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class HomepageWriteGuard
{
    public const QUEUED_DURING_CREATE = '_monsieurbiz_homepage_queued_during_create';

    private const QUEUE_AFTER_CREATE = '_monsieurbiz_homepage_queue_after_create';

    public function __construct(
        private EntityManagerInterface $homepageManager,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private HomepageRepository $homepageRepository,
        private HomepageAdminPermissions $permissions,
        private HomepageSwitchScheduler $scheduler
    ) {
    }

    #[AsEventListener(event: 'monsieurbiz_homepage.homepage.pre_create')]
    public function validateCreation(ResourceControllerEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $intent = $request?->attributes->get(HomepageType::SWITCH_NOW_INTENT);
        $request?->attributes->remove(HomepageType::SWITCH_NOW_INTENT);
        /** @var HomepageInterface $page */
        $page = $event->getSubject();
        $switches = [];
        if (is_array($intent) && $intent[0] === $page && $intent[1] instanceof Collection) {
            /** @var ChannelInterface $channel */
            foreach ($intent[1] as $channel) {
                $switches[(int) $channel->getId()] = $channel;
            }
        }

        $this->validateAssignments($event, $switches);
        if ($event->isStopped()) {
            // The configured update redirect needs an ID, which the rejected page does not have.
            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate('monsieurbiz_homepage_admin_homepage_create'),
                Response::HTTP_SEE_OTHER
            ));

            return;
        }
        if ([] !== $switches && null !== $request) {
            $this->permissions->requirePermission($request, ResourceActions::UPDATE, canonical: true);
            if (!$this->homepageManager->getConnection()->isTransactionActive()) {
                throw new \LogicException('Homepage creation and its switches must share a transaction.');
            }
            foreach ($switches as $channel) {
                $page->removeChannel($channel);
            }
            $request->attributes->set(self::QUEUE_AFTER_CREATE, [$page, $switches]);
        }
    }

    #[AsEventListener(event: 'monsieurbiz_homepage.homepage.post_create', priority: 100)]
    public function queueSwitches(ResourceControllerEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $intent = $request?->attributes->get(self::QUEUE_AFTER_CREATE);
        $request?->attributes->remove(self::QUEUE_AFTER_CREATE);
        if (null === $request || !is_array($intent) || $intent[0] !== $event->getSubject()) {
            return;
        }
        [$page, $switches] = $intent;
        foreach ($switches as $channel) {
            $this->scheduler->schedule($page, $channel);
        }

        $request->attributes->set(self::QUEUED_DURING_CREATE, true);
    }

    #[AsEventListener(event: 'monsieurbiz_homepage.homepage.pre_update')]
    public function validateUpdate(ResourceControllerEvent $event): void
    {
        $this->validateAssignments($event);
    }

    /** @param array<int, ChannelInterface> $switches */
    private function validateAssignments(ResourceControllerEvent $event, array $switches = []): void
    {
        /** @var HomepageInterface $page */
        $page = $event->getSubject();
        if (null !== $error = $this->homepageRepository->findAssignmentError($page, array_keys($switches))) {
            $event->stop($error, errorCode: 409);
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
