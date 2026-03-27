-- +goose Up
ALTER TABLE pipeline_steps ADD COLUMN tool_calls TEXT NOT NULL DEFAULT '';

-- +goose Down
ALTER TABLE pipeline_steps DROP COLUMN tool_calls;
