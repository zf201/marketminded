-- +goose Up
-- +goose NO TRANSACTION

-- SQLite doesn't support ALTER COLUMN, so recreate topic_runs with 'pending' status.
PRAGMA foreign_keys = OFF;

CREATE TABLE topic_runs_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    instructions TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','running','completed','failed')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO topic_runs_new (id, project_id, instructions, status, created_at, updated_at)
SELECT id, project_id, COALESCE(instructions,''), status, created_at, updated_at FROM topic_runs;

DROP TABLE topic_runs;
ALTER TABLE topic_runs_new RENAME TO topic_runs;

PRAGMA foreign_keys = ON;

-- +goose Down
-- +goose NO TRANSACTION

PRAGMA foreign_keys = OFF;

CREATE TABLE topic_runs_old (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    instructions TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'running' CHECK(status IN ('running','completed','failed')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO topic_runs_old (id, project_id, instructions, status, created_at, updated_at)
SELECT id, project_id, instructions,
    CASE WHEN status = 'pending' THEN 'running' ELSE status END,
    created_at, updated_at FROM topic_runs;

DROP TABLE topic_runs;
ALTER TABLE topic_runs_old RENAME TO topic_runs;

PRAGMA foreign_keys = ON;
