-- +goose Up
ALTER TABLE pipeline_runs ADD COLUMN brief TEXT NOT NULL DEFAULT '';

-- +goose Down
-- SQLite doesn't support DROP COLUMN easily, so we leave it
