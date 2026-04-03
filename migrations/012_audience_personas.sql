-- +goose Up
CREATE TABLE audience_personas (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    description TEXT NOT NULL,
    pain_points TEXT NOT NULL,
    push TEXT NOT NULL,
    pull TEXT NOT NULL,
    anxiety TEXT NOT NULL,
    habit TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT '',
    demographics TEXT NOT NULL DEFAULT '',
    company_info TEXT NOT NULL DEFAULT '',
    content_habits TEXT NOT NULL DEFAULT '',
    buying_triggers TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- +goose Down
DROP TABLE audience_personas;
