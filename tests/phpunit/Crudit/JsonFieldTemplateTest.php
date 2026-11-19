<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Crudit;

use Lle\SaasBundle\Crudit\Config\AbstractReadOnlyCrudConfig;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class JsonFieldTemplateTest extends TestCase
{
    public function testDisplaysANestedPayloadAsIndentedJson(): void
    {
        $payload = [
            'id' => 'evt_1',
            'data' => ['object' => ['id' => 'sub_1', 'metadata' => ['lle_saas_app' => 'app<script>']]],
        ];

        $html = $this->twig()->render(AbstractReadOnlyCrudConfig::JSON_TEMPLATE, ['value' => $payload]);

        $this->assertStringContainsString('<pre', $html);
        $this->assertStringContainsString('&quot;id&quot;: &quot;sub_1&quot;', $html);
        $this->assertStringContainsString('app&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testDisplaysNothingWithoutPayload(): void
    {
        $html = $this->twig()->render(AbstractReadOnlyCrudConfig::JSON_TEMPLATE, ['value' => null]);

        $this->assertSame('', trim($html));
    }

    protected function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 3) . '/src/Resources/views', 'LleSaas');

        return new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
    }
}
