# Department / PRISMA compatibility

The Department Skyrim interface is optional. The Sealed Fates web plugin page remains available; the separate delivery bridge is still required for physical notes, items and gold. The courier's workload is terrible. Making the settings page mandatory would be adding insult to occupational injury.

Compatibility belongs to an exact pair of sources. A successful Sealed Fates test run, unchanged bridge bytes, or a version added to Department's allowlist does not establish that its adapter understands the new server behavior. Unknown or changed sources must continue to be refused.

The preflight currently targets dev.20 explicitly. A future version bump must advance that reviewed release identity and obtain its own pairing evidence or an explicit web-only decision. Editing an old receipt's version does not establish compatibility.

## Before each release

1. Freeze the Sealed Fates candidate and give the Department author its version, source revision and hashes. Review changed store interfaces, document fields, restore authority, transaction ownership and receipt projections together. Do not just add a manifest pin.
2. Run Department's source-pairing check against that exact candidate. Keep the JSON result. An accepted older version is a positive control; an unknown version and a changed source file must be refused. Readiness is only a source check.
3. For a compatibility claim, run the adapter's focused PostgreSQL facade cases on the reserved disposable server. Validate fixture initialization against that release's public restore lifecycle first; copied private helpers or forced state resets can invalidate the test setup. Bind results to the frozen owner and Department sources. Cover catalog, mailbox, detail, compose, reconciliation, restore overlap, caller-owned transactions/leases, closed letters and uncertain/partial receipts. Keep original transfer anchors and no-resend behavior intact.
4. Pass the resulting reviewed pairing receipt to the release entry:

       python scripts/release-assets.py --department-interface tested-paired --receipt <verified-pairing.json>

   If that pairing is unavailable, deliberately select `--department-interface web-only` and say so in the README and release notes. Keep urgent server fixes possible without claiming an untested optional interface works.
5. Retain the preflight output with the build evidence and identify the exact supported Department build in the release notes. Changing either bound source invalidates the pairing; rerun the relevant checks instead of editing the old receipt. Do not replace published assets in place.

The release entry checks the decision before packaging. `build-package.py` still builds local install packages without another repository, credentials or TEST. Neither command publishes or installs anything. A receipt is evidence from a reviewed run, not independent proof of its truth; its source and test provenance still need review.

PostgreSQL and source checks do not prove that a DLL loads in Skyrim, that the installed native interface works, or that a physical transfer occurred in game. Report those limits separately. Keep live data, installed game files, provider settings and existing releases untouched during pairing work.
