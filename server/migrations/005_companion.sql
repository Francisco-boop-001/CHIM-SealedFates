CREATE SCHEMA IF NOT EXISTS plugins;

CREATE TABLE IF NOT EXISTS plugins.correspondence_companion_poll (
    playthrough_id text PRIMARY KEY,
    epoch uuid NOT NULL,
    observation_id uuid,
    nonce text,
    actors jsonb NOT NULL DEFAULT '[]'::jsonb,
    issued_gamets numeric,
    issued_at timestamptz,
    last_poll_gamets numeric,
    cursor_id bigint NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS plugins.correspondence_companion_episode (
    playthrough_id text NOT NULL,
    epoch uuid NOT NULL,
    actor_id bigint NOT NULL CHECK (actor_id > 0),
    episode_id uuid NOT NULL,
    first_game_minute bigint NOT NULL,
    native_first_game_minute bigint NOT NULL,
    game_minute bigint NOT NULL,
    sample_gamets numeric NOT NULL,
    identity jsonb NOT NULL CHECK (jsonb_typeof(identity) = 'object'),
    PRIMARY KEY (playthrough_id, actor_id)
);

CREATE TABLE IF NOT EXISTS plugins.correspondence_companion_decision (
    playthrough_id text NOT NULL,
    epoch uuid NOT NULL,
    actor_id bigint NOT NULL CHECK (actor_id > 0),
    episode_id uuid NOT NULL,
    request_id uuid NOT NULL,
    attempted boolean NOT NULL DEFAULT FALSE,
    status text NOT NULL CHECK (status IN ('claimed','silent','failed','queued','dispatched','stale','unknown')),
    document_id uuid,
    due_gamets numeric,
    claimed_gamets numeric NOT NULL,
    reason_code text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (playthrough_id, epoch, actor_id, episode_id),
    UNIQUE (request_id),
    UNIQUE (document_id)
);

CREATE INDEX IF NOT EXISTS correspondence_companion_due ON plugins.correspondence_companion_decision(playthrough_id,due_gamets) WHERE status='queued';
