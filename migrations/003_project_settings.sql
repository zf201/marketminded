-- +goose Up
CREATE TABLE IF NOT EXISTS project_settings (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    key TEXT NOT NULL,
    value TEXT NOT NULL DEFAULT '',
    UNIQUE(project_id, key)
);

-- +goose Down
DROP TABLE IF EXISTS project_settings;
