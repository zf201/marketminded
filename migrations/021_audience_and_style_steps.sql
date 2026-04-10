-- +goose Up
-- +goose NO TRANSACTION

PRAGMA foreign_keys = OFF;

-- Rebuild pipeline_steps with audience_picker and style_reference in step_type CHECK.
CREATE TABLE pipeline_steps_new (
    id INTEGER PRIMARY KEY,
    pipeline_run_id INTEGER NOT NULL REFERENCES pipeline_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL CHECK(step_type IN ('research','brand_enricher','claim_verifier','tone_analyzer','audience_picker','editor','style_reference','write','plan_waterfall')),
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
    id, pipeline_run_id, step_type,
    status, input, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
FROM pipeline_steps;

DROP TABLE pipeline_steps;
ALTER TABLE pipeline_steps_new RENAME TO pipeline_steps;

PRAGMA foreign_keys = ON;

-- +goose Down
-- +goose NO TRANSACTION

PRAGMA foreign_keys = OFF;

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
    id, pipeline_run_id, step_type,
    status, input, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
FROM pipeline_steps
WHERE step_type NOT IN ('audience_picker', 'style_reference');

DROP TABLE pipeline_steps;
ALTER TABLE pipeline_steps_new RENAME TO pipeline_steps;

PRAGMA foreign_keys = ON;
