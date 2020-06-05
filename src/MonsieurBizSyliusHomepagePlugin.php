<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusHomepagePlugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class MonsieurBizSyliusHomepagePlugin extends Bundle
{
    use SyliusPluginTrait;
}
