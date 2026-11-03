ALTER TABLE social_posts
    ADD COLUMN video_path VARCHAR(500) NULL,
    ADD COLUMN video_title VARCHAR(255) NULL,
    ADD COLUMN publish_to_facebook TINYINT(1) NOT NULL DEFAULT 1,
    MODIFY COLUMN post_type ENUM('feed','story','video','reel') NOT NULL DEFAULT 'feed';
