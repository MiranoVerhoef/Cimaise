-- Migration 1.4.24 (SQLite) — per-album "show equipment" flag
--
-- albums.show_equipment controls whether the public album page renders the
-- equipment block (cameras, lenses, film, developers, labs). It is a
-- presentation flag only: the equipment metadata stays stored and keeps
-- feeding captions, filters and structured data. Existing albums default to 1
-- so nothing changes until the admin switches it off.
--
-- ALTER TABLE ... ADD COLUMN has no IF NOT EXISTS on SQLite; the migration
-- runner tolerates the "duplicate column" error on a re-run.

ALTER TABLE albums ADD COLUMN show_equipment INTEGER NOT NULL DEFAULT 1;
