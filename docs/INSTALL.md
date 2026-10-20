# Installation and removal

**PRE-ALPHA:** use a disposable CHIM server and MO2 profile for the first install. The checked package is a matched pair: the server package and Papyrus bridge must stay on the same version.

## Main paired install

1. Download the paired archive, **CHIM-Sealed-Fates-0.1.0-dev.14.zip**, and verify it against **SHA256SUMS.txt**.
2. Install that ZIP as an MO2 mod. Confirm that exactly one active mod supplies **Data/Scripts/CHIMCorrespondenceBridge.pex**; disable older bridge copies.
3. From the same ZIP, install **correspondence-0.1.0-dev.14.dwpkg** through CHIM's normal server plugin manager. It is under **CHIM/server-plugins/correspondence/**.
4. Keep the bridge and server package at dev.14. Do not pair the server package with a bridge from another release.

The paired ZIP includes the MO2 PEX and the matching server package. It does not include the consumer packages. If you need the exact full test pairing, use the optional recovery kit and its unchanged Mind Poisoning 0.1.27-written-subject.dev.4 package (SHA-256 ff4fe782f2a7f4589174ff1f0bb6922b5fde9eba7384f73505e64e09d2bd3386) and Sworn & Scorned 0.1.11-cumulative.dev.1 package (SHA-256 b4451535824f87d26afbce40d39ddecef177586f0b0a46f35530bb60efcbd55b); do not substitute historical consumer versions.

## Server catalog option

**correspondence.tar.gz** is a separate server-only archive with **correspondence/manifest.json** at its root. It contains no MO2 bridge. CHIM does not automatically list it: add or import it through the catalog process already used by your installation. Keep the paired ZIP's PEX active in MO2.

The manifests include source-level compatibility references for HerikaServer hooks, the CHIM client, SKSE, JContainers, and Papyrus Extender. Treat them as targets to compare with your setup, not proof that those installed versions work together. See [server manifest](../server/manifest.json) and [bridge manifest](../game/bridge-manifest.json).

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

## Removal and retained state

Pause the feature and stop the server/game through your deployment's normal procedure. Back up the installation and persistent data, then remove only package files you have confirmed belong to Sealed Fates and disable the MO2 bridge separately.

Code removal does not automatically remove Correspondence tables or rows, eventlog anchors, written-evidence records, books, rendered PNGs, diagnostics, JContainers co-save state, or in-game notes and items. Installer removal behavior for those states is not verified. SQL savepoints do not clean files or undo native inventory changes. Do not delete database rows, evidence files, or game items as part of ordinary code removal.
