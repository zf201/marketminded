-- +goose Up
ALTER TABLE pipeline_steps ADD COLUMN usage TEXT NOT NULL DEFAULT '';
ALTER TABLE topic_steps ADD COLUMN usage TEXT NOT NULL DEFAULT '';

-- +goose Down
-- SQLite doesn't support DROP COLUMN before 3.35.0, so this is best-effort.
