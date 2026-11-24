-- Optional visual album layouts: one row per album, draft and published JSON,
-- optimistic locking through `revision`.
CREATE TABLE IF NOT EXISTS `album_layouts` (
  `album_id` INT UNSIGNED NOT NULL,
  `draft_json` MEDIUMTEXT NULL,
  `published_json` MEDIUMTEXT NULL,
  `revision` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`album_id`),
  CONSTRAINT `fk_album_layouts_album` FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing fork installations already have the layout data. Add the new columns in place.
ALTER TABLE album_layouts ADD COLUMN updated_at DATETIME DEFAULT NULL;
ALTER TABLE album_layouts ADD COLUMN published_at DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS album_layout_versions (
    album_id INT UNSIGNED NOT NULL,
    revision INTEGER NOT NULL,
    kind VARCHAR(16) NOT NULL,
    layout_json MEDIUMTEXT NOT NULL,
    saved_at DATETIME NULL,
    PRIMARY KEY (album_id, revision, kind),
    FOREIGN KEY (album_id) REFERENCES album_layouts(album_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
