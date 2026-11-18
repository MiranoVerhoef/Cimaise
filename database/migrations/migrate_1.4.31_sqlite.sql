CREATE TABLE IF NOT EXISTS album_layouts (
    album_id INTEGER NOT NULL PRIMARY KEY,
    draft_json TEXT NULL,
    published_json TEXT NULL,
    revision INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS album_layout_versions (
    album_id INTEGER NOT NULL,
    revision INTEGER NOT NULL,
    kind VARCHAR(16) NOT NULL,
    layout_json TEXT NOT NULL,
    saved_at DATETIME NULL,
    PRIMARY KEY (album_id, revision, kind),
    FOREIGN KEY (album_id) REFERENCES album_layouts(album_id) ON DELETE CASCADE
);
