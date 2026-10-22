# Dev.22 recovery changes

The courier keeps the letter when an NPC's paperwork is temporarily unreadable. He is also no longer allowed to click his own Retry button. Management regrets that this required a software release.

## Report assessment

| Report | Assessment | Change / evidence |
| --- | --- | --- |
| 1: due arrival retired on unreadable settings | Agree | Defer the intact transit row to a fixed game-time recheck; later valid deliveries can pass it. Explicit death/opt-out still stops delivery. `offscreen_bughunt_dev22_pg.php arrival`. |
| 2: manual Retry strands an autonomous child | Agree | Hide Retry for scheduler-owned children and reject forged retry POSTs through the shared manual-dispatch guard. Ordinary manual retries retain their existing rules. `server_autonomy_ui.php`. |
| 3: temporary source read failure loses a queued reply | Agree | Preserve the exact queued decision/child for the established transient identity/read reasons; resume the same child without another model call. `autonomy_accounting_pg.php queued-source`. |
| 4: failed attempt write leaves an unattempted claim | Agree, with a proof limit | The live caller conditionally releases only its own matching, childless claim before invoking the callback, retaining an audit anchor. Historical zero counters cannot prove no attempt and do not authorize replay. `autonomy_accounting_pg.php attempt-write`. |
| 5: restored initiation claims remain open | Agree | Apply foreign-generation cleanup to initiation as well as reaction claims. A source-less initiation closes inertly, without a fabricated letter or model call. `offscreen_bughunt_dev22_pg.php restore-initiation`; existing liveness coverage also drains 17 old claims before one reconsidered reply. |
| 6: ineligible sender's exposed mail disappears from prompts | Confirmed policy, no eligibility change | Death or explicit Background Life/Letters opt-out suppresses current prompt exposure. Full stored letters and original audit evidence remain; restored eligibility allows the same valid letter again. `offscreen_bughunt_dev22_pg.php eligibility`. |
| 7: renderer refusal leaves a phantom queued compose | Agree | The web compose-and-dispatch operation is atomic in database storage. A renderer refusal leaves no document/operation/command; a missing executor still creates a legitimate queued letter. Scheduler staging remains available. `server_compose_atomic.php`. |
| 8: historical validation under the writer lock scales poorly | Agree, bounded improvement | Validate at most 16 rotating live-mail rows per observation, persist scheduling progress, and exclude foreign-generation jobs before cooldown proof checks. Every consumed source still validates its complete anchors and body. `offscreen_bughunt_dev22_pg.php sweep`. |
| 9: a status probe can race supervisor startup | Confirmed by a process regression | Serialize short startup/status probes with the existing signal mutex; the long-lived supervisor lock stays nonblocking, so duplicate supervisors still refuse promptly. `server_offscreen_lock_race.php`. |

A fresh bughunt found that a corrupt due/arrived candidate beyond the bounded sweep could roll back maintenance progress. Selection now quarantines invalid candidates and commits a metadata-only result without reaching the model. Two additional regressions exercise both lanes behind 16 valid exposed letters. Native tests also exposed text sorting of selected game-time aliases; explicit numeric table columns now preserve chronological delivery and maintenance order.

## Safety and remaining limits

- Full forwarded bodies, creation/arrival/exposure proofs, restore authority, original transfer receipts and unknown/no-resend protections remain intact. No pruning or core-table index is introduced.
- A validation timestamp schedules maintenance; it is never evidence that a letter is trusted. Invalid selected letters are quarantined, not repaired or resent. Storage failures still abort their transaction.
- The fixed-size validation batch bounds proof work, not PostgreSQL scan/sort work. Current-generation cooldown history and large campaigns still need measurement; this is not a constant-time or campaign-scale performance claim.
- If both the original attempt write and the guarded release/audit transaction fail, the retained claim remains conservative. Repair storage first. Do not treat a historical zero counter as permission to send again.
- Database rollback cannot promise cleanup of files already written by an external renderer, co-save state, or Skyrim inventory. A renderer overflow must never truncate a full letter to fit.
- The Skyrim interface is optional. The existing web plugin page works. This release makes an explicit web-only compatibility decision; an exact Department/PRISMA pairing needs its own reviewed evidence. The delivery bridge and its original provenance are unchanged.

The regression fixtures use synthetic actors, letters, callbacks and receipts on a private disposable database. They do not establish provider/model quality, physical inventory changes or installed Skyrim gameplay.
