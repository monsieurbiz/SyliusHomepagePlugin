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

namespace MonsieurBiz\SyliusHomepagePlugin\Fixture\Factory;

use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageInterface;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageTranslationInterface;
use Sylius\Bundle\CoreBundle\Fixture\Factory\AbstractExampleFactory;
use Sylius\Bundle\CoreBundle\Fixture\OptionsResolver\LazyOption;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class HomepageFixtureFactory extends AbstractExampleFactory implements HomepageFixtureFactoryInterface
{
    /**
     * @var FactoryInterface
     */
    private $homepageFactory;

    /**
     * @var FactoryInterface
     */
    private $homepageTranslationFactory;

    /**
     * @var OptionsResolver
     */
    private $optionsResolver;

    /** @var \Faker\Generator */
    private $faker;

    /** @var RepositoryInterface */
    private $localeRepository;

    /** @var ChannelRepositoryInterface */
    private $channelRepository;

    /**
     * @param FactoryInterface $homepageFactory
     * @param FactoryInterface $homepageTranslationFactory
     * @param ChannelRepositoryInterface $channelRepository
     * @param RepositoryInterface $localeRepository
     */
    public function __construct(
        FactoryInterface $homepageFactory,
        FactoryInterface $homepageTranslationFactory,
        ChannelRepositoryInterface $channelRepository,
        RepositoryInterface $localeRepository
    ) {
        $this->homepageFactory = $homepageFactory;
        $this->homepageTranslationFactory = $homepageTranslationFactory;
        $this->channelRepository = $channelRepository;
        $this->localeRepository = $localeRepository;

        $this->faker = \Faker\Factory::create();

        $this->optionsResolver = new OptionsResolver();
        $this->configureOptions($this->optionsResolver);
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $options = []): HomepageInterface
    {
        $options = $this->optionsResolver->resolve($options);

        /** @var HomepageInterface $homepage */
        $homepage = $this->homepageFactory->createNew();

        foreach ($options['channels'] as $channel) {
            $homepage->addChannel($channel);
        }

        $this->createTranslations($homepage, $options);

        return $homepage;
    }

    /**
     * @param HomepageInterface $homepage
     * @param array $options
     */
    private function createTranslations(HomepageInterface $homepage, array $options): void
    {
        foreach ($options['translations'] as $localeCode => $translation) {
            /** @var HomepageTranslationInterface $homepageTranslation */
            $homepageTranslation = $this->homepageTranslationFactory->createNew();
            $homepageTranslation->setLocale($localeCode);
            $homepageTranslation->setName($translation['name']);
            $homepageTranslation->setContent($translation['content']);
            $homepageTranslation->setMetaTitle($translation['metaTitle']);
            $homepageTranslation->setMetaDescription($translation['metaDescription']);
            $homepageTranslation->setMetaKeywords($translation['metaKeywords']);

            $homepage->addTranslation($homepageTranslation);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefault('translations', function(OptionsResolver $translationResolver): void {
                $translationResolver->setDefaults($this->configureDefaultTranslations());
            })
            ->setDefault('channels', []) // No channel to avoid integrity constraint violation
            ->setAllowedTypes('channels', 'array')
            ->setNormalizer('channels', LazyOption::findBy($this->channelRepository, 'code'))
        ;
    }

    /**
     * @return array
     */
    private function configureDefaultTranslations(): array
    {
        $translations = [];
        $locales = $this->localeRepository->findAll();
        /** @var LocaleInterface $locale */
        foreach ($locales as $locale) {
            $name = ucfirst($this->faker->sentence(3, true));
            $translations[$locale->getCode()] = [
                'name' => $name,
                'content' => $this->faker->paragraphs(3, true),
                'metaTitle' => $name,
                'metaDescription' => $this->faker->paragraph,
                'metaKeywords' => $this->faker->sentence(10, true),
            ];
        }

        return $translations;
    }
}
