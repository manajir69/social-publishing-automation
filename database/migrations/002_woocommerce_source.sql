ALTER TABLE social_pages
    ADD COLUMN woo_store_url VARCHAR(2048) NULL AFTER encrypted_access_token,
    ADD COLUMN encrypted_woo_consumer_key TEXT NULL AFTER woo_store_url,
    ADD COLUMN encrypted_woo_consumer_secret TEXT NULL AFTER encrypted_woo_consumer_key;

ALTER TABLE content_items
    ADD COLUMN source_type ENUM('manual','woocommerce') NOT NULL DEFAULT 'manual' AFTER content_type,
    ADD COLUMN external_id VARCHAR(100) NULL AFTER source_type,
    ADD COLUMN last_drafted_at DATETIME NULL AFTER status,
    ADD UNIQUE KEY uq_content_external (page_id, source_type, external_id),
    ADD INDEX idx_content_rotation (page_id, source_type, status, last_drafted_at);

