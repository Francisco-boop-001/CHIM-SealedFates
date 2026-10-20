CREATE SCHEMA IF NOT EXISTS plugins;
CREATE TABLE IF NOT EXISTS plugins.correspondence_documents (
    document_id uuid PRIMARY KEY,
    playthrough_id text NOT NULL,
    request_id uuid NOT NULL,
    payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object'),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (playthrough_id, request_id)
);
CREATE TABLE IF NOT EXISTS plugins.correspondence_operations (
    operation_id uuid PRIMARY KEY,
    document_id uuid NOT NULL REFERENCES plugins.correspondence_documents(document_id),
    payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object'),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS correspondence_documents_scope ON plugins.correspondence_documents(playthrough_id, created_at DESC);
CREATE TABLE IF NOT EXISTS plugins.correspondence_presence (
    playthrough_id text PRIMARY KEY,
    payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object')
);
