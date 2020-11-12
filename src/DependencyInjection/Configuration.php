<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusHomepagePlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('monsieurbiz_sylius_homepage');
        if (method_exists($treeBuilder, 'getRootNode')) {
            $treeBuilder->getRootNode();
        } else {
            // BC layer for symfony/config 4.1 and older
            /** @scrutinizer ignore-deprecated */ $treeBuilder->root('monsieurbiz_sylius_homepage');
        }

        return $treeBuilder;
    }
}
