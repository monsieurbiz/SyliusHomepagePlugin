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

namespace MonsieurBiz\SyliusHomepagePlugin\Twig;

use Doctrine\ORM\EntityManagerInterface;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageSwitch;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class HomepageSwitchExtension extends AbstractExtension
{
    private const COUNTS = '_monsieurbiz_homepage_pending_switch_counts';

    public function __construct(
        private EntityManagerInterface $homepageManager,
        private RequestStack $requestStack
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('monsieurbiz_homepage_pending_switch_count', [$this, 'getPendingSwitchCount'])];
    }

    public function getPendingSwitchCount(int $homepageId): int
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return 0;
        }

        if (!$request->attributes->has(self::COUNTS)) {
            $request->attributes->set(self::COUNTS, $this->homepageManager->getConnection()->fetchAllKeyValue(
                'SELECT target_id, COUNT(*) FROM monsieurbiz_homepage_switch WHERE status = ? GROUP BY target_id',
                [HomepageSwitch::PENDING]
            ));
        }

        return (int) ($request->attributes->all(self::COUNTS)[$homepageId] ?? 0);
    }
}
