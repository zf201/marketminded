-- +goose Up
-- +goose NO TRANSACTION

PRAGMA foreign_keys = OFF;

CREATE TABLE pipeline_steps_new (
    id INTEGER PRIMARY KEY,
    pipeline_run_id INTEGER NOT NULL REFERENCES pipeline_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL CHECK(step_type IN ('research','brand_enricher','factcheck','tone_analyzer','editor','write','plan_waterfall')),
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','running','completed','failed')),
    input TEXT NOT NULL DEFAULT '',
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO pipeline_steps_new SELECT * FROM pipeline_steps;

DROP TABLE pipeline_steps;
ALTER TABLE pipeline_steps_new RENAME TO pipeline_steps;

PRAGMA foreign_keys = ON;

-- +goose Down
-- +goose NO TRANSACTION

PRAGMA foreign_keys = OFF;

CREATE TABLE pipeline_steps_new (
    id INTEGER PRIMARY KEY,
    pipeline_run_id INTEGER NOT NULL REFERENCES pipeline_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL CHECK(step_type IN ('research','brand_enricher','factcheck','tone_analyzer','write','plan_waterfall')),
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','running','completed','failed')),
    input TEXT NOT NULL DEFAULT '',
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO pipeline_steps_new SELECT * FROM pipeline_steps WHERE step_type != 'editor';

DROP TABLE pipeline_steps;
ALTER TABLE pipeline_steps_new RENAME TO pipeline_steps;

PRAGMA foreign_keys = ON;
