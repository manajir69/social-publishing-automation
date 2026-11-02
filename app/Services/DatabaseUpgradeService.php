<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class DatabaseUpgradeService
{
    private const TABLES = [
        'blog_settings' => <<<'SQL'
CREATE TABLE `blog_settings` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'blog_articles' => <<<'SQL'
CREATE TABLE `blog_articles` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'blog_runs' => <<<'SQL'
CREATE TABLE `blog_runs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `blog_site_id` BIGINT UNSIGNED NULL,
    `result` ENUM('success','skipped','failed') NOT NULL,
    `message` TEXT NULL,
    `article_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_blog_runs_created` (`created_at`),
    KEY `idx_blog_runs_site_created` (`blog_site_id`,`created_at`),
    CONSTRAINT `fk_blog_run_article` FOREIGN KEY (`article_id`) REFERENCES `blog_articles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'notifications' => <<<'SQL'
CREATE TABLE `notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `page_id` BIGINT UNSIGNED NULL,
    `post_id` BIGINT UNSIGNED NULL,
    `type` VARCHAR(60) NOT NULL,
    `severity` ENUM('info','warning','error') NOT NULL DEFAULT 'warning',
    `title` VARCHAR(190) NOT NULL,
    `message` TEXT NOT NULL,
    `dedupe_key` VARCHAR(190) NOT NULL,
    `read_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_notifications_dedupe` (`dedupe_key`),
    KEY `idx_notifications_unread` (`read_at`,`created_at`),
    CONSTRAINT `fk_notification_page` FOREIGN KEY (`page_id`) REFERENCES `social_pages` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notification_post` FOREIGN KEY (`post_id`) REFERENCES `social_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'post_analytics' => <<<'SQL'
CREATE TABLE `post_analytics` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `post_id` BIGINT UNSIGNED NOT NULL,
    `platform` ENUM('facebook','instagram') NOT NULL,
    `reactions` INT UNSIGNED NOT NULL DEFAULT 0,
    `comments` INT UNSIGNED NOT NULL DEFAULT 0,
    `shares` INT UNSIGNED NOT NULL DEFAULT 0,
    `views` INT UNSIGNED NOT NULL DEFAULT 0,
    `engagement_score` INT UNSIGNED NOT NULL DEFAULT 0,
    `captured_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_post_analytics_platform` (`post_id`,`platform`),
    KEY `idx_analytics_score` (`platform`,`engagement_score`),
    CONSTRAINT `fk_analytics_post` FOREIGN KEY (`post_id`) REFERENCES `social_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'social_connections' => <<<'SQL'
CREATE TABLE `social_connections` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `page_id` BIGINT UNSIGNED NOT NULL,
    `platform` ENUM('linkedin','tiktok') NOT NULL,
    `display_name` VARCHAR(190) NOT NULL,
    `account_id` VARCHAR(190) NOT NULL,
    `encrypted_access_token` TEXT NULL,
    `encrypted_refresh_token` TEXT NULL,
    `token_expires_at` DATETIME NULL,
    `auto_publish_feed` TINYINT(1) NOT NULL DEFAULT 1,
    `auto_publish_video` TINYINT(1) NOT NULL DEFAULT 1,
    `default_privacy` VARCHAR(60) NOT NULL DEFAULT 'PUBLIC_TO_EVERYONE',
    `allow_comments` TINYINT(1) NOT NULL DEFAULT 1,
    `allow_duet` TINYINT(1) NOT NULL DEFAULT 1,
    `allow_stitch` TINYINT(1) NOT NULL DEFAULT 1,
    `caption_suffix` TEXT NULL,
    `feed_delay_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    `weekly_post_limit` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `weekly_publish_weekday` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `weekly_publish_time` TIME NOT NULL DEFAULT '10:00:00',
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_social_connection_account` (`platform`,`account_id`),
    UNIQUE KEY `uq_social_connection_page_platform` (`page_id`,`platform`),
    KEY `idx_social_connection_page` (`page_id`,`platform`,`status`),
    CONSTRAINT `fk_social_connection_page` FOREIGN KEY (`page_id`) REFERENCES `social_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];

    private const COLUMNS = [
        'social_pages' => [
            'woo_store_url' => 'VARCHAR(2048) NULL',
            'encrypted_woo_consumer_key' => 'TEXT NULL',
            'encrypted_woo_consumer_secret' => 'TEXT NULL',
            'woo_repeat_days' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 15',
            'woo_images_per_post' => 'TINYINT UNSIGNED NOT NULL DEFAULT 4',
            'woo_image_batch_mode' => "ENUM('half','fixed') NOT NULL DEFAULT 'half'",
            'woo_daily_mode' => "ENUM('off','draft','schedule') NOT NULL DEFAULT 'off'",
            'woo_posts_per_day' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
            'woo_post_time' => "TIME NOT NULL DEFAULT '10:00:00'",
            'woo_post_time_2' => "TIME NOT NULL DEFAULT '15:00:00'",
            'woo_post_time_3' => "TIME NOT NULL DEFAULT '20:00:00'",
            'woo_last_run_on' => 'DATE NULL',
            'folder_source_slug' => 'VARCHAR(64) NULL',
            'folder_daily_mode' => "ENUM('off','draft','schedule') NOT NULL DEFAULT 'off'",
            'folder_posts_per_day' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
            'folder_images_per_post' => 'TINYINT UNSIGNED NOT NULL DEFAULT 10',
            'folder_post_time' => "TIME NOT NULL DEFAULT '11:00:00'",
            'folder_post_time_2' => "TIME NOT NULL DEFAULT '16:00:00'",
            'folder_post_time_3' => "TIME NOT NULL DEFAULT '21:00:00'",
            'folder_last_run_on' => 'DATE NULL',
            'folder_branding_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'folder_logo_path' => 'VARCHAR(500) NULL',
            'folder_logo_size_pct' => 'TINYINT UNSIGNED NOT NULL DEFAULT 12',
            'folder_logo_position' => "VARCHAR(30) NOT NULL DEFAULT 'top-right'",
            'folder_brand_text' => 'VARCHAR(500) NULL',
            'folder_text_color' => "CHAR(7) NOT NULL DEFAULT '#FFFFFF'",
            'folder_text_background_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'folder_text_background_color' => "CHAR(7) NOT NULL DEFAULT '#050F19'",
            'folder_text_background_opacity' => 'TINYINT UNSIGNED NOT NULL DEFAULT 35',
            'folder_text_position' => "VARCHAR(30) NOT NULL DEFAULT 'bottom-center'",
            'folder_text_opacity' => 'TINYINT UNSIGNED NOT NULL DEFAULT 90',
            'folder_text_size_pct' => 'TINYINT UNSIGNED NOT NULL DEFAULT 3',
            'story_daily_mode' => "ENUM('off','schedule') NOT NULL DEFAULT 'off'",
            'story_source' => "ENUM('woocommerce','server_folder') NOT NULL DEFAULT 'server_folder'",
            'story_posts_per_day' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
            'story_post_time' => "TIME NOT NULL DEFAULT '09:00:00'",
            'story_post_time_2' => "TIME NOT NULL DEFAULT '15:00:00'",
            'story_post_time_3' => "TIME NOT NULL DEFAULT '21:00:00'",
            'story_last_run_on' => 'DATE NULL',
            'instagram_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'instagram_user_id' => 'VARCHAR(100) NULL',
            'encrypted_instagram_access_token' => 'TEXT NULL',
            'instagram_caption_max' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 500',
            'banned_words' => 'TEXT NULL',
            'post_instruction' => 'TEXT NULL',
            'caption_examples' => 'TEXT NULL',
        ],
        'content_items' => [
            'source_type' => "ENUM('manual','woocommerce') NOT NULL DEFAULT 'manual'",
            'external_id' => 'VARCHAR(100) NULL',
            'last_drafted_at' => 'DATETIME NULL',
            'woo_images_json' => 'MEDIUMTEXT NULL',
            'woo_next_image_index' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
            'woo_batch_images_json' => 'TEXT NULL',
            'folder_images_json' => 'MEDIUMTEXT NULL',
            'folder_used_images_json' => 'MEDIUMTEXT NULL',
            'folder_batch_images_json' => 'MEDIUMTEXT NULL',
            'folder_rotation_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        ],
        'social_posts' => [
            'image_urls_json' => 'TEXT NULL',
            'archived_at' => 'DATETIME NULL',
            'post_type' => "ENUM('feed','story') NOT NULL DEFAULT 'feed'",
            'title_fingerprint' => 'CHAR(64) NULL',
            'caption_fingerprint' => 'CHAR(64) NULL',
            'publish_to_instagram' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'instagram_caption' => 'TEXT NULL',
            'instagram_hashtags' => 'TEXT NULL',
            'instagram_media_id' => 'VARCHAR(190) NULL',
            'instagram_status' => "VARCHAR(20) NOT NULL DEFAULT 'off'",
            'instagram_error' => 'TEXT NULL',
            'facebook_published_at' => 'DATETIME NULL',
            'instagram_published_at' => 'DATETIME NULL',
            'analytics_checked_at' => 'DATETIME NULL',
            'video_path' => 'VARCHAR(500) NULL',
            'video_title' => 'VARCHAR(255) NULL',
            'publish_to_facebook' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'publish_to_linkedin' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'linkedin_status' => "VARCHAR(20) NOT NULL DEFAULT 'off'",
            'linkedin_post_id' => 'VARCHAR(255) NULL',
            'linkedin_error' => 'TEXT NULL',
            'linkedin_published_at' => 'DATETIME NULL',
            'publish_to_tiktok' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'tiktok_status' => "VARCHAR(20) NOT NULL DEFAULT 'off'",
            'tiktok_publish_id' => 'VARCHAR(255) NULL',
            'tiktok_error' => 'TEXT NULL',
            'tiktok_published_at' => 'DATETIME NULL',
            'linkedin_scheduled_at' => 'DATETIME NULL',
            'tiktok_scheduled_at' => 'DATETIME NULL',
        ],
        'social_connections' => [
            'allow_comments' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'allow_duet' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'allow_stitch' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'feed_delay_minutes' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 60',
            'weekly_post_limit' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
            'weekly_publish_weekday' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
            'weekly_publish_time' => "TIME NOT NULL DEFAULT '10:00:00'",
        ],
        'blog_articles' => [
            'blog_site_id' => 'BIGINT UNSIGNED NULL',
            'featured_media_id' => 'BIGINT UNSIGNED NULL',
            'tags_json' => 'TEXT NULL',
        ],
        'blog_settings' => [
            'site_name' => "VARCHAR(190) NOT NULL DEFAULT 'WordPress Site'",
        ],
        'blog_runs' => [
            'blog_site_id' => 'BIGINT UNSIGNED NULL',
        ],
    ];

    private const INDEXES = [
        'content_items' => [
            'uq_content_external' => 'UNIQUE KEY uq_content_external (page_id,source_type,external_id)',
            'idx_content_rotation' => 'KEY idx_content_rotation (page_id,source_type,status,last_drafted_at)',
        ],
        'social_posts' => [
            'idx_posts_archive' => 'KEY idx_posts_archive (archived_at,created_at)',
            'idx_posts_analytics' => 'KEY idx_posts_analytics (status,analytics_checked_at)',
            'idx_posts_fingerprints' => 'KEY idx_posts_fingerprints (page_id,title_fingerprint,caption_fingerprint)',
        ],
        'social_connections' => [
            'uq_social_connection_page_platform' => 'UNIQUE KEY uq_social_connection_page_platform (page_id,platform)',
        ],
        'blog_settings' => [
            'uq_blog_settings_url' => 'UNIQUE KEY uq_blog_settings_url (site_url(190))',
        ],
        'blog_articles' => [
            'uq_blog_site_focus' => 'UNIQUE KEY uq_blog_site_focus (blog_site_id,focus_keyphrase)',
            'idx_blog_articles_site_status' => 'KEY idx_blog_articles_site_status (blog_site_id,status)',
        ],
        'blog_runs' => [
            'idx_blog_runs_site_created' => 'KEY idx_blog_runs_site_created (blog_site_id,created_at)',
        ],
    ];

    public function __construct(private PDO $db) {}

    /** @return string[] */
    public function pending(): array
    {
        $pending = [];
        foreach (self::TABLES as $table => $_definition) {
            if (str_starts_with($table, 'blog_')) continue;
            if (!$this->tableExists($table)) $pending[] = 'table.' . $table;
        }
        foreach (self::COLUMNS as $table => $columns) {
            if (str_starts_with($table, 'blog_')) continue;
            foreach ($columns as $column => $_definition) {
                if (!$this->columnExists($table, $column)) $pending[] = $table . '.' . $column;
            }
        }
        foreach (self::INDEXES as $table => $indexes) {
            if (str_starts_with($table, 'blog_')) continue;
            foreach ($indexes as $index => $_definition) {
                if (!$this->indexExists($table, $index)) $pending[] = $table . '.' . $index;
            }
        }
        if (!$this->enumSupports('content_items', 'source_type', 'server_folder')) {
            $pending[] = 'content_items.source_type.server_folder';
        }
        if (!$this->enumSupports('social_pages', 'ai_provider', 'anthropic')) {
            $pending[] = 'social_pages.ai_provider.anthropic';
        }
        if (!$this->enumSupports('social_posts', 'post_type', 'reel')) $pending[] = 'social_posts.post_type.reel';
        return $pending;
    }

    /** @return string[] names of changes applied */
    public function run(): array
    {
        $applied = [];
        foreach (self::TABLES as $table => $definition) {
            if (str_starts_with($table, 'blog_')) continue;
            if ($this->tableExists($table)) continue;
            $this->db->exec($definition);
            $applied[] = 'table.' . $table;
        }
        foreach (self::COLUMNS as $table => $columns) {
            if (str_starts_with($table, 'blog_')) continue;
            foreach ($columns as $column => $definition) {
                if ($this->columnExists($table, $column)) continue;
                $this->db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
                $applied[] = $table . '.' . $column;
            }
        }
        foreach (self::INDEXES as $table => $indexes) {
            if (str_starts_with($table, 'blog_')) continue;
            foreach ($indexes as $index => $definition) {
                if ($this->indexExists($table, $index)) continue;
                $this->db->exec("ALTER TABLE `{$table}` ADD {$definition}");
                $applied[] = $table . '.' . $index;
            }
        }
        if (!$this->enumSupports('content_items', 'source_type', 'server_folder')) {
            $this->db->exec("ALTER TABLE `content_items` MODIFY COLUMN `source_type` ENUM('manual','woocommerce','server_folder') NOT NULL DEFAULT 'manual'");
            $applied[] = 'content_items.source_type.server_folder';
        }
        if (!$this->enumSupports('social_pages', 'ai_provider', 'anthropic')) {
            $this->db->exec("ALTER TABLE `social_pages` MODIFY COLUMN `ai_provider` ENUM('none','openai','anthropic','qwen') NOT NULL DEFAULT 'none'");
            $applied[] = 'social_pages.ai_provider.anthropic';
        }
        if (!$this->enumSupports('social_posts', 'post_type', 'reel')) {
            $this->db->exec("ALTER TABLE `social_posts` MODIFY COLUMN `post_type` ENUM('feed','story','video','reel') NOT NULL DEFAULT 'feed'");
            $applied[] = 'social_posts.post_type.reel';
        }
        return $applied;
    }

    private function columnExists(string $table, string $column): bool
    {
        $query = $this->db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $query->execute([$table, $column]);
        return (int)$query->fetchColumn() > 0;
    }

    private function tableExists(string $table): bool
    {
        $query = $this->db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]);
        return (int)$query->fetchColumn() > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        $query = $this->db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $query->execute([$table, $index]);
        return (int)$query->fetchColumn() > 0;
    }

    private function enumSupports(string $table, string $column, string $value): bool
    {
        $query = $this->db->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $query->execute([$table, $column]);
        $type = (string)$query->fetchColumn();
        return $type !== '' && str_contains($type, "'" . $value . "'");
    }

    private function columnIsAutoIncrement(string $table, string $column): bool
    {
        $query = $this->db->prepare('SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $query->execute([$table, $column]);
        return str_contains(strtolower((string)$query->fetchColumn()), 'auto_increment');
    }
}
