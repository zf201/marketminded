-- +goose Up

-- Add source_urls column (JSON array of {url, notes} objects)
ALTER TABLE profile_sections ADD COLUMN source_urls TEXT NOT NULL DEFAULT '[]';

-- Version history table (capped at 5 per section in application code)
CREATE TABLE profile_section_versions (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    section TEXT NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Remove the CHECK constraint by recreating the table without it.
-- SQLite does not support ALTER TABLE DROP CONSTRAINT.
CREATE TABLE profile_sections_new (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    section TEXT NOT NULL,
    content TEXT NOT NULL DEFAULT '',
    source_urls TEXT NOT NULL DEFAULT '[]',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id, section)
);

INSERT INTO profile_sections_new (id, project_id, section, content, updated_at)
SELECT id, project_id, section, content, updated_at FROM profile_sections;

DROP TABLE profile_sections;
ALTER TABLE profile_sections_new RENAME TO profile_sections;

-- Delete content_strategy and guidelines sections
DELETE FROM profile_sections WHERE section IN ('content_strategy', 'guidelines');

-- Delete brainstorm chats for profile sections (they're being removed)
DELETE FROM brainstorm_messages WHERE chat_id IN (
    SELECT id FROM brainstorm_chats WHERE section IN (
        'product_and_positioning', 'audience', 'voice_and_tone',
        'content_strategy', 'guidelines'
    )
);
DELETE FROM brainstorm_chats WHERE section IN (
    'product_and_positioning', 'audience', 'voice_and_tone',
    'content_strategy', 'guidelines'
);

-- +goose Down
DROP TABLE profile_section_versions;

CREATE TABLE profile_sections_old (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    section TEXT NOT NULL CHECK(section IN (
        'product_and_positioning','audience','voice_and_tone',
        'content_strategy','guidelines'
    )),
    content TEXT NOT NULL DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id, section)
);

INSERT INTO profile_sections_old (id, project_id, section, content, updated_at)
SELECT id, project_id, section, content, updated_at FROM profile_sections
WHERE section IN ('product_and_positioning','audience','voice_and_tone','content_strategy','guidelines');

DROP TABLE profile_sections;
ALTER TABLE profile_sections_old RENAME TO profile_sections;
