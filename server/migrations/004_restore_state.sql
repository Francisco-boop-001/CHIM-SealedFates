CREATE SCHEMA IF NOT EXISTS plugins;

CREATE TABLE IF NOT EXISTS plugins.correspondence_restore_state (
    singleton boolean PRIMARY KEY DEFAULT TRUE CHECK (singleton IS TRUE),
    epoch uuid NOT NULL,
    pending boolean NOT NULL DEFAULT TRUE,
    native_ts_floor bigint NOT NULL DEFAULT 0 CHECK (native_ts_floor >= 0),
    updated_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE plugins.correspondence_restore_state
    ADD COLUMN IF NOT EXISTS native_ts_floor bigint NOT NULL DEFAULT 0 CHECK (native_ts_floor >= 0);
