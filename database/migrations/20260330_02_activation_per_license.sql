SET @drop_installation_hash_unique := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'license_activations'
              AND index_name = 'installation_hash'
              AND non_unique = 0
        ),
        'ALTER TABLE license_activations DROP INDEX installation_hash',
        'SELECT 1'
    )
);
PREPARE stmt FROM @drop_installation_hash_unique;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_license_hash_active_index := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'license_activations'
              AND index_name = 'idx_activation_license_hash_active'
        ),
        'SELECT 1',
        'ALTER TABLE license_activations ADD INDEX idx_activation_license_hash_active (license_id, installation_hash, is_active)'
    )
);
PREPARE stmt FROM @add_license_hash_active_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
