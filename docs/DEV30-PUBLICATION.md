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

The original immutable release deliberately records **web-only**. At that publication, Department prealpha.25 pinned dev.29 and correctly refused dev.30. Subsequent Department prealpha.26 adds the exact dev.30 pins and passes 16 focused test2 service cases with 71 assertions. Its native/UI payload is unchanged. The [follow-up record](PRISMA-COMPATIBILITY.md) states its narrower scope; it does not replace the original build gate or satisfy the broader tested-paired gate. Sealed Fates' tag and assets remain unchanged.

No live server, installed game/MO2 or provider was accessed for the fixes/publication. Test2 used fresh synthetic data, not its copied campaign. Queued native commands are not real inventory transfers. Actual follower observations, unloaded actors/framework behavior, rendering, physical letters/items/gold, Skyrim crashes, save/co-save recovery, installed HTTP sends and real-model behavior remain unverified for these changed files. This remains **PRE-ALPHA**; publication is not installation or gameplay acceptance.


## Public release result

Published [correspondence-v0.1.0-dev.30](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.30) at `1eb58d999698002f8aeaa05b21464f7608717794`. All75 package/preflight tests pass in the clean-tag run; its four rebuilt assets match both authenticated draft and anonymous public downloads. Both packaged PEX files are byte-identical to dev.29. The server DWPkg changes only its manifest/checksums, server manifest and the two reviewed runtime files.

17 prior releases and 34 prior tag refs remain unchanged. The original release gate stays web-only and the release remains PRE-ALPHA; the subsequent optional Department service pairing is recorded separately. Main receives this documentation after public asset verification; the release tag and assets are immutable.

The table below is developer verification evidence. Players install the single MO2 ZIP; no manual checksum calculation is required.

| Asset | SHA-256 | Bytes |
| --- | --- | ---: |

| `CHIM-Sealed-Fates-0.1.0-dev.30.zip` | `fef5b26d30da32102b4d512d9f80e060331864fb9af3f803a64acf2fc8f5d68e` | 1097015 |

| `SHA256SUMS.txt` | `bec94c3abf3e42c8cd6df6f0098533bc702adad773d24e92a4895cd35d024bba` | 289 |

| `correspondence-0.1.0-dev.30.dwpkg` | `f557901c091c6d8316d955f71f284236d512e34235d678cc9ef372906daa0be1` | 1042686 |

| `correspondence.tar.gz` | `5c7673ec73a4aa81918804bb3a9c8b43019515361bee8d21d2e309cb9e36f181` | 599388 |
