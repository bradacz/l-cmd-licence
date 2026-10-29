CREATE TABLE IF NOT EXISTS google_analytics_integrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id INT NOT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    measurement_id VARCHAR(64) DEFAULT NULL,
    website_url VARCHAR(255) DEFAULT NULL,
    property_name VARCHAR(255) DEFAULT NULL,
    admin_link VARCHAR(500) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_google_analytics_app (app_id),
    INDEX idx_google_analytics_enabled (is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_provider_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(50) NOT NULL UNIQUE,
    is_enabled BOOLEAN DEFAULT FALSE,
    provider_name VARCHAR(100) DEFAULT NULL,
    model_name VARCHAR(150) DEFAULT NULL,
    api_key_encrypted TEXT DEFAULT NULL,
    daily_request_limit INT NOT NULL DEFAULT 50,
    store_prompts BOOLEAN DEFAULT FALSE,
    redact_sensitive_data BOOLEAN DEFAULT TRUE,
    enabled_app_ids_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_insight_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id INT DEFAULT NULL,
    triggered_by BIGINT UNSIGNED DEFAULT NULL,
    insight_type VARCHAR(80) NOT NULL,
    input_summary TEXT DEFAULT NULL,
    output_text MEDIUMTEXT DEFAULT NULL,
    source_context_json JSON DEFAULT NULL,
    status ENUM('queued', 'completed', 'failed') NOT NULL DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE SET NULL,
    FOREIGN KEY (triggered_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_ai_insight_app (app_id),
    INDEX idx_ai_insight_type (insight_type),
    INDEX idx_ai_insight_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_assistant_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id INT DEFAULT NULL,
    triggered_by BIGINT UNSIGNED DEFAULT NULL,
    task_type VARCHAR(80) NOT NULL,
    input_text MEDIUMTEXT DEFAULT NULL,
    output_text MEDIUMTEXT DEFAULT NULL,
    status ENUM('queued', 'completed', 'failed') NOT NULL DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE SET NULL,
    FOREIGN KEY (triggered_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_ai_assistant_app (app_id),
    INDEX idx_ai_assistant_type (task_type),
    INDEX idx_ai_assistant_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
