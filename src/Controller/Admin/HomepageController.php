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

namespace MonsieurBiz\SyliusHomepagePlugin\Controller\Admin;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageWriteLock;
use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class HomepageController extends ResourceController
{
    public function createAction(Request $request): Response
    {
        try {
            return $this->write($request, fn (): Response => parent::createAction($request));
        } catch (UniqueConstraintViolationException $exception) {
            $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);
            $this->flashHelper->addErrorFlash($configuration, 'monsieurbiz_homepage.unique_channel');

            return $this->redirectHandler->redirectToReferer($configuration);
        }
    }

    public function updateAction(Request $request): Response
    {
        try {
            return $this->write($request, fn (): Response => parent::updateAction($request));
        } catch (UniqueConstraintViolationException $exception) {
            $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);
            $this->flashHelper->addErrorFlash($configuration, 'monsieurbiz_homepage.unique_channel');

            return $this->redirectHandler->redirectToReferer($configuration);
        }
    }

    public function deleteAction(Request $request): Response
    {
        return $this->write($request, fn (): Response => parent::deleteAction($request));
    }

    public function bulkDeleteAction(Request $request): Response
    {
        return $this->write($request, fn (): Response => parent::bulkDeleteAction($request));
    }

    private function write(Request $request, callable $action): Response
    {
        if ($request->isMethodSafe()) {
            return $action();
        }

        if (!$this->manager instanceof EntityManagerInterface) {
            throw new \LogicException('Homepage requires the Doctrine ORM manager.');
        }

        return HomepageWriteLock::run($this->manager->getConnection(), $action);
    }
}
