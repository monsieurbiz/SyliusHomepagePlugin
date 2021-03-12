<?php

/*
 * This file is part of Monsieur Biz' Homepage plugin for Sylius.
 *
 * (c) Monsieur Biz <sylius@monsieurbiz.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MonsieurBiz\SyliusHomepagePlugin\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use MonsieurBiz\SyliusHomepagePlugin\Fixture\Factory\HomepageFixtureFactoryInterface;
use Sylius\Bundle\CoreBundle\Fixture\AbstractResourceFixture;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class HomepageFixture extends AbstractResourceFixture
{
    public function __construct(EntityManagerInterface $homepageManager, HomepageFixtureFactoryInterface $exampleFactory)
    {
        parent::__construct($homepageManager, $exampleFactory);
    }

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
        /** @phpstan-ignore-next-line */
        $resourceNode
            ->children()
                ->arrayNode('channels')->scalarPrototype()->end()->end()
                ->arrayNode('translations')
                    ->arrayPrototype()
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
