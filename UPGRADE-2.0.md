# UPGRADE FROM `1.3` TO `2.0`

- Support of Sylius Plus RBAC is momentarily removed.
- If you've customized the admin form, use twig hooks to add your fields.
  (see [Twig Hooks config](src/Resources/config/sylius/twig_hooks.yaml)).
- Template path has changed from `@MonsieurBizSyliusHomepagePlugin/Homepage/index.html.twig` to `@MonsieurBizSyliusHomepagePlugin/shop/homepage/index.html.twig`, particularly in the `monsieurbiz_sylius_homepage_homepage` route.
