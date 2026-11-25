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

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsEventListener(event: KernelEvents::CONTROLLER)]
final class HomepageSwitchGridRequestListener
{
    public function __construct(private AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (true !== $request->attributes->get('_monsieurbiz_homepage_switch_grid')) {
            return;
        }

        if (!$this->authorizationChecker->isGranted('ROLE_ADMINISTRATION_ACCESS')) {
            throw new AccessDeniedException();
        }

        // Reject scalar criteria before Grid expects an array, without interpreting any filter.
        $request->query->all('criteria');
    }
}
