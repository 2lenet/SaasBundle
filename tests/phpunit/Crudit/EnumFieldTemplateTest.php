<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Crudit;

use Lle\SaasBundle\Crudit\Config\AbstractReadOnlyCrudConfig;
use Lle\SaasBundle\Enum\SubscriptionStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class EnumFieldTemplateTest extends TestCase
{
    public function testDisplaysTheTranslatedValueOfTheEnum(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 3) . '/src/Resources/views', 'LleSaas');
        $twig = new Environment($loader);
        $twig->addExtension(new TranslationExtension($this->translator()));

        $this->assertSame('Impayé (relances en cours)', trim($twig->render(AbstractReadOnlyCrudConfig::ENUM_TEMPLATE, ['value' => SubscriptionStatus::PastDue])));
        $this->assertSame('', trim($twig->render(AbstractReadOnlyCrudConfig::ENUM_TEMPLATE, ['value' => null])));
    }

    protected function translator(): Translator
    {
        $translator = new Translator('fr');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource(
            'yaml',
            TranslationCatalogTest::TRANSLATIONS . AbstractReadOnlyCrudConfig::TRANSLATION_DOMAIN . '.fr.yaml',
            'fr',
            AbstractReadOnlyCrudConfig::TRANSLATION_DOMAIN,
        );

        return $translator;
    }
}
