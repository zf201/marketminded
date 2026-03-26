-- +goose Up
-- +goose NO TRANSACTION

-- Disable FK checks for table rebuild
PRAGMA foreign_keys = OFF;

-- Rebuild pipeline_runs to add phase column and update status CHECK
CREATE TABLE pipeline_runs_new (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    topic TEXT NOT NULL,
    brief TEXT,
    plan TEXT,
    phase TEXT NOT NULL DEFAULT 'cornerstone'
        CHECK(phase IN ('cornerstone','waterfall')),
    status TEXT NOT NULL DEFAULT 'producing'
        CHECK(status IN ('producing','complete','abandoned')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO pipeline_runs_new (id, project_id, topic, brief, plan, phase, status, created_at, updated_at)
    SELECT id, project_id, topic, brief, plan, 'cornerstone',
        CASE WHEN status = 'planning' THEN 'producing' ELSE status END,
        created_at, updated_at
    FROM pipeline_runs;

DROP TABLE pipeline_runs;
ALTER TABLE pipeline_runs_new RENAME TO pipeline_runs;

-- Re-enable FK checks
PRAGMA foreign_keys = ON;

-- Pipeline steps table
CREATE TABLE pipeline_steps (
    id INTEGER PRIMARY KEY,
    pipeline_run_id INTEGER NOT NULL REFERENCES pipeline_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL CHECK(step_type IN ('research','factcheck','write','plan_waterfall')),
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','running','completed','failed')),
    input TEXT NOT NULL DEFAULT '',
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- +goose Down
-- +goose NO TRANSACTION

PRAGMA foreign_keys = OFF;

DROP TABLE pipeline_steps;

CREATE TABLE pipeline_runs_new (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    topic TEXT NOT NULL,
    brief TEXT,
    plan TEXT,
    status TEXT NOT NULL DEFAULT 'planning'
        CHECK(status IN ('planning','producing','complete','abandoned')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO pipeline_runs_new (id, project_id, topic, brief, plan, status, created_at, updated_at)
    SELECT id, project_id, topic, brief, plan, status, created_at, updated_at
    FROM pipeline_runs;

DROP TABLE pipeline_runs;
ALTER TABLE pipeline_runs_new RENAME TO pipeline_runs;

PRAGMA foreign_keys = ON;
