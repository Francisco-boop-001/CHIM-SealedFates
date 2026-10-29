# Dev.30 companion recovery verification

Dev.30 fixes two confirmed availability/recovery bugs. Unreadable participation settings no longer count as confirmed opt-outs, and unavailable player identity no longer masquerades as a changed identity. Queued mail defers until the required evidence is readable; the existing fresh same-episode, identity, scope/restore and scheduler ownership checks still apply. No historical stale/closed decision is automatically revived.

## Behavioral evidence

Both new PostgreSQL regression tests failed on the original code before the fixes. The final `tests/server_companion_availability_pg.php` run passes 13 scenarios and 86 assertions on a new Windows PostgreSQL 17 fixture and a new socket-only PostgreSQL 15 fixture in `DwemerAI4Skyrim3-test2`:

- Readable control, unreadable flag in poll/due paths, and missing flag.
- Confirmed opt-out and death.
- Temporarily unavailable player, resolved player/NPC identity changes, unavailable physical mapping and resolved physical identity changes.
- Changed episode and changed restore token.
- Recovery without a fresh observation remains blocked; fresh valid recovery queues one physical dispatch command/operation, and repeated ticks cannot duplicate it. Manual scheduler bypass remains refused.

All 27 pure PHP suites pass on test2. Windows passes 26/27; `server_offscreen_host` retains its documented platform-path assertion failure, which passes on test2. The initial test2 harness lacked process-local ctype/tokenizer extensions under `php -n`; those failed runs were retained, corrected without configuration changes, and superseded by a complete passing run. All owned clusters were identity-checked and stopped.

Publication reruns package/preflight tests after the dev.30 version/date change, syntax checks and the isolated browser-origin/CSRF fixture. The 171 mechanically translated native-source checks passed with unchanged bridge/observer source and PEX bytes. Clean-tag rebuilding and draft/public asset comparison are required before publishing or advancing main; their exact commit, source/package hashes and outputs are retained in the private release record.

Private evidence in the owning workspace: `artifacts/chim-sealed-fates-companion-availability-fixes-20261028` and `artifacts/chim-sealed-fates-dev30-publication-20261028`. Runtime source hashes are checked against the test2 freeze before release; only release metadata changes after that run.

## Distribution and proof limits

Install the single `CHIM-Sealed-Fates-0.1.0-dev.30.zip` after ending the game session. It contains both unchanged matching Papyrus scripts and `CHIM/server-plugins/correspondence/0.1.0-dev.30.dwpkg`. No new migration is introduced by dev.30. Older upgrades still require migration006 and its documented owner/admin rights and uncapped one-time index build; an already applied migration is not a reason to reapply it manually.

This release deliberately records **web-only**. Department prealpha.25 pins dev.29's exact files and is not a compatible dev.30 interface release; keep its checks and wait for a matching owner update. This Sealed Fates release changes no Department/native/UI payload and claims no new paired receipt.

No live server, installed game/MO2 or provider was accessed for the fixes/publication. Test2 used fresh synthetic data, not its copied campaign. Queued native commands are not real inventory transfers. Actual follower observations, unloaded actors/framework behavior, rendering, physical letters/items/gold, Skyrim crashes, save/co-save recovery, installed HTTP sends and real-model behavior remain unverified for these changed files. This remains **PRE-ALPHA**; publication is not installation or gameplay acceptance.
