# Packaging lessons

## CHIM automatic synchronization

- Mistake: the bundle writer and its verifier agreed on a descriptive download basename, while CHIM interpreted that whole filename as the version. Internal consistency hid an invalid consumer contract.
- Rule: inside `CHIM/server-plugins/<package>/`, use `<declared-version>.dwpkg`. Keep descriptive standalone download names separate.
- Prevention: inspect the produced ZIP and compare its package folder and filename stem with the embedded manifest, independently of the writer's path-building expression; exercise the actual package validator offline before claiming installation compatibility.

## Read-only installation diagnosis

- Identify the active MO2 profile, enabled mod, installed package and sync-log result before asking the user which download they installed. A download or metadata label alone does not establish the running server version.
- While the user is playing, read-only inspection does not authorize renaming a package, triggering sync, restarting anything or using the reserved test server.

## Browser policy and POST validation must be tested together

- Mistake: testing a synthesized valid Origin missed that the page's no-referrer policy causes Chromium form POSTs to carry Origin: null. The canonical-port repair alone could not establish browser send readiness.
- Rule: reproduce security-header interactions with a real browser and the actual emitted response policy before claiming a web submission fix. Preserve null/cross-origin refusal and CSRF; repair the header that creates the invalid request.
- Prevention: run the standalone browser fixture with production header extraction plus same-origin and wrong-CSRF controls, and distinguish it from an installed-game/server send.


## Explain recovery in the interface the player is using

- Mistake: explaining fresh NPC presence without clearly distinguishing Department's locked unresolved-transfer composer from the separate web page's same-letter dispatch action can imply the Send button will unlock.
- Rule: trace the exact visible control, API action and resulting state before giving a recovery instruction; renewed dispatch eligibility does not itself resume a queued document or clear a duplicate-send lock.
- Prevention: describe the current interface limitation, name the exact same-document action available, and preserve unresolved operation identity throughout recovery.


## Source-bound native activity fixtures

- Mistake: new fixtures assumed nested child results, writable scratch roots and PHP object-key order instead of checking the existing producers. These caused setup/assertion failures before complete runtime verification.
- Rule: trace every fixture producer and consumer before a server run. Reuse the owned writable test directory and pinned file layout. Compare JSON objects through the existing canonical serializer with exact fields/types/list order; never relax the authority assertion to hide a fixture failure.
- Prevention: verify cold-child paths, null handling, result shapes and timing assumptions offline, freeze exact Git bytes, and keep every failed run and cleanup receipt.

## Tests during an active game session

- Warn the user in this chat before each server test, including isolated test2. Keep live and the installed game untouched. If a test ever requires stopping live, wait for explicit confirmation; do not infer permission from general test authorization.
