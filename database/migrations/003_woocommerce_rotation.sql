ALTER TABLE social_pages
    ADD COLUMN woo_repeat_days SMALLINT UNSIGNED NOT NULL DEFAULT 15 AFTER encrypted_woo_consumer_secret,
    ADD COLUMN woo_images_per_post TINYINT UNSIGNED NOT NULL DEFAULT 4 AFTER woo_repeat_days,
    ADD COLUMN woo_daily_mode ENUM('off','draft','schedule') NOT NULL DEFAULT 'off' AFTER woo_images_per_post,
    ADD COLUMN woo_posts_per_day TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER woo_daily_mode,
    ADD COLUMN woo_post_time TIME NOT NULL DEFAULT '10:00:00' AFTER woo_posts_per_day,
    ADD COLUMN woo_post_time_2 TIME NOT NULL DEFAULT '15:00:00' AFTER woo_post_time,
    ADD COLUMN woo_post_time_3 TIME NOT NULL DEFAULT '20:00:00' AFTER woo_post_time_2,
    ADD COLUMN woo_last_run_on DATE NULL AFTER woo_post_time_3;

ALTER TABLE content_items
    ADD COLUMN woo_images_json MEDIUMTEXT NULL AFTER image_url,
    ADD COLUMN woo_next_image_index SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER woo_images_json,
    ADD COLUMN woo_batch_images_json TEXT NULL AFTER woo_next_image_index;

ALTER TABLE social_posts
    ADD COLUMN image_urls_json TEXT NULL AFTER image_url,
    ADD COLUMN archived_at DATETIME NULL AFTER locked_at,
    ADD INDEX idx_posts_archive (archived_at, created_at);
