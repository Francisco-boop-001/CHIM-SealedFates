# Verification and limits

## Dev.16 source, store, and package verification

The Windows Python 3.12 package suite passes **20 tests** using a fresh fixture directory under the evidence root via `CHIM_PACKAGE_TEST_TMP`. It verifies deterministic dev.16 packaging with exactly four assets, rejects retained dev.15 filenames and conflicting bytes without changing them, and retains the dev.15 and unsupported-version checks. The assets were built from a separate 99-file source copy; their SHA-256 digests are listed in [release notes](RELEASE.md).

Nine integrated PostgreSQL suites passed on fresh, socket-only databases: connector availability, autonomy, autonomy recovery (**41 checks**), offscreen recovery, native PgStore, native core-history privacy, native clocks, native offscreen behavior, and the F-05 store recovery fixture. The N-01 case covers due-child dispatch while unavailable, leaving a source unclaimed without a decision/cooldown, processing it once after connector restoration, and keeping actual request exceptions terminal. Recovery and restore guards remain part of the passing suites.

The pure PHP batch exercised 18 regression suites: 17 passed in the batch; `server_ingress.php` could not start because `php -n` omitted the `tokenizer` extension required for `token_get_all()`. A separate ingress rerun with tokenizer enabled passed. The Windows batch reported 17/18 because `server_offscreen_host.php` uses a strict mixed-separator path comparison; the unmodified native-host test passed on Linux. The pure Windows host regression also passed for connector preflight, correct request-time flags, callback accounting, and restoration of native globals.

The runtime wrapper checked its identity, stopped the owned test cluster, terminated only the TEST distro it had started, and returned the runtime lease. Protected live-VHD size and modification time matched before and after; this is a metadata check, not a whole-volume content hash.

F-05 work narrows prompt reads to the empty, queued, and already-exposed paths. First exposure remains an effect/write path and revalidates the full source body and references. Synthetic PostgreSQL measurements of the eventlog freshness query were:

| Synthetic eventlog rows | `MAX(ts)` (ms) | `EXISTS` no-hit (ms) |
| ---: | ---: | ---: |
| 100,000 | 21.258 | 15.115 |
| 1,000,000 | 78.708 | 82.790 |
| 5,000,000 | 321.101 | 344.704 |

The `EXISTS` alternative did not improve results consistently. A synthetic `ts` index made both queries much faster in this fixture, but no core index or data change is shipped. A read-only prompt query returned in about **46 ms** while a peer insert was uncommitted, preserving empty-queued and exposed-prompt behavior without an eventlog or advisory writer lock. This was one fixture, not a campaign-scale performance result. First-exposure and effect paths still serialize writes; `MAX(ts)`, `observeManaged`, and writer-lock costs remain known work.

The optional Department-owned in-game interface remains optional and the web page remains usable. Exact dev.16 compatibility pins await owner confirmation, and no dev.16 sibling bundle is included. Normal CHIM update checks may replace the historical kit's tested sibling candidates and invalidate that pairing. An older enabled Mind Poisoning MO2 package may also synchronize an older build back. Confirm active files before upgrading and do not stack old and new routes.

No new provider evaluation or Skyrim gameplay result is claimed. `model_calls` records callback attempts, not provider billing or receipt. Old `failed/model_unavailable` decisions remain terminal because historical reason and attempt fields cannot prove that no callback ran.

## Historical dev.15 web presentation release

Fresh verification for this web presentation build:

- 18 package tests pass, including exact CSS/art payload bytes, copied-checkout bridge evidence, immutable-output guards and stale-output rejection before writes. Four deterministic distributable assets are built; no historical recovery kit is relabeled as current.
- Registered disposable PHP 8.2.29 reproduced the missing-layout baseline assertion, then passed three pure UI/autonomy/memory-recovery suites, four syntax checks and five synthetic page renders. The test batch made no database, core or provider calls.
- 12 Chromium browser scenarios pass at 320, 390, 768 and 1280 pixel widths in light/dark modes, across populated, empty, opened, error and long-Unicode views. Strict same-origin image/style CSP, complete uncropped artwork, no page overflow, skip-link focus and keyboard horizontal table panning were checked. A synthetic compose POST retained all fields and was intercepted before backend delivery.
- Browser testing found long actor names overflowing an opened-letter heading. A CSS-only wrapping correction was made and all browser scenarios rerun. The PHP files remained byte-identical to the native-tested versions; the browser receipt identifies the final stylesheet hash.
- The test distro was stopped and returned to its owner. Protected live VHD size/mtime matched the fresh preflight; this is a metadata guard, not a whole-volume content hash. No live deployment was performed.

The artwork is the selected 1536 × 1024 emo-llama edit. The packaged WebP is a lossless encoding with identical decoded pixels; the original PNG remains in the repository. Web presentation and package verification do not establish Skyrim gameplay or new NPC/model semantics.

## Evidence for the pinned dev.14 build

Previously recorded checks for the exact dev.14 candidate reported:

- 41 targeted recovery and fault checks passed.
- 18 pure server regressions and 13 package/privacy checks passed.
- A disposable official-manager fixture verified 90 payload files across Correspondence and its two pinned consumers, with no provider calls or core startup.
- The actual Caprica build and bounded PEX metadata sanitization checks passed; compiled code/debug content was preserved.

These are historical checks on the exact dev.14 candidate. At its tag, **python scripts/release-assets.py** reproduced the three pinned tested assets and server catalog archive, and **python -B tests/package_test.py** passed 14 release package checks. They are not fresh dev.15 integration results. The bridge parser and host-state checks are source-driven tests with fake native/JContainers boundaries, not Skyrim runtime tests.

Department's custom adapter, SHA-256 **626f8ea3afb5f5711d0f77dc521607241ca9158b1b3408792fc9c24aa98ae61e**, separately passed its owner's R13 HTTP/PHP-SAPI and isolated PostgreSQL checks with MP dev.4 and Sworn & Scorned 0.1.10-wake.dev.4.1. That test verifies the adapter contract for that composition only; it does not validate the complete recovery-kit pairing, a VM transfer, a provider run, or Skyrim.

## What was not verified

The dev.16 verification made no new provider/model evaluation and ran no Skyrim gameplay session. The checked packages do not establish installed-runtime compatibility, NPC interpretation quality, note display, exact note-instance ownership under concurrency, native inventory atomicity, or co-save restore behavior. Claimed sender names and signatures are attribution, not proof or reliable forgery detection.

Database savepoints cover SQL staging only. They do not roll back rendered PNG files, co-save state, or Skyrim inventory. Unreferenced rendered files can remain after failed dispatches. Removal does not automatically clean plugin tables, eventlog evidence, books, PNGs, co-save data, notes, or items.

F05 is mitigated, not closed. Read-only snapshots avoid writer eventlog/advisory locks, while effect paths still recheck identity and serialize writes. Eventlog freshness still computes MAX(ts); lock and scan cost for large histories or campaigns remains unmeasured.
