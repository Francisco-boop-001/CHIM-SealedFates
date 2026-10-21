# Installation and removal

**PRE-ALPHA:** use a disposable CHIM server and MO2 profile for the first install. The package is a matched pair: server package and Papyrus bridge must come from the same paired archive. Dev.20 retains the byte-identical, source-bound dev.17 bridge and its original build provenance. Install the server and bridge from the dev.20 paired archive together; do not relabel an older archive.

## Pick your interface

**The new in-game Skyrim interface is optional. The existing Sealed Fates web plugin page still works.** Keep using CHIM's web page to compose letters, browse your inbox and outbox, check delivery status, and enable or pause autonomous correspondence. You do not need the new Skyrim interface to use those controls. Your browser has not been fired; I lack the qualifications to conduct that meeting.

The interface is separate from the **matching Sealed Fates Papyrus bridge**, which is still required for physical note delivery and item/gold transfers. Skipping the optional interface does not mean skipping the delivery bridge.

The optional [Department/PRISMA v0.1.0-prealpha.4](https://github.com/Francisco-boop-001/CHIM-DepartmentOfQuestionableDecisions/releases/tag/v0.1.0-prealpha.4) adapter passed the exact dev.20 server pairing tests. Published prealpha.3 and earlier refuse dev.20. The existing web plugin page still works. No sibling plugins are bundled, and installed Skyrim behavior remains unverified. Read [the source identities and verification limits](PRISMA-COMPATIBILITY.md). Normal CHIM update checks may replace the historical kit's tested sibling candidates and invalidate that pairing. An older enabled Mind Poisoning MO2 package may also synchronize an older build back. Confirm the active files before upgrading, and do not stack old and new routes.

## Main paired install

Download the paired archive, **CHIM-Sealed-Fates-0.1.0-dev.20.zip**, and verify it against **SHA256SUMS.txt**.

1. Stop the offscreen service before replacing files (see **Updating** below). Install that ZIP as an MO2 mod. Confirm that exactly one active mod supplies **Data/Scripts/CHIMCorrespondenceBridge.pex** and exactly one correspondence **.dwpkg** remains in the merged **CHIM/server-plugins/correspondence/** directory. Disable older playtest/recovery mods that supply either file.
2. From the same ZIP, install **correspondence-0.1.0-dev.20.dwpkg** through CHIM's normal server plugin manager. It is under **CHIM/server-plugins/correspondence/**.
3. Keep the bridge and server package from the dev.20 paired archive. Do not pair the server package with a bridge from another release.

The paired ZIP includes the unchanged, hash-verified MO2 bridge PEX and the dev.20 server package, including its stylesheet and artwork. It does not include Mind Poisoning or Sworn & Scorned. Their optional integrations remain separate. The [dev.14 recovery kit](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.14) remains historical evidence for that exact older three-package pairing, not a dev.20 install bundle or a new integration test. Dev.15 release notes and checks likewise remain evidence for dev.15 only.

CHIM selects client-synchronized packages by **file modification time**, not the highest version number. Older playtest ZIPs contain 2026 timestamps; current reproducible archives use a fixed 1980 timestamp. With both enabled, the courier may diligently install the older package. Check the merged package directory; an apparently current PEX alone does not establish the active server version. Actual MO2 timestamp preservation remains unverified.

## Server-only maintenance archive

**correspondence.tar.gz** contains server files under **correspondence/** and no MO2 bridge. It is retained for inspection and administrator-managed deployment; this release does not establish a working Plugin Manager catalog listing or import route for it. Use the paired ZIP and **.dwpkg** route above. Core documents both standard and legacy catalog formats, but that does not make this archive a configured listing.

The manifests include source-level compatibility references for HerikaServer hooks, the CHIM client, SKSE, JContainers, and Papyrus Extender. Treat them as targets to compare with your setup, not proof that those installed versions work together. See [server manifest](../server/manifest.json) and [bridge manifest](../game/bridge-manifest.json).

Dev.17 and historical asset digests are listed in [release notes](RELEASE.md). Optional-interface compatibility is limited to the exact tested adapter described in [verification notes](VERIFICATION.md). Do not treat source compatibility references or the historical recovery kit as approval of a new sibling-mod pairing.

## Settings and behavior

Autonomous correspondence is disabled by default. To opt in, enable the global Correspondence control and, for each participating NPC, Background Life and Send letters. Decisions are made only on eligible ordinary native dialogue postrequests and require an already exposed, confirmed-delivery source. Browser views do not trigger decisions.

The separate offscreen text-mail worker starts or resumes after a fresh native bored or location observation when the global setting is enabled. It waits on fresh game-clock observations, checks scope and restore state, and pauses if the clock stops or the setting is disabled. Its supervisor is capped at 30 minutes, and each worker child runs at most one bounded tick. This service can progress private NPC text mail while the player is away; it does not claim a physical note or inventory transfer. See the [feature overview](../README.md).

NPC decisions wait two in-game hours after exposure. The scheduler allows at most one decision per game-hour across the scope and applies a six-game-hour cooldown per NPC. A generated reply/forward thread is limited to three letters. Transit is delayed one game-hour when sender and recipient have the same known last location, otherwise twelve game-hours; this is a delay, not proof of travel or proximity.

Forwarding carries the whole visible original, attribution labels, and cover note. All of it must fit within 2,000 codepoints and 8,000 bytes. Attribution does not prove authorship. A printed note does not prove it was read; recipient exposure occurs only through the addressed NPC's context render.

## Custom server adapters

Dev.14 expands the PHP CorrespondenceStore interface. Custom implementations must add:

    readTransaction(callable $work): mixed
    lockActorRows(array $npcIds, bool $includePlayer = false): array
    documents(string $scope, ?int $recipientId = null): array

An older adapter can fail during class loading, including a read-only adapter. Read-only implementations must keep their snapshot behavior and reject writer identity-lock requests; they must not route effects through a read-only path.

## Updating

Disable the global correspondence control in the web page; that requests an offscreen-service stop. Let the current child finish and confirm the service lock has been released, or stop your own server environment through its normal procedure. Stop the game before replacing the bridge. Back up persistent data and keep the matching server package/bridge pair together. Ensure only one correspondence DWPkg and one bridge PEX are active before restarting. Restart/resume deliberately; the UI's paused label alone is not proof the old worker has exited.

For a dev.17-or-older upgrade, keep a matched backup of the server database, its runtime files and the game's save/co-save before applying migration 004. The plugin creates `plugins.correspondence_restore_state` (one epoch/pending/native-floor row) and `log/playthrough_runtime/correspondence.restore.json` under the engine root. Dev.19 also uses the persistent sibling `correspondence.restore.json.lock`; do not replace or remove that lock while any plugin process can run. Preserve these files with the database backup. A code-only downgrade does not undo migration or restore authority.

Published dev.18 has the overlapping-init ownership defect fixed in dev.19. Serialize game init/load operations when using dev.18; the fix is not in the immutable dev.18 download. If loads have overlapped, pause correspondence and complete a fresh supported init before resuming. Older dev.14–17 releases also used a global timestamp fence that can reject boot-scoped timestamps after reboot; do not delete history to work around that older design.

After installing dev.18 or later, run CHIM's normal supported game init/load cycle. The production bootstrap initializes schema and captures restore authority; custom adapters must use that lifecycle rather than invoking a constructor alone. Clearance requires a successful restore fence and post-prune completion, not elapsed time. A pending, missing, malformed or mismatched marker/row keeps correspondence unavailable. Fix the failed prerequisite and complete another supported init; do not manually write `clear`, delete the marker or reset the epoch row.

The native timestamp floor rejects packets older than or equal to the clearing init. These client timestamps are boot-scoped high-resolution counts, not Unix dates. A packet at the same count is refused until a later native event; after a restart/load, complete a new supported init. An old queued request is not evidence of a new game observation.

Historical sibling packages are exact development fixtures, not current dependency pins. Their original build-time labels remain inside the immutable kit; see [historical provenance](../release-inputs/README.md). Do not use CHIM update checks as proof that an old three-plugin pairing remains compatible.

## Recovery and diagnostics

A storage-failed receipt gets one intake retry with the same packet. This does not repeat delivery or move inventory. If the packet still cannot be accepted, keep the operation pending/unknown and use read-only Reconcile with the original dispatch wire; never compose or dispatch a replacement as a recovery shortcut. A missing or untrusted native journal cannot prove delivery. Reconcile reads an existing journal; it cannot repair missing or unsupported JContainers, a malformed dispatch packet, or a missing journal. Fix the prerequisite first, keep the original operation and its evidence, then check that same operation. If its outcome still cannot be proven, abandon further delivery attempts and leave it pending/unknown. There is no safe clean-failure receipt or automatic resend for an unproven transfer, and no database deletion is required.

Dev.19 adds **Close without resending or refunding** to the web page for manual letters. This records an idempotent administrative closure and blocks dispatch/retry for that document. The physical outcome remains queued, dispatched, failed or unknown as actually supported by evidence. It does not remove a queued game command, recover money, prove that nothing moved, or stop a genuine late receipt. The read-only outcome check remains available for dispatched/unknown operations. Autonomous child dispatch stays owned by its scheduler; pause autonomous correspondence through its normal control rather than closing its children manually.

The dev.20 F-03 recovery repair keeps a closed letter visible if its later administrative close event has been pruned. The page flags the missing closure proof; the stored closure still blocks dispatch and retry. A correctly correlated receipt or read-only reconciliation can resolve the original operation only while its original document and dispatch anchors survive. A genuine late delivery can still be read, change relationships, and prompt replies: closing the paperwork does not recall the courier. Missing original anchors remain quarantined. This repair ships in dev.20; the immutable dev.19 download does not contain it.

The dev.20 F-04 repair also attempts database-only quarantine when the restore marker cannot be written. It marks only the request's previously captured epoch pending, under the existing writer lock, preserving the native timestamp floor and any newer initializer. Another supported init is required to recover; do not clear the row or marker manually. If no trustworthy prior epoch was captured, or both persistence channels fail, cross-process quarantine cannot be guaranteed. The current request stays unsafe. This repair ships in dev.20; the immutable dev.19 download does not contain it.

If JContainers or another native dependency is missing, restore the supported dependency in the disposable profile first and retry only the original read-only check. If its journal is missing, corrupt or cannot be correlated with the original operation, preserve unknown and close further attempts if desired. No clean-failure receipt is invented to make the UI look reassuring. The courier is many things; a forensic accountant is not currently one of them.

Structured diagnostics record UTC time, bounded status/reason, elapsed time and available claim/source correlation. Autonomy records callback attempts and input/output character counts independently of effect-bearing completion. These are operational measurements, not token totals or provider billing receipts. A failure can legitimately retain an attempted call while producing no letter or social effect.

Offscreen transient reactions may be reconsidered once only when the saved claim and completion/abandonment anchors prove eligibility. Old ambiguous failures remain terminal. Retiring an invalid source preserves its original text and does not expose it or create a replacement transfer.

## Removal and retained state

Pause the feature and stop the server/game through your deployment's normal procedure. Back up the installation and persistent data, then remove only package files you have confirmed belong to Sealed Fates and disable the MO2 bridge separately.

Code removal does not automatically remove Correspondence tables or rows, eventlog anchors, written-evidence records, **public.books** rows, rendered PNGs, diagnostics and worker state under **log/correspondence/offscreen-***, JContainers co-save state, or in-game notes and items. Installer removal behavior for those states is not verified. SQL savepoints do not clean files or undo native inventory changes. Do not delete database rows, evidence files, or game items as part of ordinary code removal.

That retained state also includes `plugins.correspondence_restore_state`, `log/playthrough_runtime/correspondence.restore.json` and its stable `.lock` sibling, plus operator-close evidence. Archive them with their matching database/save before any separately planned cleanup. Do not remove one side of restore authority while retaining the other, and do not use uninstall/reinstall as a way to clear an unknown transfer or quarantine.
