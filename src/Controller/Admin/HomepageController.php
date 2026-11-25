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
use MonsieurBiz\SyliusHomepagePlugin\EventListener\HomepageWriteGuard;
use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

class HomepageController extends ResourceController
{
    public function createAction(Request $request): Response
    {
        $session = $request->isMethod('POST') && $request->hasSession() ? $request->getSession() : null;
        $flashes = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag() : null;
        $previousSuccess = $flashes?->peek('success') ?? [];
        try {
            $response = $this->write($request, fn (): Response => parent::createAction($request));
        } catch (\Throwable $exception) {
            // Sylius flashes creation success before dispatching post_create, inside the transaction.
            $flashes?->set('success', $previousSuccess);
            $request->attributes->remove(HomepageWriteGuard::QUEUED_DURING_CREATE);
            if (!$exception instanceof UniqueConstraintViolationException) {
                throw $exception;
            }
            $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);
            $this->flashHelper->addErrorFlash($configuration, 'monsieurbiz_homepage.unique_channel');

            return $this->redirectHandler->redirectToReferer($configuration);
        }

        if (true === $request->attributes->get(HomepageWriteGuard::QUEUED_DURING_CREATE)) {
            $request->attributes->remove(HomepageWriteGuard::QUEUED_DURING_CREATE);
            $flashes?->add('success', 'monsieurbiz_homepage.switch.queued');
        }

        return $response;
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
