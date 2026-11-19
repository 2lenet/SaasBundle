<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Security;

/**
 * Roles of the bundle, granted to groups in the credential bundle (never in role_hierarchy).
 */
final class SaasRole
{
    public const CUSTOMER_ACTION_CHECKOUT = 'ROLE_SAAS_CUSTOMER_ACTION_CHECKOUT';
    public const CUSTOMER_ACTION_BILLINGPORTAL = 'ROLE_SAAS_CUSTOMER_ACTION_BILLINGPORTAL';

    /** Prefix of the label keys. */
    public const LABEL_PREFIX = 'credential.saas.';

    /**
     * Label of each role in the credential matrix: a translation key of the "messages" domain (the credential bundle
     * translates the labels in the default domain), created in Loco by the application.
     */
    public const LABELS = [
        self::CUSTOMER_ACTION_CHECKOUT => self::LABEL_PREFIX . 'customer_action_checkout',
        self::CUSTOMER_ACTION_BILLINGPORTAL => self::LABEL_PREFIX . 'customer_action_billingportal',
    ];

    private function __construct()
    {
    }
}
