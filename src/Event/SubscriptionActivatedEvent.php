<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Event;

/**
 * The subscription now gives access to the service (first payment done, or back from a status without access).
 */
class SubscriptionActivatedEvent extends SubscriptionEvent
{
}
