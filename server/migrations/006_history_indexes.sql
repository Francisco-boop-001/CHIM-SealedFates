-- CHIM owns public.eventlog. The named partial expression index is owned by
-- this plugin's migration and accelerates exact written-source replay checks.
-- EnsureSchema runs this under the existing writer serialization. Creating it
-- requires the runtime database role to own eventlog or be a member of its
-- owning role. The build scans matching legacy rows and fails closed on a
-- malformed marked payload.
CREATE INDEX IF NOT EXISTS correspondence_written_evidence_document_id_idx
    ON public.eventlog ((CASE
        WHEN type = 'info' AND sess = 'written_evidence_v1'
        THEN data::jsonb->>'document_id'
    END))
    WHERE type = 'info' AND sess = 'written_evidence_v1';
