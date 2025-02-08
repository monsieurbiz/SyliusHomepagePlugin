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

namespace App\Menu;

use MonsieurBiz\SyliusMenuPlugin\Provider\AbstractUrlProvider;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class HomepageUrlProvider extends AbstractUrlProvider
{
    public const PROVIDER_CODE = 'homepage';

    protected string $code = self::PROVIDER_CODE;

    protected string $icon = 'home';

    protected int $priority = 1000;

    public function __construct(
        RouterInterface $router,
        private TranslatorInterface $translator
    ) {
        parent::__construct($router);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function getResults(string $locale, string $search = ''): iterable
    {
        return [
            (object) [
                'title' => $this->translator->trans('monsieurbiz_homepage.ui.homepage', [], 'messages', $locale),
            ],
        ];
    }

    protected function addItemFromResult(object $result, string $locale): void
    {
        $this->addItem(
            (string) $result->title,
            $this->router->generate('monsieurbiz_sylius_homepage_homepage', ['_locale' => $locale])
        );
    }
}
