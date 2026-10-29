ALTER TABLE apps
    ADD COLUMN IF NOT EXISTS default_currency VARCHAR(3) DEFAULT NULL AFTER notification_email,
    ADD COLUMN IF NOT EXISTS enabled_currencies VARCHAR(32) DEFAULT NULL AFTER default_currency;
