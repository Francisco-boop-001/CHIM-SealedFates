# Verification and limits

## Dev.15 web presentation release

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

This publishing step made no new provider/model evaluation and ran no Skyrim gameplay session. The checked packages do not establish installed-runtime compatibility, NPC interpretation quality, note display, exact note-instance ownership under concurrency, native inventory atomicity, or co-save restore behavior. Claimed sender names and signatures are attribution, not proof or reliable forgery detection.

Database savepoints cover SQL staging only. They do not roll back rendered PNG files, co-save state, or Skyrim inventory. Unreferenced rendered files can remain after failed dispatches. Removal does not automatically clean plugin tables, eventlog evidence, books, PNGs, co-save data, notes, or items.

F05 is mitigated, not closed. Read-only snapshots avoid writer eventlog/advisory locks, while effect paths still recheck identity and serialize writes. Eventlog freshness still computes MAX(ts); lock and scan cost for large histories or campaigns remains unmeasured.
