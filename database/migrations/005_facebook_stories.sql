ALTER TABLE social_pages
    ADD COLUMN story_daily_mode ENUM('off','schedule') NOT NULL DEFAULT 'off',
    ADD COLUMN story_source ENUM('woocommerce','server_folder') NOT NULL DEFAULT 'server_folder',
    ADD COLUMN story_posts_per_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN story_post_time TIME NOT NULL DEFAULT '09:00:00',
    ADD COLUMN story_post_time_2 TIME NOT NULL DEFAULT '15:00:00',
    ADD COLUMN story_post_time_3 TIME NOT NULL DEFAULT '21:00:00',
    ADD COLUMN story_last_run_on DATE NULL;

ALTER TABLE social_posts
    ADD COLUMN post_type ENUM('feed','story') NOT NULL DEFAULT 'feed' AFTER content_item_id;
