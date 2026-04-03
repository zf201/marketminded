-- +goose Up
CREATE TABLE voice_tone_profiles (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    voice_analysis TEXT NOT NULL DEFAULT '',
    content_types TEXT NOT NULL DEFAULT '',
    should_avoid TEXT NOT NULL DEFAULT '',
    should_use TEXT NOT NULL DEFAULT '',
    style_inspiration TEXT NOT NULL DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id)
);

-- +goose Down
DROP TABLE voice_tone_profiles;
