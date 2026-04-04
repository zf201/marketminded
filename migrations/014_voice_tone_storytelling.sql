-- +goose Up
ALTER TABLE voice_tone_profiles ADD COLUMN storytelling_frameworks TEXT NOT NULL DEFAULT '[]';
ALTER TABLE voice_tone_profiles ADD COLUMN preferred_length INTEGER NOT NULL DEFAULT 1500;

DELETE FROM project_settings WHERE key = 'storytelling_framework';

-- +goose Down
-- SQLite doesn't support DROP COLUMN, but goose down is for dev only
