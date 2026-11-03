ALTER TABLE content_items
    ADD COLUMN folder_rotation_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER folder_batch_images_json;
