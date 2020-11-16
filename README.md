<p align="center">
    <a href="https://monsieurbiz.com" target="_blank">
        <img src="https://monsieurbiz.com/logo.png" width="250px" alt="Monsieur Biz logo" />
    </a>
    &nbsp;&nbsp;&nbsp;&nbsp;
    <a href="https://monsieurbiz.com/agence-web-experte-sylius" target="_blank">
        <img src="https://demo.sylius.com/assets/shop/img/logo.png" width="200px" alt="Sylius logo" />
    </a>
    <br/>
    <img src="https://monsieurbiz.com/assets/images/sylius_badge_extension-artisan.png" width="100" alt="Monsieur Biz is a Sylius Extension Artisan partner">
</p>

<h1 align="center">Homepages</h1>

[![Homepage Plugin license](https://img.shields.io/github/license/monsieurbiz/SyliusHomepagePlugin?public)](https://github.com/monsieurbiz/SyliusHomepagePlugin/blob/master/LICENSE.txt)
[![Build Status](https://img.shields.io/github/workflow/status/monsieurbiz/SyliusHomepagePlugin/Tests)](https://github.com/monsieurbiz/SyliusHomepagePlugin/actions?query=workflow%3ATests)

This plugins allows you to manage your homepages using the Rich Editor.

If you want to know more about our editor, see the [Rich Editor Repository](https://github.com/monsieurbiz/SyliusRichEditorPlugin)

![Example of homepage edition](screenshots/demo.gif)

## Installation

```bash
composer require monsieurbiz/sylius-homepage-plugin
```

Change your `config/bundles.php` file to add the line for the plugin : 

```php
<?php

return [
    //..
    MonsieurBiz\SyliusHomepagePlugin\MonsieurBizSyliusHomepagePlugin::class => ['all' => true],
];
```

Then create the config file in `config/packages/monsieurbiz_sylius_homepage_plugin.yaml` :

```yaml
imports:
    - { resource: "@MonsieurBizSyliusHomepagePlugin/Resources/config/config.yaml" }
```

Finally import the routes in `config/routes/monsieurbiz_sylius_homepage_plugin.yaml` : 

```yaml
monsieurbiz_sylius_homepage_admin:
    resource: "@MonsieurBizSyliusHomepagePlugin/Resources/config/routes/admin.yaml"
    prefix: /admin
monsieurbiz_sylius_homepage_homepage:
    path: /
    methods: [GET]
    defaults:
        _controller: monsieurbiz_homepage.controller.homepage:indexAction
        _sylius:
            template: '@MonsieurBizSyliusHomepagePlugin/Homepage/index.html.twig'
            repository:
                method: findOneByChannelAndLocale
                arguments:
                    - "expr:service('sylius.context.channel').getChannel()"
                    - "expr:service('sylius.context.locale').getLocaleCode()"
```

### Migrations

Make a doctrine migration diff : 

```php
bin/console doctrine:migrations:diff
```

Then run it : 

```php
bin/console doctrine:migrations:migrate
```

## Example of complete homepage

### Admin form with preview

![Admin full form](screenshots/full_back.jpg)

### Front display

![Front full display](screenshots/full_front.jpg)

## Create custom elements

You can customize and create custom elements in your page.  
In order to do that, you can check the [Rich Editor custom element creation](https://github.com/monsieurbiz/SyliusRichEditorPlugin#create-your-own-elements)

## SEO Friendly

You can define for your homepage the meta title, meta description and meta 
keywords.

## Contributing

You can open an issue or a Pull Request if you want! 😘  
Thank you!
