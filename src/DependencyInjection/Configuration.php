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

namespace MonsieurBiz\SyliusHomepagePlugin\DependencyInjection;

use DateTimeZone;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    /**
     * @inheritdoc
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('monsieurbiz_sylius_homepage');
        $tree->getRootNode()
            ->children()
                ->scalarNode('timezone')
                    ->defaultValue('UTC')
                    ->validate()
                        ->ifNotInArray(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))
                        ->thenInvalid('Expected a named timezone, for example Europe/Paris, got %s.')
                    ->end()
                ->end()
            ->end()
        ;

        return $tree;
    }
}
