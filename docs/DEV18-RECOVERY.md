# Dev.18 recovery release — PRE-ALPHA

The courier can now survive the scandalous event of somebody else speaking while he is doing paperwork. This candidate changes recovery and validation; it does not add another correspondence system.

## What changed

- F-01/F-03: ordinary newer events and outlier timestamps no longer invalidate valid work. Completion keeps the original playthrough, restore epoch, claim, source/body, settings and actor/context checks. It does not compare against global `MAX(eventlog.ts)`.
- F-02: a transient database failure retries the same parsed result once, after rereading its claim or verified completion. No second model request or physical delivery is made by that retry. An unavailable completion interaction lock gets an anchored, effect-free terminal state with the existing single reconsideration allowance. Untrusted claims are quarantined by exact claim ID without inventing completion evidence.
- F-05: the actual sender is included before the 32-contact cap. Case-folded namesakes cannot displace that sender or poison the self identity. Message construction happens before claiming; unsafe candidates defer without consuming a model call or retiring their letter. The existing bounded candidate scan remains.
- F-04: receipt status and reason enums accept ASCII recasing. Identity, scope, correlation, signed observations and transfer invariants remain strict. Missing native dependencies, malformed input and untrusted journals still cannot manufacture a clean failure or permission to resend. See recovery instructions in INSTALL.md.
- F-06: the documented package CLI now selects its exact source-bound PEX from the manifest when --pex is omitted, matching the release builder. Explicit PEX overrides still undergo the same verification.
- R-4: unauthenticated loopback access also requires a loopback Host. POSTs with an Origin must match the trusted server scheme/host/port; cross-site Fetch Metadata is refused. The session CSRF token remains mandatory. Authenticated operator access remains available.

## Restore boundary

Migration 004 adds one plugin-owned epoch/pending/native-floor row. It does not change core tables or add an eventlog index. A durable local marker accompanies that row under `log/playthrough_runtime/correspondence.restore.json`. Pending, missing or inconsistent authority fails closed; a missing row with a surviving marker reconstructs pending.

A supported init rotates the epoch before restore. Clearance requires the successful dedicated restore fence, an exact post-prune init marker, normal completed-phase evidence, a valid native clock, and no observable fatal, `pgr_sql_failed` or `pgr_skip_rollback` condition. A failed fence continues CHIM's init and leaves correspondence quarantined. A later successful init can recover it; elapsed time alone cannot clear quarantine.

The native floor rejects packets older than or equal to that init. The pinned CHIM client uses nanosecond `high_resolution_clock` counts, and the inspected MSVC toolchain maps that clock to `steady_clock`; these are boot-scoped counts, not Unix time. A new init resets the floor. An equal-clock packet is conservatively refused until the next native event. Core Background Life's separate synthetic CLI input does not traverse this native hook path.

Readers acquire their shared gate before opening a repeatable-read, read-only snapshot. Writers retain the advisory, eventlog and targeted identity locks. A failed shared unlock closes the dedicated plugin connection and fails the operation.

## Verification

All 21 integrated PostgreSQL jobs and 23 pure PHP suites (25 cases) pass from the final frozen `followup11` stage on exclusively leased disposable TEST. Its source-bound manifest and per-job receipts are retained under `artifacts/chim-sealed-fates-critique-dev18-20261020/`; `verification-final.json` identifies the accepted receipts. Host checks also passed: 76 PHP syntax checks, 60 translated bridge parser checks, 41 fake-native state checks and 28 package checks in each public/canonical layout. The owned cluster stopped, TEST was explicitly released, and protected live-VHD size/mtime matched preflight (metadata guard only). Seven immutable dev.17 baseline cases reached their intended failure markers. Setup errors and refused duplicate job attempts are preserved and excluded from regression proof.

The bridge source and anonymous PEX are unchanged from dev.17; the original build descriptor and compile evidence remain intact. Validator/control-flow checks use translated source and fake native boundaries, not Skyrim inventory or co-save execution.

## Limits and compatibility

The existing web plugin page works; the Department Skyrim interface is optional. Published Department prealpha.1 remains pinned to dev.17. Dev.18 needs a separate owner-reviewed adapter candidate: exact pins, the signed-unknown receipt branch, and restore lifecycle participation on both reads and writes. A pin-only update is insufficient. Constructor-only stores and request-bound stores missing a captured token now fail closed. The production bootstrap initializes schema and captures ingress; external read-only pre-commit callers must not silently migrate or create state. This Sealed Fates publication does not certify dev.18 Department compatibility.

A persistent database outage cannot durably close a claim or retain a model result. Bounded in-request retry does not become a durable response queue, and ambiguous physical outcomes remain unknown. If both filesystem and database quarantine persistence fail, an optional plugin cannot guarantee cross-process isolation while also allowing core init to continue. Fix the failed prerequisites and perform a successful init.

Core may catch and log some relationship-restore failures without exposing a failure flag; this boundary proves the supported completion phase, not every internal core SQL operation. Optional social consumers validate their own canonical sources and restore policies. Their enqueue calls remain outside the Sealed Fates transaction because S&S requires an idle connection; this is not cross-plugin atomicity or a deployed pairing certificate.

No live/game/core/provider change, model-quality evaluation, campaign-scale performance claim or retention pruning is included. The published dev.17 source/tag/assets and historical evidence remain unchanged.
