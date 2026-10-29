CREATE TABLE IF NOT EXISTS tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    status ENUM('active', 'suspended', 'trial', 'archived') NOT NULL DEFAULT 'active',
    plan_key VARCHAR(100) DEFAULT 'internal',
    timezone VARCHAR(100) DEFAULT 'Europe/Prague',
    default_currency VARCHAR(3) DEFAULT 'EUR',
    is_system BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
    UNIQUE KEY uniq_tenant_membership (tenant_id, tenant_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    key_name VARCHAR(120) NOT NULL,
    value TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tenant_setting (tenant_id, key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenants (slug, name, status, plan_key, timezone, default_currency, is_system)
VALUES ('default', 'Default Tenant', 'active', 'internal', 'Europe/Prague', 'EUR', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    status = VALUES(status),
    plan_key = VALUES(plan_key),
    timezone = VALUES(timezone),
    default_currency = VALUES(default_currency),
    is_system = VALUES(is_system);

ALTER TABLE licenses ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE apps ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE app_keys ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE products ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE app_products ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE product_tariffs ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE app_notification_profiles ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE app_policies ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE product_policies ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE tariff_policies ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE license_overrides ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE google_analytics_integrations ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE ai_provider_settings ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE ai_insight_runs ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;
ALTER TABLE ai_assistant_runs ADD COLUMN IF NOT EXISTS tenant_id BIGINT UNSIGNED DEFAULT NULL AFTER id;

SET @default_tenant_id := (SELECT id FROM tenants WHERE slug = 'default' LIMIT 1);

UPDATE apps
SET tenant_id = @default_tenant_id
WHERE tenant_id IS NULL;

UPDATE products
SET tenant_id = @default_tenant_id
WHERE tenant_id IS NULL;

UPDATE app_keys k
INNER JOIN apps a ON a.id = k.app_id
SET k.tenant_id = a.tenant_id
WHERE k.tenant_id IS NULL;

UPDATE app_products ap
INNER JOIN apps a ON a.id = ap.app_id
SET ap.tenant_id = a.tenant_id
WHERE ap.tenant_id IS NULL;

UPDATE product_tariffs t
INNER JOIN products p ON p.id = t.product_id
SET t.tenant_id = p.tenant_id
WHERE t.tenant_id IS NULL;

UPDATE licenses l
LEFT JOIN apps a ON a.id = l.app_id
SET l.tenant_id = COALESCE(a.tenant_id, @default_tenant_id)
WHERE l.tenant_id IS NULL;

UPDATE app_notification_profiles p
INNER JOIN apps a ON a.id = p.app_id
SET p.tenant_id = a.tenant_id
WHERE p.tenant_id IS NULL;

UPDATE app_policies p
INNER JOIN apps a ON a.id = p.app_id
SET p.tenant_id = a.tenant_id
WHERE p.tenant_id IS NULL;

UPDATE product_policies p
INNER JOIN products pr ON pr.id = p.product_id
SET p.tenant_id = pr.tenant_id
WHERE p.tenant_id IS NULL;

UPDATE tariff_policies p
INNER JOIN product_tariffs t ON t.id = p.tariff_id
SET p.tenant_id = t.tenant_id
WHERE p.tenant_id IS NULL;

UPDATE license_overrides o
INNER JOIN licenses l ON l.id = o.license_id
SET o.tenant_id = l.tenant_id
WHERE o.tenant_id IS NULL;

UPDATE google_analytics_integrations g
INNER JOIN apps a ON a.id = g.app_id
SET g.tenant_id = a.tenant_id
WHERE g.tenant_id IS NULL;

UPDATE ai_provider_settings
SET tenant_id = @default_tenant_id
WHERE tenant_id IS NULL;

UPDATE ai_insight_runs r
LEFT JOIN apps a ON a.id = r.app_id
SET r.tenant_id = COALESCE(a.tenant_id, @default_tenant_id)
WHERE r.tenant_id IS NULL;

UPDATE ai_assistant_runs r
LEFT JOIN apps a ON a.id = r.app_id
SET r.tenant_id = COALESCE(a.tenant_id, @default_tenant_id)
WHERE r.tenant_id IS NULL;

ALTER TABLE ai_provider_settings
    DROP INDEX module_key,
    ADD UNIQUE KEY uniq_ai_provider_tenant_module (tenant_id, module_key);
