<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusHomepagePlugin\Fixture;

use Sylius\Bundle\CoreBundle\Fixture\AbstractResourceFixture;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class HomepageFixture extends AbstractResourceFixture
{
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'monsieurbiz_homepage';
    }

    /**
     * {@inheritdoc}
     */
    protected function configureResourceNode(ArrayNodeDefinition $resourceNode): void
    {
        $resourceNode
            ->children()
                ->arrayNode('channels')->scalarPrototype()->end()->end()
                ->arrayNode('translations')
                    ->prototype('array')
                        ->children()
                            ->scalarNode('name')->cannotBeEmpty()->end()
                            ->scalarNode('content')->cannotBeEmpty()->end()
                            ->scalarNode('metaTitle')->end()
                            ->scalarNode('metaDescription')->end()
                            ->scalarNode('metaKeywords')->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
