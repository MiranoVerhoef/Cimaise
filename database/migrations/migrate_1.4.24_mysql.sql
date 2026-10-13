-- Migration 1.4.24 (MySQL / MariaDB) — per-album "show equipment" flag
--
-- albums.show_equipment controls whether the public album page renders the
-- equipment block (cameras, lenses, film, developers, labs). It is a
-- presentation flag only: the equipment metadata stays stored and keeps
-- feeding captions, filters and structured data. Existing albums default to 1
-- so nothing changes until the admin switches it off.
--
-- No "ADD COLUMN IF NOT EXISTS": it is MariaDB-only and MySQL 5.7 rejects it.
-- The migration runner records each version once and tolerates the
-- "Duplicate column name" error on a re-run.

ALTER TABLE `albums` ADD COLUMN `show_equipment` TINYINT(1) NOT NULL DEFAULT 1 AFTER `show_date`;
