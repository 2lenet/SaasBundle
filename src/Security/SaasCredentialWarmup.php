<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Security;

use Doctrine\ORM\EntityManagerInterface;
use Lle\CredentialBundle\Contracts\CredentialWarmupInterface;
use Lle\CredentialBundle\Factory\CredentialFactory;
use Lle\CredentialBundle\Service\CredentialWarmupTrait;

/**
 * Declares the ROLE_SAAS_* roles in the credential bundle (lle:credential:warmup).
 *
 * Registered by LleSaasExtension only when the credential bundle is installed.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class SaasCredentialWarmup implements CredentialWarmupInterface
{
    use CredentialWarmupTrait;

    public const SECTION = 'SAAS';
    public const CREDENTIAL_TYPE = 'credential.action';

    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected CredentialFactory $credentialFactory,
    ) {
    }

    public function warmUp(): void
    {
        foreach (SaasRole::LABELS as $role => $label) {
            $this->checkAndCreateCredential($role, self::SECTION, $label, statusList: [], type: self::CREDENTIAL_TYPE);
        }
    }
}
