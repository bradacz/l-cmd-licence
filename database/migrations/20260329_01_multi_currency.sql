ALTER TABLE product_tariffs
    ADD COLUMN is_default BOOLEAN DEFAULT FALSE AFTER is_recommended;

UPDATE product_tariffs
SET currency = UPPER(currency);

UPDATE product_tariffs t
INNER JOIN (
    SELECT product_id, MIN(id) AS first_tariff_id
    FROM product_tariffs
    GROUP BY product_id
) seeded ON seeded.first_tariff_id = t.id
SET t.is_default = 1
WHERE t.is_default = 0;

ALTER TABLE product_tariffs
    ADD UNIQUE KEY uniq_product_tariff_currency (product_id, license_type, currency);
