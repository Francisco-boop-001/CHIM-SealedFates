CREATE SCHEMA IF NOT EXISTS plugins;

CREATE TABLE IF NOT EXISTS plugins.correspondence_offscreen_observations (
    playthrough_id text PRIMARY KEY,
    native_type text NOT NULL,
    native_ts numeric NOT NULL CHECK (native_ts > 0),
    gamets numeric NOT NULL CHECK (gamets > 0),
    request_sha256 text NOT NULL CHECK (request_sha256 ~ '^[0-9a-f]{64}$'),
    interaction_generation integer,
    restore_generation text,
    boundary boolean NOT NULL DEFAULT FALSE,
    consumed boolean NOT NULL DEFAULT FALSE,
    last_consumed_gamets numeric NOT NULL DEFAULT 0 CHECK (last_consumed_gamets >= 0),
    anchor jsonb NOT NULL CHECK (jsonb_typeof(anchor) = 'object'),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS plugins.correspondence_offscreen_jobs (
    playthrough_id text NOT NULL,
    job_id uuid NOT NULL,
    trigger_event_id bigint NOT NULL CHECK (trigger_event_id > 0),
    actor_id bigint NOT NULL CHECK (actor_id > 0),
    source_message_id uuid,
    goal_hash text,
    claim_id uuid NOT NULL,
    status text NOT NULL CHECK (status IN ('claimed','sent','silent','failed','stale')),
    action text CHECK (action IS NULL OR action IN ('letter','reply','forward','mediate','none')),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (playthrough_id, job_id),
    UNIQUE (playthrough_id, trigger_event_id),
    UNIQUE (playthrough_id, source_message_id),
    UNIQUE (playthrough_id, claim_id)
);

CREATE INDEX IF NOT EXISTS correspondence_offscreen_actor_cooldown
    ON plugins.correspondence_offscreen_jobs (playthrough_id, actor_id, created_at DESC)
    WHERE status IN ('sent','silent');

CREATE TABLE IF NOT EXISTS plugins.correspondence_offscreen_mail (
    playthrough_id text NOT NULL,
    message_id uuid NOT NULL,
    sender_id bigint NOT NULL CHECK (sender_id > 0),
    recipient_id bigint NOT NULL CHECK (recipient_id > 0),
    root_message_id uuid NOT NULL,
    depth smallint NOT NULL CHECK (depth BETWEEN 0 AND 2),
    status text NOT NULL CHECK (status IN ('in_transit','arrived','exposed','stale')),
    body text NOT NULL CHECK (length(body) BETWEEN 1 AND 2000),
    created_gamets numeric NOT NULL CHECK (created_gamets > 0),
    due_gamets numeric NOT NULL CHECK (due_gamets > 0),
    arrived_gamets numeric CHECK (arrived_gamets IS NULL OR arrived_gamets > 0),
    source_event_id bigint,
    source_event_sha256 text,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (playthrough_id, message_id),
    CHECK ((source_event_id IS NULL) = (source_event_sha256 IS NULL)),
    CHECK (source_event_sha256 IS NULL OR source_event_sha256 ~ '^[0-9a-f]{64}$')
);

CREATE INDEX IF NOT EXISTS correspondence_offscreen_due
    ON plugins.correspondence_offscreen_mail (playthrough_id, due_gamets, created_gamets)
    WHERE status = 'in_transit';

CREATE INDEX IF NOT EXISTS correspondence_offscreen_inbox
    ON plugins.correspondence_offscreen_mail (playthrough_id, recipient_id, created_gamets)
    WHERE status = 'exposed';
