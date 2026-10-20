CREATE SCHEMA IF NOT EXISTS plugins;

CREATE TABLE IF NOT EXISTS plugins.correspondence_autonomy_settings (
    playthrough_id text PRIMARY KEY,
    enabled boolean NOT NULL DEFAULT FALSE,
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS plugins.correspondence_autonomy_decisions (
    playthrough_id text NOT NULL,
    source_document_id uuid NOT NULL,
    source_event_id bigint NOT NULL CHECK (source_event_id > 0),
    claim_id uuid NOT NULL,
    request_id uuid NOT NULL,
    root_document_id uuid NOT NULL,
    depth smallint NOT NULL CHECK (depth BETWEEN 0 AND 3),
    status text NOT NULL CHECK (status IN ('claimed','silent','queued','dispatched','failed','unknown','stale')),
    action text CHECK (action IS NULL OR action IN ('none','reply','forward','mediate')),
    child_document_id uuid,
    due_gamets numeric,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (playthrough_id, source_document_id),
    UNIQUE (playthrough_id, source_event_id),
    UNIQUE (playthrough_id, claim_id),
    UNIQUE (playthrough_id, request_id),
    UNIQUE (child_document_id)
);

CREATE INDEX IF NOT EXISTS correspondence_autonomy_due
    ON plugins.correspondence_autonomy_decisions (playthrough_id, due_gamets, created_at)
    WHERE status = 'queued';

CREATE INDEX IF NOT EXISTS correspondence_autonomy_actor_cooldown
    ON plugins.correspondence_autonomy_decisions (playthrough_id, (payload->>'self_id'), created_at DESC);
