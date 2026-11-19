<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Crudit;

use Lle\CruditBundle\Dto\Layout\LinkElement;
use Lle\SaasBundle\Crudit\Config\AbstractReadOnlyCrudConfig;
use Lle\SaasBundle\Crudit\CrudMenu\SaasMenuProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\Translation\TranslatorInterface;

class SaasMenuProviderTest extends TestCase
{
    public function testEachScreenIsLinkedWithItsIndexRole(): void
    {
        $provider = new SaasMenuProvider(new IdentityTranslator());

        $entries = [...$provider->getMenuEntry()];

        $this->assertCount(1, $entries);
        $menu = $entries[0];
        $this->assertInstanceOf(LinkElement::class, $menu);
        $this->assertSame(SaasMenuProvider::ROLE, $menu->getRole());
        $links = [];
        foreach ($menu->getChildren() as $child) {
            $this->assertInstanceOf(LinkElement::class, $child);
            $links[(string) $child->getPath()?->getRoute()] = $child->getRole();
        }
        $this->assertSame([
            'lle_saas_crudit_subscription_index' => 'ROLE_SAAS_SUBSCRIPTION_INDEX',
            'lle_saas_crudit_plan_index' => 'ROLE_SAAS_PLAN_INDEX',
            'lle_saas_crudit_stripeevent_index' => 'ROLE_SAAS_STRIPEEVENT_INDEX',
            'lle_saas_crudit_stripeapicall_index' => 'ROLE_SAAS_STRIPEAPICALL_INDEX',
        ], $links);
    }

    public function testLabelsAreTranslatedInTheBundleDomain(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->exactly(1 + count(SaasMenuProvider::ENTRIES)))
            ->method('trans')
            ->with($this->anything(), [], AbstractReadOnlyCrudConfig::TRANSLATION_DOMAIN)
            ->willReturn('Traduit');
        $provider = new SaasMenuProvider($translator);

        $entries = [...$provider->getMenuEntry()];

        $menu = $entries[0];
        $this->assertInstanceOf(LinkElement::class, $menu);
        $this->assertSame('Traduit', $menu->getLibelle());
    }
}
