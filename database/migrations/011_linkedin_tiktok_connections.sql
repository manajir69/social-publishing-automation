SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS social_connections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    platform ENUM('linkedin','tiktok') NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    account_id VARCHAR(190) NOT NULL,
    encrypted_access_token TEXT NULL,
    encrypted_refresh_token TEXT NULL,
    token_expires_at DATETIME NULL,
    auto_publish_feed TINYINT(1) NOT NULL DEFAULT 1,
    auto_publish_video TINYINT(1) NOT NULL DEFAULT 1,
    default_privacy VARCHAR(60) NOT NULL DEFAULT 'PUBLIC_TO_EVERYONE',
    allow_comments TINYINT(1) NOT NULL DEFAULT 1,
    allow_duet TINYINT(1) NOT NULL DEFAULT 1,
    allow_stitch TINYINT(1) NOT NULL DEFAULT 1,
    caption_suffix TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_social_connection_account (platform,account_id),
    UNIQUE KEY uq_social_connection_page_platform (page_id,platform),
    KEY idx_social_connection_page (page_id,platform,status),
    CONSTRAINT fk_social_connection_page FOREIGN KEY (page_id) REFERENCES social_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE social_posts
    ADD COLUMN publish_to_linkedin TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN linkedin_status VARCHAR(20) NOT NULL DEFAULT 'off',
    ADD COLUMN linkedin_post_id VARCHAR(255) NULL,
    ADD COLUMN linkedin_error TEXT NULL,
    ADD COLUMN linkedin_published_at DATETIME NULL,
    ADD COLUMN publish_to_tiktok TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN tiktok_status VARCHAR(20) NOT NULL DEFAULT 'off',
    ADD COLUMN tiktok_publish_id VARCHAR(255) NULL,
    ADD COLUMN tiktok_error TEXT NULL,
    ADD COLUMN tiktok_published_at DATETIME NULL;
