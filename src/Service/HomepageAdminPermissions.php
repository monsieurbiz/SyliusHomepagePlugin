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

namespace MonsieurBiz\SyliusHomepagePlugin\Service;

use Sylius\Bundle\ResourceBundle\Controller\AuthorizationCheckerInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfigurationFactoryInterface;
use Sylius\Resource\Metadata\RegistryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface as SecurityAuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class HomepageAdminPermissions
{
    public function __construct(
        private RegistryInterface $registry,
        private RequestConfigurationFactoryInterface $requestConfigurationFactory,
        private AuthorizationCheckerInterface $authorizationChecker,
        private SecurityAuthorizationCheckerInterface $security
    ) {
    }

    public function requirePermission(Request $request, string $action, bool $canonical = false): void
    {
        $metadata = $this->registry->get('monsieurbiz_homepage.homepage');
        $configuration = $this->requestConfigurationFactory->create($metadata, $request);
        // A create-route permission override must never authorize an activation.
        $permission = $canonical
            ? sprintf('%s.%s.%s', $metadata->getApplicationName(), $metadata->getName(), $action)
            : $configuration->getPermission($action);
        if (!$this->security->isGranted('ROLE_ADMINISTRATION_ACCESS') || !$this->authorizationChecker->isGranted($configuration, $permission)) {
            throw new AccessDeniedException();
        }
    }
}
