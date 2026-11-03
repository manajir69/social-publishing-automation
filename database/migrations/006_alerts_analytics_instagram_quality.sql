SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NULL,
    post_id BIGINT UNSIGNED NULL,
    type VARCHAR(60) NOT NULL,
    severity ENUM('info','warning','error') NOT NULL DEFAULT 'warning',
    title VARCHAR(190) NOT NULL,
    message TEXT NOT NULL,
    dedupe_key VARCHAR(190) NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notifications_dedupe (dedupe_key),
    KEY idx_notifications_unread (read_at,created_at),
    CONSTRAINT fk_notification_page FOREIGN KEY (page_id) REFERENCES social_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_post FOREIGN KEY (post_id) REFERENCES social_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_analytics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    platform ENUM('facebook','instagram') NOT NULL,
    reactions INT UNSIGNED NOT NULL DEFAULT 0,
    comments INT UNSIGNED NOT NULL DEFAULT 0,
    shares INT UNSIGNED NOT NULL DEFAULT 0,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    engagement_score INT UNSIGNED NOT NULL DEFAULT 0,
    captured_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_post_analytics_platform (post_id,platform),
    KEY idx_analytics_score (platform,engagement_score),
    CONSTRAINT fk_analytics_post FOREIGN KEY (post_id) REFERENCES social_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE social_pages
    ADD COLUMN instagram_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN instagram_user_id VARCHAR(100) NULL,
    ADD COLUMN encrypted_instagram_access_token TEXT NULL,
    ADD COLUMN instagram_caption_max SMALLINT UNSIGNED NOT NULL DEFAULT 500,
    ADD COLUMN banned_words TEXT NULL;

ALTER TABLE social_posts
    ADD COLUMN title_fingerprint CHAR(64) NULL,
    ADD COLUMN caption_fingerprint CHAR(64) NULL,
    ADD COLUMN publish_to_instagram TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN instagram_caption TEXT NULL,
    ADD COLUMN instagram_hashtags TEXT NULL,
    ADD COLUMN instagram_media_id VARCHAR(190) NULL,
    ADD COLUMN instagram_status VARCHAR(20) NOT NULL DEFAULT 'off',
    ADD COLUMN instagram_error TEXT NULL,
    ADD COLUMN facebook_published_at DATETIME NULL,
    ADD COLUMN instagram_published_at DATETIME NULL,
    ADD COLUMN analytics_checked_at DATETIME NULL,
    ADD KEY idx_posts_analytics (status,analytics_checked_at),
    ADD KEY idx_posts_fingerprints (page_id,title_fingerprint,caption_fingerprint);
