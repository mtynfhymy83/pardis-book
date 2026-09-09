-- SQLite / MySQL flavour. For PostgreSQL use SERIAL/BIGSERIAL or IDENTITY.
-- This file documents the schema; scripts/migrate.php applies a per-driver
-- version automatically.

CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      VARCHAR(200) NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
