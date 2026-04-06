-- +goose Up

-- Coerce any stuck 'running' rows to 'failed'.
-- The app code no longer writes 'running' to the DB, so these rows
-- would otherwise be orphaned. The CHECK constraints still technically
-- allow 'running' in the old schema, but no code path will ever set it.
UPDATE pipeline_steps SET status = 'failed', output = '', thinking = '', tool_calls = '' WHERE status = 'running';
UPDATE topic_steps SET status = 'failed', output = '', thinking = '', tool_calls = '' WHERE status = 'running';
UPDATE topic_runs SET status = 'failed' WHERE status = 'running';

-- +goose Down

-- No-op: we can't un-fail rows that were stuck as running.
