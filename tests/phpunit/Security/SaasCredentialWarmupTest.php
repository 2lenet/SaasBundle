<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Security;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Lle\CredentialBundle\Entity\Credential;
use Lle\CredentialBundle\Factory\CredentialFactory;
use Lle\SaasBundle\Security\SaasCredentialWarmup;
use Lle\SaasBundle\Security\SaasRole;
use PHPUnit\Framework\TestCase;

class SaasCredentialWarmupTest extends TestCase
{
    public function testDeclaresEveryBundleRoleInTheSaasSection(): void
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $created = [];
        $credentialFactory = $this->createMock(CredentialFactory::class);
        $credentialFactory->expects($this->exactly(count(SaasRole::LABELS)))
            ->method('create')
            ->willReturnCallback(
                static function (string $role, ?string $section, ?string $label, ?array $statusList, ?bool $visible, ?string $type) use (&$created): Credential {
                    $created[$role] = [$section, $label, $type];

                    return new Credential();
                }
            );
        $warmup = new SaasCredentialWarmup($entityManager, $credentialFactory);

        $warmup->warmUp();

        $this->assertSame(
            [
                SaasRole::CUSTOMER_ACTION_CHECKOUT => [
                    SaasCredentialWarmup::SECTION,
                    SaasRole::LABELS[SaasRole::CUSTOMER_ACTION_CHECKOUT],
                    SaasCredentialWarmup::CREDENTIAL_TYPE,
                ],
                SaasRole::CUSTOMER_ACTION_BILLINGPORTAL => [
                    SaasCredentialWarmup::SECTION,
                    SaasRole::LABELS[SaasRole::CUSTOMER_ACTION_BILLINGPORTAL],
                    SaasCredentialWarmup::CREDENTIAL_TYPE,
                ],
            ],
            $created
        );
    }

    public function testRolesFollowTheTeamFormat(): void
    {
        foreach (array_keys(SaasRole::LABELS) as $role) {
            $this->assertMatchesRegularExpression('/^ROLE_SAAS_[A-Z]+_ACTION_[A-Z]+$/', $role);
        }
    }

    /**
     * The credential matrix translates the labels in the "messages" domain: keys, created in Loco by the application.
     */
    public function testLabelsAreTranslationKeys(): void
    {
        foreach (SaasRole::LABELS as $label) {
            $this->assertMatchesRegularExpression('/^' . preg_quote(SaasRole::LABEL_PREFIX, '/') . '[a-z_]+$/', $label);
        }
    }
}
