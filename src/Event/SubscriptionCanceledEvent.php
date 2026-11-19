<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Event;

/**
 * The subscription has ended in Stripe (status "canceled").
 */
class SubscriptionCanceledEvent extends SubscriptionEvent
{
}
