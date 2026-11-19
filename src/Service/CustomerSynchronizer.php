<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Service;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Exception\CustomerNotPersistedException;
use Lle\SaasBundle\Stripe\StripeApiCallJournalInterface;
use Lle\SaasBundle\Stripe\StripeAppScope;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Creates or updates the Stripe customer matching a local customer.
 *
 * Creation is safe against a double submit (two tabs, double click):
 *  - the customer row is locked (SELECT ... FOR UPDATE) while the Stripe customer is created: the second request
 *    waits, then finds the Stripe id stored by the first one;
 *  - the idempotency key is derived from the local id: Stripe answers the same customer to a retried creation.
 * The Stripe id is written with a targeted update, without flushing the pending changes of the application.
 */
class CustomerSynchronizer
{
    /** Metadata set on the Stripe customer to find the local customer back. */
    public const METADATA_CUSTOMER_ID = 'lle_saas_customer_id';
    public const IDEMPOTENCY_KEY_FORMAT = 'lle_saas_%s_customer_create_%d';
    public const FIELD_ID = 'id';
    public const FIELD_STRIPE_CUSTOMER_ID = 'stripeCustomerId';

    public function __construct(
        protected StripeGatewayInterface $gateway,
        protected EntityManagerInterface $entityManager,
        protected StripeAppScope $appScope,
        protected StripeApiCallJournalInterface $apiCallJournal,
    ) {
    }

    /**
     * @throws CustomerNotPersistedException
     * @throws ApiErrorException
     */
    public function synchronize(CustomerInterface $customer): void
    {
        $customerId = $customer->getId();
        if ($customerId === null) {
            throw new CustomerNotPersistedException();
        }

        $stripeCustomerId = $customer->getStripeCustomerId();
        if ($stripeCustomerId !== null) {
            $this->gateway->updateCustomer($stripeCustomerId, $this->params($customer, $customerId));

            return;
        }

        $this->create($customer, $customerId);
    }

    /**
     * @throws ApiErrorException
     */
    protected function create(CustomerInterface $customer, int $customerId): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $stripeCustomerId = $this->lockedStripeCustomerId($customer, $customerId);
            if ($stripeCustomerId === null) {
                $stripeCustomerId = $this->gateway->createCustomer(
                    $this->params($customer, $customerId),
                    sprintf(self::IDEMPOTENCY_KEY_FORMAT, $this->appScope->getAppId(), $customerId),
                )->id;
                $this->storeStripeCustomerId($customer, $customerId, $stripeCustomerId);
            }
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        } finally {
            $this->apiCallJournal->release();
        }

        $this->markStored($customer, $stripeCustomerId);
    }

    /**
     * Stripe id currently stored, read with a row lock held until the end of the transaction.
     */
    protected function lockedStripeCustomerId(CustomerInterface $customer, int $customerId): ?string
    {
        $stripeCustomerId = $this->entityManager->createQueryBuilder()
            ->select('customer.' . self::FIELD_STRIPE_CUSTOMER_ID)
            ->from($customer::class, 'customer')
            ->andWhere('customer.' . self::FIELD_ID . ' = :id')
            ->setParameter('id', $customerId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getSingleScalarResult();

        return is_string($stripeCustomerId) && $stripeCustomerId !== '' ? $stripeCustomerId : null;
    }

    protected function storeStripeCustomerId(CustomerInterface $customer, int $customerId, string $stripeCustomerId): void
    {
        $metadata = $this->entityManager->getClassMetadata($customer::class);
        $this->entityManager->getConnection()->update(
            $metadata->getTableName(),
            [$metadata->getColumnName(self::FIELD_STRIPE_CUSTOMER_ID) => $stripeCustomerId],
            [$metadata->getColumnName(self::FIELD_ID) => $customerId],
        );
    }

    /**
     * Sets the id on the entity as already stored, so that the next flush of the application does not write it again.
     */
    protected function markStored(CustomerInterface $customer, string $stripeCustomerId): void
    {
        $customer->setStripeCustomerId($stripeCustomerId);
        $unitOfWork = $this->entityManager->getUnitOfWork();
        if ($unitOfWork->isInIdentityMap($customer)) {
            $unitOfWork->setOriginalEntityProperty(spl_object_id($customer), self::FIELD_STRIPE_CUSTOMER_ID, $stripeCustomerId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function params(CustomerInterface $customer, int $customerId): array
    {
        return [
            'name' => (string) $customer->getName(),
            'email' => $customer->getBillingEmail(),
            'metadata' => [self::METADATA_CUSTOMER_ID => (string) $customerId] + $this->appScope->metadata(),
        ];
    }
}
