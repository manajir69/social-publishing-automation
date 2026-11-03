ALTER TABLE social_pages
    ADD COLUMN woo_image_batch_mode ENUM('half','fixed') NOT NULL DEFAULT 'half',
    ADD COLUMN folder_source_slug VARCHAR(64) NULL,
    ADD COLUMN folder_daily_mode ENUM('off','draft','schedule') NOT NULL DEFAULT 'off',
    ADD COLUMN folder_posts_per_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN folder_images_per_post TINYINT UNSIGNED NOT NULL DEFAULT 10,
    ADD COLUMN folder_post_time TIME NOT NULL DEFAULT '11:00:00',
    ADD COLUMN folder_post_time_2 TIME NOT NULL DEFAULT '16:00:00',
    ADD COLUMN folder_post_time_3 TIME NOT NULL DEFAULT '21:00:00',
    ADD COLUMN folder_last_run_on DATE NULL;

ALTER TABLE content_items
    MODIFY COLUMN source_type ENUM('manual','woocommerce','server_folder') NOT NULL DEFAULT 'manual',
    ADD COLUMN folder_images_json MEDIUMTEXT NULL,
    ADD COLUMN folder_used_images_json MEDIUMTEXT NULL,
    ADD COLUMN folder_batch_images_json MEDIUMTEXT NULL;
