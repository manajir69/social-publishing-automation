ALTER TABLE social_connections
    ADD COLUMN feed_delay_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    ADD COLUMN weekly_post_limit TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN weekly_publish_weekday TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN weekly_publish_time TIME NOT NULL DEFAULT '10:00:00';

ALTER TABLE social_posts
    ADD COLUMN linkedin_scheduled_at DATETIME NULL,
    ADD COLUMN tiktok_scheduled_at DATETIME NULL;
