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
use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class HomepageController extends ResourceController
{
    /**
     * @param Request $request
     *
     * @return Response
     */
    public function createAction(Request $request): Response
    {
        try {
            return parent::createAction($request);
        } catch (UniqueConstraintViolationException $exception) {
            $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);
            $this->flashHelper->addErrorFlash($configuration, 'monsieurbiz_homepage.unique_channel');

            return $this->redirectHandler->redirectToReferer($configuration);
        }
    }

    /**
     * @param Request $request
     *
     * @return Response
     */
    public function updateAction(Request $request): Response
    {
        try {
            return parent::updateAction($request);
        } catch (UniqueConstraintViolationException $exception) {
            $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);
            $this->flashHelper->addErrorFlash($configuration, 'monsieurbiz_homepage.unique_channel');

            return $this->redirectHandler->redirectToReferer($configuration);
        }
    }
}
