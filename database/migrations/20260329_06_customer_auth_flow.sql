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
