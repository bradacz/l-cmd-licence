CREATE TABLE IF NOT EXISTS apps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    platform_type VARCHAR(50) DEFAULT NULL,
    base_url VARCHAR(255) DEFAULT NULL,
    support_email VARCHAR(255) DEFAULT NULL,
    notification_email VARCHAR(255) DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_apps_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    INDEX idx_app_keys_app (app_id),
    INDEX idx_app_keys_type (key_type),
    INDEX idx_app_keys_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    app_id INT NOT NULL,
    product_id INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_app_product (app_id, product_id),
    INDEX idx_app_products_app (app_id),
    INDEX idx_app_products_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_notification_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
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
    app_id INT NOT NULL,
    policy_key VARCHAR(120) NOT NULL,
    policy_value JSON DEFAULT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_app_policy (app_id, policy_key),
    INDEX idx_app_policies_app (app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    policy_key VARCHAR(120) NOT NULL,
    policy_value JSON DEFAULT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_product_policy (product_id, policy_key),
    INDEX idx_product_policies_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tariff_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tariff_id INT NOT NULL,
    policy_key VARCHAR(120) NOT NULL,
    policy_value JSON DEFAULT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tariff_id) REFERENCES product_tariffs(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_tariff_policy (tariff_id, policy_key),
    INDEX idx_tariff_policies_tariff (tariff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id BIGINT UNSIGNED NOT NULL,
    override_key VARCHAR(120) NOT NULL,
    override_value JSON DEFAULT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_license_override (license_id, override_key),
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

SET @has_app_id := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'licenses'
      AND COLUMN_NAME = 'app_id'
);
SET @sql := IF(
    @has_app_id = 0,
    'ALTER TABLE licenses ADD COLUMN app_id INT DEFAULT NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx_app_id := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'licenses'
      AND INDEX_NAME = 'idx_app_id'
);
SET @sql := IF(
    @has_idx_app_id = 0,
    'ALTER TABLE licenses ADD INDEX idx_app_id (app_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk_app_id := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'licenses'
      AND COLUMN_NAME = 'app_id'
      AND REFERENCED_TABLE_NAME = 'apps'
);
SET @sql := IF(
    @has_fk_app_id = 0,
    'ALTER TABLE licenses ADD CONSTRAINT fk_licenses_app_id FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO apps (slug, name, description, platform_type, is_active)
SELECT 'loftp', 'LoFTP', 'Primary managed application', 'desktop', 1
WHERE NOT EXISTS (
    SELECT 1 FROM apps WHERE slug = 'loftp'
);

INSERT INTO app_products (app_id, product_id, is_active)
SELECT a.id, p.id, 1
FROM apps a
INNER JOIN products p ON p.slug = 'loftp'
WHERE a.slug = 'loftp'
  AND NOT EXISTS (
      SELECT 1
      FROM app_products ap
      WHERE ap.app_id = a.id
        AND ap.product_id = p.id
  );

UPDATE licenses l
INNER JOIN apps a ON a.slug = 'loftp'
SET l.app_id = a.id
WHERE l.app_id IS NULL
  AND l.product_slug = 'loftp';
