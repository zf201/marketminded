-- +goose Up
CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

INSERT INTO settings (key, value) VALUES ('model_content', '');
INSERT INTO settings (key, value) VALUES ('model_ideation', '');

-- +goose Down
DROP TABLE settings;
