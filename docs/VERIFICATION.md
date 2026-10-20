# Verification and limits

## Evidence for the pinned dev.14 build

Previously recorded checks for the exact dev.14 candidate reported:

- 41 targeted recovery and fault checks passed.
- 18 pure server regressions and 13 package/privacy checks passed.
- A disposable official-manager fixture verified 90 payload files across Correspondence and its two pinned consumers, with no provider calls or core startup.
- The actual Caprica build and bounded PEX metadata sanitization checks passed; compiled code/debug content was preserved.

These are prior checks on the pinned candidate. Current release packaging is checked separately: **python scripts/release-assets.py** reproduces the three pinned tested assets and server catalog archive, while **python -B tests/package_test.py** passes 14 release package checks. The bridge parser and host-state checks are source-driven tests with fake native/JContainers boundaries, not Skyrim runtime tests.

Department's custom adapter, SHA-256 **626f8ea3afb5f5711d0f77dc521607241ca9158b1b3408792fc9c24aa98ae61e**, separately passed its owner's R13 HTTP/PHP-SAPI and isolated PostgreSQL checks with MP dev.4 and Sworn & Scorned 0.1.10-wake.dev.4.1. That test verifies the adapter contract for that composition only; it does not validate the complete recovery-kit pairing, a VM transfer, a provider run, or Skyrim.

## What was not verified

This publishing step made no new provider/model evaluation and ran no Skyrim gameplay session. The checked packages do not establish installed-runtime compatibility, NPC interpretation quality, note display, exact note-instance ownership under concurrency, native inventory atomicity, or co-save restore behavior. Claimed sender names and signatures are attribution, not proof or reliable forgery detection.

Database savepoints cover SQL staging only. They do not roll back rendered PNG files, co-save state, or Skyrim inventory. Unreferenced rendered files can remain after failed dispatches. Removal does not automatically clean plugin tables, eventlog evidence, books, PNGs, co-save data, notes, or items.

F05 is mitigated, not closed. Read-only snapshots avoid writer eventlog/advisory locks, while effect paths still recheck identity and serialize writes. Eventlog freshness still computes MAX(ts); lock and scan cost for large histories or campaigns remains unmeasured.
