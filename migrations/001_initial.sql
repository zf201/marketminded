-- +goose Up
CREATE TABLE projects (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE profile_sections (
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

CREATE TABLE templates (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    platform TEXT NOT NULL CHECK(platform IN ('instagram','facebook','linkedin')),
    html_content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pipeline_runs (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'ideating'
        CHECK(status IN ('ideating','creating_pillar','waterfalling','complete','abandoned')),
    selected_topic TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE content_pieces (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    pipeline_run_id INTEGER REFERENCES pipeline_runs(id),
    type TEXT NOT NULL CHECK(type IN ('blog','social_instagram','social_facebook','social_linkedin')),
    title TEXT,
    body TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','approved','published')),
    parent_id INTEGER REFERENCES content_pieces(id),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE agent_runs (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    pipeline_run_id INTEGER REFERENCES pipeline_runs(id),
    agent_type TEXT NOT NULL CHECK(agent_type IN ('profile','idea','content')),
    prompt_summary TEXT,
    response TEXT NOT NULL,
    content_piece_id INTEGER REFERENCES content_pieces(id),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE brainstorm_chats (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title TEXT,
    section TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE brainstorm_messages (
    id INTEGER PRIMARY KEY,
    chat_id INTEGER NOT NULL REFERENCES brainstorm_chats(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK(role IN ('user','assistant')),
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

INSERT INTO settings (key, value) VALUES ('model_content', '');
INSERT INTO settings (key, value) VALUES ('model_ideation', '');

-- +goose Down
DROP TABLE settings;
DROP TABLE brainstorm_messages;
DROP TABLE brainstorm_chats;
DROP TABLE agent_runs;
DROP TABLE content_pieces;
DROP TABLE pipeline_runs;
DROP TABLE templates;
DROP TABLE profile_sections;
DROP TABLE projects;
