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

INSERT INTO settings (key_name, value) VALUES
('contact_form_recipient_email', 'contact@example.invalid'),
('turnstile_site_key', ''),
('turnstile_secret_key', '')
ON DUPLICATE KEY UPDATE value = VALUES(value);
