SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `blog_settings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `site_name` VARCHAR(190) NOT NULL,
    `site_url` VARCHAR(2048) NOT NULL,
    `wp_username` VARCHAR(190) NOT NULL,
    `encrypted_wp_app_password` TEXT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `frequency_days` SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    `post_time` TIME NOT NULL DEFAULT '03:00:00',
    `publish_mode` ENUM('draft','publish') NOT NULL DEFAULT 'draft',
    `ai_model` VARCHAR(120) NOT NULL DEFAULT 'gpt-5-mini',
    `encrypted_ai_api_key` TEXT NULL,
    `topics` MEDIUMTEXT NULL,
    `system_prompt` MEDIUMTEXT NULL,
    `category_id` BIGINT UNSIGNED NULL,
    `featured_media_id` BIGINT UNSIGNED NULL,
    `last_run_on` DATE NULL,
    `last_success_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_blog_settings_url` (`site_url`(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blog_articles` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `blog_site_id` BIGINT UNSIGNED NULL,
    `topic` VARCHAR(500) NOT NULL,
    `focus_keyphrase` VARCHAR(190) NOT NULL,
    `title` VARCHAR(500) NOT NULL,
    `slug` VARCHAR(250) NOT NULL,
    `excerpt` TEXT NULL,
    `content_html` MEDIUMTEXT NOT NULL,
    `seo_title` VARCHAR(500) NULL,
    `meta_description` VARCHAR(500) NULL,
    `image_alt` VARCHAR(500) NULL,
    `featured_media_id` BIGINT UNSIGNED NULL,
    `sources_json` MEDIUMTEXT NULL,
    `internal_links_json` MEDIUMTEXT NULL,
    `tags_json` TEXT NULL,
    `wp_post_id` BIGINT UNSIGNED NULL,
    `wp_post_url` VARCHAR(2048) NULL,
    `wp_status` VARCHAR(30) NULL,
    `seo_score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `seo_checks_json` MEDIUMTEXT NULL,
    `status` ENUM('draft','published','failed') NOT NULL DEFAULT 'draft',
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `published_at` DATETIME NULL,
    UNIQUE KEY `uq_blog_site_focus` (`blog_site_id`,`focus_keyphrase`),
    KEY `idx_blog_articles_site_status` (`blog_site_id`,`status`),
    KEY `idx_blog_articles_created` (`created_at`),
    KEY `idx_blog_articles_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blog_runs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `blog_site_id` BIGINT UNSIGNED NULL,
    `result` ENUM('success','skipped','failed') NOT NULL,
    `message` TEXT NULL,
    `article_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_blog_runs_created` (`created_at`),
    KEY `idx_blog_runs_site_created` (`blog_site_id`,`created_at`),
    CONSTRAINT `fk_blog_run_article` FOREIGN KEY (`article_id`) REFERENCES `blog_articles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
