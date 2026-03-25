-- +goose Up
ALTER TABLE brainstorm_messages ADD COLUMN thinking TEXT DEFAULT '';

-- +goose Down
ALTER TABLE brainstorm_messages DROP COLUMN thinking;
