SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_name VARCHAR(190) NOT NULL,
    facebook_page_id VARCHAR(100) NOT NULL UNIQUE,
    encrypted_access_token TEXT NULL,
    ai_provider ENUM('none','openai','anthropic','qwen') NOT NULL DEFAULT 'none',
    ai_model VARCHAR(120) NULL,
    encrypted_ai_api_key TEXT NULL,
    ai_prompt TEXT NULL,
    default_hashtags TEXT NULL,
    post_instruction TEXT NULL,
    caption_examples TEXT NULL,
    folder_branding_enabled TINYINT(1) NOT NULL DEFAULT 0,
    folder_logo_path VARCHAR(500) NULL,
    folder_logo_size_pct TINYINT UNSIGNED NOT NULL DEFAULT 12,
    folder_logo_position VARCHAR(30) NOT NULL DEFAULT 'top-right',
    folder_brand_text VARCHAR(500) NULL,
    folder_text_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    folder_text_background_enabled TINYINT(1) NOT NULL DEFAULT 1,
    folder_text_background_color CHAR(7) NOT NULL DEFAULT '#050F19',
    folder_text_position VARCHAR(30) NOT NULL DEFAULT 'bottom-center',
    folder_text_opacity TINYINT UNSIGNED NOT NULL DEFAULT 90,
    folder_text_size_pct TINYINT UNSIGNED NOT NULL DEFAULT 3,
    posting_frequency VARCHAR(100) NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Dhaka',
    auto_publish TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_social_pages_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    content_type ENUM('product','service') NOT NULL DEFAULT 'product',
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    price VARCHAR(100) NULL,
    features TEXT NULL,
    category VARCHAR(190) NULL,
    source_url VARCHAR(2048) NULL,
    image_url VARCHAR(2048) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_content_page FOREIGN KEY (page_id) REFERENCES social_pages(id) ON DELETE CASCADE,
    INDEX idx_content_page_status (page_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    content_item_id BIGINT UNSIGNED NULL,
    caption TEXT NOT NULL,
    hashtags TEXT NULL,
    call_to_action TEXT NULL,
    image_url VARCHAR(2048) NOT NULL,
    video_path VARCHAR(500) NULL,
    video_title VARCHAR(255) NULL,
    publish_to_facebook TINYINT(1) NOT NULL DEFAULT 1,
    scheduled_at DATETIME NULL,
    published_at DATETIME NULL,
    facebook_post_id VARCHAR(190) NULL,
    status ENUM('draft','approved','scheduled','publishing','published','failed','cancelled') NOT NULL DEFAULT 'draft',
    error_message TEXT NULL,
    retry_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_post_page FOREIGN KEY (page_id) REFERENCES social_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_post_content FOREIGN KEY (content_item_id) REFERENCES content_items(id) ON DELETE SET NULL,
    INDEX idx_posts_due (status, scheduled_at),
    INDEX idx_posts_page (page_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publishing_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    attempt_number SMALLINT UNSIGNED NOT NULL,
    result ENUM('success','temporary_failure','permanent_failure') NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    response_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attempt_post FOREIGN KEY (post_id) REFERENCES social_posts(id) ON DELETE CASCADE,
    INDEX idx_attempt_post (post_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id BIGINT UNSIGNED NULL,
    details TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at),
    CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
