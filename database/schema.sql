CREATE TABLE IF NOT EXISTS tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    status ENUM('active', 'suspended', 'trial', 'archived') NOT NULL DEFAULT 'active',
    plan_key VARCHAR(100) DEFAULT 'internal',
    timezone VARCHAR(100) DEFAULT 'Europe/Prague',
    default_currency VARCHAR(3) DEFAULT 'EUR',
    is_system BOOLEAN DEFAULT FALSE,
    is_demo BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenants_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    tenant_user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner', 'admin', 'manager', 'viewer') NOT NULL DEFAULT 'owner',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_user_id) REFERENCES tenant_users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_membership (tenant_id, tenant_user_id),
    INDEX idx_tenant_memberships_tenant (tenant_id),
    INDEX idx_tenant_memberships_user (tenant_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    key_name VARCHAR(120) NOT NULL,
    value TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_setting (tenant_id, key_name),
    INDEX idx_tenant_settings_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_stripe_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    publishable_key VARCHAR(255) DEFAULT NULL,
    secret_key_encrypted TEXT DEFAULT NULL,
    webhook_secret_encrypted TEXT DEFAULT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_stripe_connection (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_smtp_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    is_enabled BOOLEAN DEFAULT TRUE,
    host VARCHAR(255) DEFAULT NULL,
    port INT DEFAULT 587,
    username VARCHAR(255) DEFAULT NULL,
    password_encrypted TEXT DEFAULT NULL,
    encryption VARCHAR(20) DEFAULT 'tls',
    from_email VARCHAR(255) DEFAULT NULL,
    from_name VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_smtp_connection (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS apps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    slug VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    platform_type VARCHAR(50) DEFAULT NULL,
    base_url VARCHAR(255) DEFAULT NULL,
    public_base_url VARCHAR(255) DEFAULT NULL,
    checkout_success_url VARCHAR(512) DEFAULT NULL,
    checkout_cancel_url VARCHAR(512) DEFAULT NULL,
    support_email VARCHAR(255) DEFAULT NULL,
    notification_email VARCHAR(255) DEFAULT NULL,
    default_currency VARCHAR(3) DEFAULT NULL,
    enabled_currencies VARCHAR(32) DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_apps_tenant (tenant_id),
    INDEX idx_apps_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS licenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    app_id INT DEFAULT NULL,
    license_key VARCHAR(64) UNIQUE NOT NULL,
    license_type ENUM('lifetime', 'annual', 'monthly', 'trial', 'whitelabel') NOT NULL,
    product_slug VARCHAR(50) DEFAULT NULL,
    product_name VARCHAR(255) DEFAULT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_name VARCHAR(255) DEFAULT NULL,
    customer_company VARCHAR(255) DEFAULT NULL,
    customer_vat_id VARCHAR(50) DEFAULT NULL,
    stripe_customer_id VARCHAR(100) DEFAULT NULL,
    stripe_subscription_id VARCHAR(100) DEFAULT NULL,
    stripe_payment_intent_id VARCHAR(100) DEFAULT NULL,
    order_id VARCHAR(100) DEFAULT NULL,
    price DECIMAL(10,2) DEFAULT NULL,
    currency VARCHAR(3) DEFAULT 'CZK',
    issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    activated_at TIMESTAMP NULL DEFAULT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    last_verified_at TIMESTAMP NULL DEFAULT NULL,
    max_activations INT DEFAULT 1,
    current_activations INT DEFAULT 0,
    status ENUM('pending', 'active', 'expired', 'revoked', 'refunded') DEFAULT 'pending',
    revoke_reason TEXT DEFAULT NULL,
    whitelabel_config JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE SET NULL,
    INDEX idx_tenant_id (tenant_id),
    INDEX idx_app_id (app_id),
    INDEX idx_email (customer_email),
    INDEX idx_key (license_key),
    INDEX idx_status (status),
    INDEX idx_stripe_customer (stripe_customer_id),
    INDEX idx_expires (expires_at),
    INDEX idx_license_status_type (status, license_type),
    FULLTEXT INDEX idx_fulltext_search (customer_name, customer_email, customer_company)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_activations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id BIGINT UNSIGNED NOT NULL,
    domain VARCHAR(255) NOT NULL,
    server_ip VARCHAR(45) DEFAULT NULL,
    installation_hash VARCHAR(64) NOT NULL,
    php_version VARCHAR(20) DEFAULT NULL,
    cms_version VARCHAR(20) DEFAULT NULL,
    database_type VARCHAR(20) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    activated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_ping_at TIMESTAMP NULL DEFAULT NULL,
    deactivated_at TIMESTAMP NULL DEFAULT NULL,
    deactivation_reason VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    INDEX idx_license (license_id),
    INDEX idx_installation (installation_hash),
    INDEX idx_domain (domain),
    INDEX idx_is_active (is_active),
    INDEX idx_last_ping (last_ping_at),
    INDEX idx_activation_license_active (license_id, is_active),
    INDEX idx_activation_license_hash_active (license_id, installation_hash, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_verifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id BIGINT UNSIGNED NOT NULL,
    activation_id BIGINT UNSIGNED DEFAULT NULL,
    verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    status ENUM('valid', 'expired', 'revoked', 'invalid', 'limit_exceeded') NOT NULL,
    response_time_ms INT DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    FOREIGN KEY (activation_id) REFERENCES license_activations(id) ON DELETE SET NULL,
    INDEX idx_license (license_id),
    INDEX idx_verified_at (verified_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    role VARCHAR(20) DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_access_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    access_key_hash CHAR(64) NOT NULL,
    status ENUM('pending', 'used', 'revoked') NOT NULL DEFAULT 'pending',
    expires_at TIMESTAMP NULL DEFAULT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    used_by_tenant_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_by_admin_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (used_by_tenant_user_id) REFERENCES tenant_users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_tenant_access_keys_tenant (tenant_id),
    INDEX idx_tenant_access_keys_email (email),
    INDEX idx_tenant_access_keys_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_user_id) REFERENCES tenant_users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_password_reset_token_hash (token_hash),
    INDEX idx_tenant_password_reset_user (tenant_user_id),
    INDEX idx_tenant_password_reset_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_activation_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    tenant_access_key_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    status ENUM('pending', 'used', 'expired', 'revoked') NOT NULL DEFAULT 'pending',
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_ip VARCHAR(45) DEFAULT NULL,
    created_user_agent VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_access_key_id) REFERENCES tenant_access_keys(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_activation_token_hash (token_hash),
    INDEX idx_tenant_activation_tokens_tenant (tenant_id),
    INDEX idx_tenant_activation_tokens_access_key (tenant_access_key_id),
    INDEX idx_tenant_activation_tokens_email (email),
    INDEX idx_tenant_activation_tokens_status (status),
    INDEX idx_tenant_activation_tokens_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    signature VARCHAR(255) DEFAULT NULL,
    status ENUM('success', 'failed', 'retry') DEFAULT 'success',
    error_message TEXT DEFAULT NULL,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_type (event_type),
    INDEX idx_processed_at (processed_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_receipts (
    scope VARCHAR(80) NOT NULL,
    event_id VARCHAR(255) NOT NULL,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (scope, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id BIGINT UNSIGNED DEFAULT NULL,
    license_id BIGINT UNSIGNED DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED DEFAULT NULL,
    old_values JSON DEFAULT NULL,
    new_values JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE SET NULL,
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    minute_bucket VARCHAR(16) NOT NULL,
    request_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_rate (ip_address, endpoint, minute_bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    attempts INT DEFAULT 0,
    available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reserved_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jobs_available (available_at, reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS failed_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT UNSIGNED DEFAULT NULL,
    error_message TEXT NOT NULL,
    payload JSON NOT NULL,
    failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_failed_at (failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(100) UNIQUE NOT NULL,
    value TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key_name (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    app_id INT NOT NULL,
    key_name VARCHAR(100) NOT NULL,
    key_type ENUM('client_public', 'server_secret') NOT NULL,
    key_id VARCHAR(40) NOT NULL UNIQUE,
    public_key VARCHAR(255) DEFAULT NULL,
    secret_hash VARCHAR(255) DEFAULT NULL,
    last4 VARCHAR(8) DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    rotated_at TIMESTAMP NULL DEFAULT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    INDEX idx_app_keys_tenant (tenant_id),
    INDEX idx_app_keys_app (app_id),
    INDEX idx_app_keys_type (key_type),
    INDEX idx_app_keys_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    slug VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_products_tenant_slug (tenant_id, slug),
    INDEX idx_products_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    app_id INT NOT NULL,
    product_id INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_app_product (app_id, product_id),
    INDEX idx_app_products_tenant (tenant_id),
    INDEX idx_app_products_app (app_id),
    INDEX idx_app_products_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_tariffs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    product_id INT NOT NULL,
    license_type ENUM('monthly', 'annual', 'lifetime') NOT NULL,
    stripe_price_id VARCHAR(255) NOT NULL,
    purchase_kind VARCHAR(32) NOT NULL DEFAULT 'license',
    target_plan_key VARCHAR(50) DEFAULT NULL,
    price DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'CZK',
    is_recommended BOOLEAN DEFAULT FALSE,
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_product_tariffs_tenant (tenant_id),
    UNIQUE KEY uniq_product_tariff_currency (product_id, license_type, currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_checkout_orders (
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
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (tariff_id) REFERENCES product_tariffs(id) ON DELETE CASCADE,
    FOREIGN KEY (access_key_id) REFERENCES tenant_access_keys(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_tenant_checkout_session (stripe_session_id),
    INDEX idx_tenant_checkout_orders_tenant (tenant_id),
    INDEX idx_tenant_checkout_orders_subscription (stripe_subscription_id),
    INDEX idx_tenant_checkout_orders_payment_intent (stripe_payment_intent_id),
    INDEX idx_tenant_checkout_orders_email (customer_email),
    INDEX idx_tenant_checkout_orders_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_request_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(32) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    page_source VARCHAR(100) DEFAULT NULL,
    status VARCHAR(32) NOT NULL,
    spam_score INT NOT NULL DEFAULT 0,
    failure_reason VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_form_request_log_type_ip_created (type, ip_address, created_at),
    INDEX idx_form_request_log_type_email_created (type, email, created_at),
    INDEX idx_form_request_log_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(100) NOT NULL UNIQUE,
    subject VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    available_placeholders TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_notification_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    app_id INT NOT NULL,
    is_enabled BOOLEAN DEFAULT TRUE,
    sender_name VARCHAR(255) DEFAULT NULL,
    reply_to_email VARCHAR(255) DEFAULT NULL,
    sales_email VARCHAR(255) DEFAULT NULL,
    ops_email VARCHAR(255) DEFAULT NULL,
    support_url VARCHAR(255) DEFAULT NULL,
    brand_color VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    INDEX idx_app_notification_profiles_tenant (tenant_id),
    UNIQUE KEY uniq_app_notification_profile (app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_email_template_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id INT NOT NULL,
    template_key VARCHAR(100) NOT NULL,
    subject VARCHAR(500) DEFAULT NULL,
    body TEXT DEFAULT NULL,
    is_enabled BOOLEAN DEFAULT TRUE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_app_template_override (app_id, template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    policy_key VARCHAR(120) NOT NULL UNIQUE,
    policy_value JSON DEFAULT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    app_id INT NOT NULL,
    policy_key VARCHAR(120) NOT NULL,
    policy_value JSON DEFAULT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_app_policy (app_id, policy_key),
    INDEX idx_app_policies_tenant (tenant_id),
    INDEX idx_app_policies_app (app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    product_id INT NOT NULL,
    policy_key VARCHAR(120) NOT NULL,
    policy_value JSON DEFAULT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_product_policy (product_id, policy_key),
    INDEX idx_product_policies_tenant (tenant_id),
    INDEX idx_product_policies_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tariff_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    tariff_id INT NOT NULL,
    policy_key VARCHAR(120) NOT NULL,
    policy_value JSON DEFAULT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (tariff_id) REFERENCES product_tariffs(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tariff_policy (tariff_id, policy_key),
    INDEX idx_tariff_policies_tenant (tenant_id),
    INDEX idx_tariff_policies_tariff (tariff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    license_id BIGINT UNSIGNED NOT NULL,
    override_key VARCHAR(120) NOT NULL,
    override_value JSON DEFAULT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_license_override (license_id, override_key),
    INDEX idx_license_overrides_tenant (tenant_id),
    INDEX idx_license_overrides_license (license_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operator_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_operator_notes_entity (entity_type, entity_id),
    INDEX idx_operator_notes_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_health_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    check_type VARCHAR(80) NOT NULL,
    status ENUM('ok', 'warning', 'error') NOT NULL DEFAULT 'ok',
    details_json JSON DEFAULT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_system_health_type (check_type),
    INDEX idx_system_health_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS google_analytics_integrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    app_id INT NOT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    measurement_id VARCHAR(64) DEFAULT NULL,
    website_url VARCHAR(255) DEFAULT NULL,
    property_name VARCHAR(255) DEFAULT NULL,
    admin_link VARCHAR(500) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_google_analytics_app (app_id),
    INDEX idx_google_analytics_tenant (tenant_id),
    INDEX idx_google_analytics_enabled (is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_provider_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    module_key VARCHAR(50) NOT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    provider_name VARCHAR(100) DEFAULT NULL,
    model_name VARCHAR(150) DEFAULT NULL,
    api_key_encrypted TEXT DEFAULT NULL,
    daily_request_limit INT NOT NULL DEFAULT 50,
    store_prompts BOOLEAN DEFAULT FALSE,
    redact_sensitive_data BOOLEAN DEFAULT TRUE,
    enabled_app_ids_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_ai_provider_tenant_module (tenant_id, module_key),
    INDEX idx_ai_provider_settings_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_insight_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    app_id INT DEFAULT NULL,
    triggered_by BIGINT UNSIGNED DEFAULT NULL,
    insight_type VARCHAR(80) NOT NULL,
    input_summary TEXT DEFAULT NULL,
    output_text MEDIUMTEXT DEFAULT NULL,
    source_context_json JSON DEFAULT NULL,
    status ENUM('queued', 'completed', 'failed') NOT NULL DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE SET NULL,
    FOREIGN KEY (triggered_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_ai_insight_tenant (tenant_id),
    INDEX idx_ai_insight_app (app_id),
    INDEX idx_ai_insight_type (insight_type),
    INDEX idx_ai_insight_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_assistant_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED DEFAULT NULL,
    app_id INT DEFAULT NULL,
    triggered_by BIGINT UNSIGNED DEFAULT NULL,
    task_type VARCHAR(80) NOT NULL,
    input_text MEDIUMTEXT DEFAULT NULL,
    output_text MEDIUMTEXT DEFAULT NULL,
    status ENUM('queued', 'completed', 'failed') NOT NULL DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE SET NULL,
    FOREIGN KEY (triggered_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_ai_assistant_tenant (tenant_id),
    INDEX idx_ai_assistant_app (app_id),
    INDEX idx_ai_assistant_type (task_type),
    INDEX idx_ai_assistant_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenants (slug, name, status, plan_key, timezone, default_currency, is_system, is_demo) VALUES
('default', 'Default Tenant', 'active', 'internal', 'Europe/Prague', 'EUR', 1, 0)
ON DUPLICATE KEY UPDATE
slug = slug;

INSERT INTO email_templates (template_key, subject, body, available_placeholders) VALUES
('new_license', 'Váš nový licenční klíč pro {product_name}', 'Dobrý den {customer_name},\n\nděkujeme za nákup. Váš licenční klíč je:\n\n{license_key}\n\nMůžete jej aktivovat ve vaší aplikaci.\n\nS pozdravem,\nTým myLocalio', '{customer_name}, {customer_email}, {license_key}, {product_name}, {license_type}, {expires_at}'),
('expiring_soon', 'Vaše licence pro {product_name} brzy vyprší', 'Dobrý den {customer_name},\n\nvaše licence ({license_type}) pro {product_name} vyprší dne {expires_at}.\n\nProdlužte si ji ve svém účtu.\n\nS pozdravem,\nTým myLocalio', '{customer_name}, {customer_email}, {license_key}, {product_name}, {license_type}, {expires_at}'),
('expired', 'Vaše licence pro {product_name} vypršela', 'Dobrý den {customer_name},\n\nvaše licence pro {product_name} dnes vypršela.\n\nPokud chcete pokračovat v používání prémiových funkcí, obnovte si předplatné.\n\nS pozdravem,\nTým myLocalio', '{customer_name}, {customer_email}, {product_name}, {license_type}'),
('renewal_success', 'Předplatné {product_name} bylo úspěšně obnoveno', 'Dobrý den {customer_name},\n\nvaše {license_type} předplatné pro {product_name} bylo úspěšně obnoveno.\n\nNové datum expirace: {expires_at}\n\nS pozdravem,\nTým myLocalio', '{customer_name}, {customer_email}, {product_name}, {license_type}, {expires_at}')
ON DUPLICATE KEY UPDATE template_key = template_key;

INSERT INTO settings (key_name, value) VALUES
('smtp_host', ''),
('smtp_port', '587'),
('smtp_username', ''),
('smtp_password', ''),
('smtp_encryption', 'tls'),
('smtp_from_email', 'noreply@example.invalid'),
('smtp_from_name', 'myLocalio'),
('contact_form_recipient_email', 'contact@example.invalid'),
('turnstile_site_key', ''),
('turnstile_secret_key', '')
ON DUPLICATE KEY UPDATE key_name = key_name;
