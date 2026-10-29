ALTER TABLE products
    DROP INDEX slug,
    ADD UNIQUE KEY uniq_products_tenant_slug (tenant_id, slug);
