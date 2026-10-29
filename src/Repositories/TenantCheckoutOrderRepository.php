<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class TenantCheckoutOrderRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_checkout_orders (
                tenant_id, app_id, product_id, tariff_id, stripe_session_id, stripe_customer_id, stripe_subscription_id,
                stripe_payment_intent_id, stripe_price_id, purchase_kind, target_plan_key, customer_email, customer_name, status, error_message,
                access_key_id, email_sent_at, completed_at
             ) VALUES (
                :tenant_id, :app_id, :product_id, :tariff_id, :stripe_session_id, :stripe_customer_id, :stripe_subscription_id,
                :stripe_payment_intent_id, :stripe_price_id, :purchase_kind, :target_plan_key, :customer_email, :customer_name, :status, :error_message,
                :access_key_id, :email_sent_at, :completed_at
             )'
        );
        $stmt->execute([
            'tenant_id' => $data['tenant_id'] ?? null,
            'app_id' => $data['app_id'],
            'product_id' => $data['product_id'],
            'tariff_id' => $data['tariff_id'],
            'stripe_session_id' => $data['stripe_session_id'],
            'stripe_customer_id' => $data['stripe_customer_id'] ?? null,
            'stripe_subscription_id' => $data['stripe_subscription_id'] ?? null,
            'stripe_payment_intent_id' => $data['stripe_payment_intent_id'] ?? null,
            'stripe_price_id' => $data['stripe_price_id'],
            'purchase_kind' => $data['purchase_kind'],
            'target_plan_key' => $data['target_plan_key'] ?? null,
            'customer_email' => mb_strtolower(trim((string) $data['customer_email'])),
            'customer_name' => $data['customer_name'] ?? null,
            'status' => $data['status'] ?? 'checkout_created',
            'error_message' => $data['error_message'] ?? null,
            'access_key_id' => $data['access_key_id'] ?? null,
            'email_sent_at' => $data['email_sent_at'] ?? null,
            'completed_at' => $data['completed_at'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByStripeSessionId(string $stripeSessionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tenant_checkout_orders WHERE stripe_session_id = :stripe_session_id LIMIT 1'
        );
        $stmt->execute(['stripe_session_id' => $stripeSessionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenant_checkout_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByStripeSessionIdForUpdate(string $stripeSessionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tenant_checkout_orders WHERE stripe_session_id = :stripe_session_id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['stripe_session_id' => $stripeSessionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tenant_checkout_orders WHERE stripe_subscription_id = :stripe_subscription_id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['stripe_subscription_id' => $stripeSubscriptionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByStripePaymentIntentId(string $stripePaymentIntentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tenant_checkout_orders WHERE stripe_payment_intent_id = :stripe_payment_intent_id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['stripe_payment_intent_id' => $stripePaymentIntentId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function update(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $sets = [];
        $params = ['id' => $id];
        foreach ($fields as $key => $value) {
            $sets[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        $sql = 'UPDATE tenant_checkout_orders SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }
}
