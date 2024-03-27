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

use MonsieurBiz\SyliusHomepagePlugin\Entity\Homepage;
use MonsieurBiz\SyliusPlusAdapterPlugin\DependencyInjection\SyliusPlusCompatibilityTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class MonsieurBizSyliusHomepageExtension extends Extension implements PrependExtensionInterface
{
    use SyliusPlusCompatibilityTrait;

    /**
     * @inheritdoc
     */
    public function load(array $config, ContainerBuilder $container): void
    {
        $this->processConfiguration($this->getConfiguration([], $container), $config);
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
        $this->enabledFilteredChannelChoiceType($container, ['homepage' => Homepage::class]);
    }

    /**
     * @inheritdoc
     */
    public function getAlias(): string
    {
        return str_replace('monsieur_biz', 'monsieurbiz', parent::getAlias());
    }

    /**
     * @inheritdoc
     */
    public function prepend(ContainerBuilder $container): void
    {
        $doctrineConfig = $container->getExtensionConfig('doctrine_migrations');
        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => array_merge(array_pop($doctrineConfig)['migrations_paths'] ?? [], [
                'MonsieurBiz\SyliusHomepagePlugin\Migrations' => '@MonsieurBizSyliusHomepagePlugin/Migrations',
            ]),
        ]);
        $this->prependRestrictedResources($container, ['homepage']);
        $this->replaceInGridOriginalQueryBuilderWithChannelRestrictedQueryBuilder(
            $container,
            'monsieurbiz_homepage',
            '%monsieurbiz_homepage.model.homepage.class%',
            "expr:service('monsieurbiz_homepage.repository.homepage').createQueryBuilder('o')"
        );
    }
}
