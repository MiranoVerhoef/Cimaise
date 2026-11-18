CREATE TABLE IF NOT EXISTS album_layouts (
    album_id INT UNSIGNED NOT NULL PRIMARY KEY,
    draft_json MEDIUMTEXT NULL,
    published_json MEDIUMTEXT NULL,
    revision INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS album_layout_versions (
    album_id INT UNSIGNED NOT NULL,
    revision INTEGER NOT NULL,
    kind VARCHAR(16) NOT NULL,
    layout_json MEDIUMTEXT NOT NULL,
    saved_at DATETIME NULL,
    PRIMARY KEY (album_id, revision, kind),
    FOREIGN KEY (album_id) REFERENCES album_layouts(album_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
