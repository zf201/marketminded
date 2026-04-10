-- +goose Up
-- +goose NO TRANSACTION

PRAGMA foreign_keys = OFF;

-- Rebuild pipeline_steps with claim_verifier in step_type CHECK,
-- then rename existing factcheck rows.
CREATE TABLE pipeline_steps_new (
    id INTEGER PRIMARY KEY,
    pipeline_run_id INTEGER NOT NULL REFERENCES pipeline_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL CHECK(step_type IN ('research','brand_enricher','claim_verifier','tone_analyzer','editor','write','plan_waterfall')),
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','running','completed','failed')),
    input TEXT NOT NULL DEFAULT '',
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    tool_calls TEXT NOT NULL DEFAULT '',
    usage TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO pipeline_steps_new (
    id, pipeline_run_id, step_type, status, input, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
)
SELECT
    id, pipeline_run_id,
    CASE step_type WHEN 'factcheck' THEN 'claim_verifier' ELSE step_type END,
    status, input, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
FROM pipeline_steps;

DROP TABLE pipeline_steps;
ALTER TABLE pipeline_steps_new RENAME TO pipeline_steps;

PRAGMA foreign_keys = ON;

INSERT OR IGNORE INTO settings (key, value) VALUES ('claim_verifier_enabled', 'false');

-- +goose Down
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
    tool_calls TEXT NOT NULL DEFAULT '',
    usage TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO pipeline_steps_new (
    id, pipeline_run_id, step_type, status, input, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
)
SELECT
    id, pipeline_run_id,
    CASE step_type WHEN 'claim_verifier' THEN 'factcheck' ELSE step_type END,
    status, input, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
FROM pipeline_steps;

DROP TABLE pipeline_steps;
ALTER TABLE pipeline_steps_new RENAME TO pipeline_steps;

PRAGMA foreign_keys = ON;

DELETE FROM settings WHERE key = 'claim_verifier_enabled';
