# Installation and removal

**PRE-ALPHA:** use a disposable CHIM server and MO2 profile for the first install. The checked package is a matched pair: the server package and Papyrus bridge must stay on the same release package. Dev.17 rebuilds the bridge; use its matching server package and PEX together.

## Pick your interface

**The new in-game Skyrim interface is optional. The existing Sealed Fates web plugin page still works.** Keep using CHIM's web page to compose letters, browse your inbox and outbox, check delivery status, and enable or pause autonomous correspondence. You do not need the new Skyrim interface to use those controls. Your browser has not been fired; I lack the qualifications to conduct that meeting.

The interface is separate from the **matching Sealed Fates Papyrus bridge**, which is still required for physical note delivery and item/gold transfers. Skipping the optional interface does not mean skipping the delivery bridge.

The updated Department adapter passed exact dev.17 owner/adapter checks on disposable TEST. This verifies the synthetic server/adapter boundary, not Skyrim gameplay or a shipped DLL. The verified Department change is a local candidate at verification time; older interface builds without the dev.17 pins refuse this version. Use a Department build containing the verified adapter; see [the exact boundary and digest](VERIFICATION.md). No sibling bundle is included. Normal CHIM update checks may replace the historical kit's tested sibling candidates and invalidate that pairing. An older enabled Mind Poisoning MO2 package may also synchronize an older build back. Confirm the active files before upgrading, and do not stack old and new routes.

## Main paired install

Download the paired archive, **CHIM-Sealed-Fates-0.1.0-dev.17.zip**, and verify it against **SHA256SUMS.txt**.

1. Stop the offscreen service before replacing files (see **Updating** below). Install that ZIP as an MO2 mod. Confirm that exactly one active mod supplies **Data/Scripts/CHIMCorrespondenceBridge.pex** and exactly one correspondence **.dwpkg** remains in the merged **CHIM/server-plugins/correspondence/** directory. Disable older playtest/recovery mods that supply either file.
2. From the same ZIP, install **correspondence-0.1.0-dev.17.dwpkg** through CHIM's normal server plugin manager. It is under **CHIM/server-plugins/correspondence/**.
3. Keep the bridge and server package from the dev.17 paired archive. Do not pair the server package with a bridge from another release.

The paired ZIP includes the rebuilt, hash-verified MO2 bridge PEX and the dev.17 server package, including its stylesheet and artwork. It does not include Mind Poisoning or Sworn & Scorned. Their optional integrations remain separate. The [dev.14 recovery kit](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.14) remains historical evidence for that exact older three-package pairing, not a dev.17 install bundle or a new integration test. Dev.15 release notes and checks likewise remain evidence for dev.15 only.

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

Historical sibling packages are exact development fixtures, not current dependency pins. Their original build-time labels remain inside the immutable kit; see [historical provenance](../release-inputs/README.md). Do not use CHIM update checks as proof that an old three-plugin pairing remains compatible.

## Recovery and diagnostics

A storage-failed receipt gets one intake retry with the same packet. This does not repeat delivery or move inventory. If the packet still cannot be accepted, keep the operation pending/unknown and use read-only Reconcile with the original dispatch wire; never compose or dispatch a replacement as a recovery shortcut. A missing or untrusted native journal cannot prove delivery.

Structured diagnostics record UTC time, bounded status/reason, elapsed time and available claim/source correlation. Autonomy records callback attempts and input/output character counts independently of effect-bearing completion. These are operational measurements, not token totals or provider billing receipts. A failure can legitimately retain an attempted call while producing no letter or social effect.

Offscreen transient reactions may be reconsidered once only when the saved claim and completion/abandonment anchors prove eligibility. Old ambiguous failures remain terminal. Retiring an invalid source preserves its original text and does not expose it or create a replacement transfer.

## Removal and retained state

Pause the feature and stop the server/game through your deployment's normal procedure. Back up the installation and persistent data, then remove only package files you have confirmed belong to Sealed Fates and disable the MO2 bridge separately.

Code removal does not automatically remove Correspondence tables or rows, eventlog anchors, written-evidence records, **public.books** rows, rendered PNGs, diagnostics and worker state under **log/correspondence/offscreen-***, JContainers co-save state, or in-game notes and items. Installer removal behavior for those states is not verified. SQL savepoints do not clean files or undo native inventory changes. Do not delete database rows, evidence files, or game items as part of ordinary code removal.
