ALTER TABLE social_pages
    ADD COLUMN folder_branding_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN folder_logo_path VARCHAR(500) NULL,
    ADD COLUMN folder_logo_size_pct TINYINT UNSIGNED NOT NULL DEFAULT 12,
    ADD COLUMN folder_logo_position VARCHAR(30) NOT NULL DEFAULT 'top-right',
    ADD COLUMN folder_brand_text VARCHAR(500) NULL,
    ADD COLUMN folder_text_position VARCHAR(30) NOT NULL DEFAULT 'bottom-center',
    ADD COLUMN folder_text_opacity TINYINT UNSIGNED NOT NULL DEFAULT 90,
    ADD COLUMN folder_text_size_pct TINYINT UNSIGNED NOT NULL DEFAULT 3;
