ALTER TABLE social_pages
    ADD COLUMN folder_text_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    ADD COLUMN folder_text_background_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN folder_text_background_color CHAR(7) NOT NULL DEFAULT '#050F19';
