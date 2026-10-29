ALTER TABLE product_tariffs
    ADD COLUMN purchase_kind VARCHAR(32) NOT NULL DEFAULT 'license' AFTER stripe_price_id,
    ADD COLUMN target_plan_key VARCHAR(50) DEFAULT NULL AFTER purchase_kind;

UPDATE product_tariffs
SET purchase_kind = 'license'
WHERE purchase_kind IS NULL OR purchase_kind = '';

CREATE TABLE tenant_checkout_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    app_id INT NOT NULL,
    product_id INT NOT NULL,
    tariff_id INT NOT NULL,
    stripe_session_id VARCHAR(255) NOT NULL,
    stripe_customer_id VARCHAR(255) DEFAULT NULL,
    stripe_subscription_id VARCHAR(255) DEFAULT NULL,
    stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
    stripe_price_id VARCHAR(255) NOT NULL,
    purchase_kind VARCHAR(32) NOT NULL,
    target_plan_key VARCHAR(50) DEFAULT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_name VARCHAR(255) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'checkout_created',
    error_message TEXT DEFAULT NULL,
    access_key_id BIGINT UNSIGNED DEFAULT NULL,
    email_sent_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tenant_checkout_orders_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    CONSTRAINT fk_tenant_checkout_orders_app FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_checkout_orders_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_checkout_orders_tariff FOREIGN KEY (tariff_id) REFERENCES product_tariffs(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_checkout_orders_access_key FOREIGN KEY (access_key_id) REFERENCES tenant_access_keys(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_tenant_checkout_session (stripe_session_id),
    KEY idx_tenant_checkout_orders_tenant (tenant_id),
    KEY idx_tenant_checkout_orders_subscription (stripe_subscription_id),
    KEY idx_tenant_checkout_orders_payment_intent (stripe_payment_intent_id),
    KEY idx_tenant_checkout_orders_email (customer_email),
    KEY idx_tenant_checkout_orders_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
