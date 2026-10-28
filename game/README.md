# Sealed Fates game bridge

`Source/Scripts/CHIMCorrespondenceBridge.psc` is a separate global Papyrus extension. Install the matching `Scripts/CHIMCorrespondenceBridge.pex`; it does not replace a CHIM built-in. The single MO2 ZIP also includes `Scripts/CHIMCorrespondenceCompanion.pex`, described below.

The repository retains the anonymous dev.14 bridge and includes the dev.29 recipient-owned bridge bound by the current manifest. [The bridge manifest](bridge-manifest.json) binds the script, compiler and PEX hashes. The portable receipt under `bridge/` at the repository root is a projection of the actual new compilation: it retains hashes and compilation facts, uses portable input labels, and records the original private receipt hash. Raw compiler output and logs are preserved privately, outside the distributable source. The sanitized header is anonymous and its compiled code/debug body is byte-identical to the raw output.

## Wire and receipts

A currently managed CHIM actor transports `ExtCmdCHIMCorrespondenceBridge_Deliver` or `ExtCmdCHIMCorrespondenceBridge_Reconcile` with JSON. Inventory owners are explicit stable plugin/local identities checked against signed runtime FormIDs. Body text and hidden author/payer provenance do not enter receipt JSON. Reconcile requires the original dispatch JSON unchanged.

The bridge claims the scoped operation before physical work, checks all attachments, creates a note, then verifies both sides of every transfer. Partial or ambiguous results become `unknown`; they do not trigger automatic redelivery. Reconcile moves no inventory and replays a cached completion when available. A physical receipt does not establish that the NPC read the letter: recipient exposure is the separate server context boundary.

A delivered receipt is emitted in this member order: `protocol_version, document_id, operation_id, receipt_id, playthrough_id, scope_token, nonce, status, reason, note_form_id, attachments`. Each attachment uses `form_id, quantity, before_source, after_source, before_recipient, after_recipient`; array order is retained. Dev.14 hashes the decoded PHP receipt in its input order before JSONB storage. Custom read adapters must preserve full anchored-hash validation; JSONB member order is not a safe substitute for the producer order.

## Build and verification

From the repository root, `python scripts/release-assets.py --department-interface web-only` reproduces the release using the included verified PEX, without claiming Department compatibility. See [build instructions](../docs/BUILD.md) for a tested pairing. `python tests/bridge-parser.py` and `python tests/bridge-state.py` mechanically translate production validation/control flow and use deliberately fake native boundaries. They do not run the Papyrus VM or Skyrim.

Fresh Papyrus compilation requires Caprica plus compatible CHIM, SKSE, JContainers and Papyrus Extender declarations. These third-party SDKs and the private development compiler wrapper are not redistributed. The manifest/receipt record the retained build identities and dependencies; shipping the tested PEX avoids presenting an untested recompilation as the same binary.

Native gameplay, note rendering, co-save restore, real inventory atomicity/concurrency and installed dependency compatibility remain unverified. Attachments address base forms and quantities; selecting a particular enchanted/renamed instance and dynamic FF identities is unsupported.

## Companion observer

`Source/Scripts/CHIMCorrespondenceCompanion.psc` is the separate read-only observer used for optional waiting-companion check-ins. Its source-bound anonymous PEX and build receipt are listed in `companion-manifest.json`. Dev.29 leaves that observer unchanged. It records supported game observations; it does not invent abandonment from server time, create notes or move items. Server eligibility, fresh native observations, restore/playthrough checks and one attempt per waiting episode remain required. See [companion behavior and gameplay limits](../docs/COMPANION-CHECKINS.md).

## Ownership and retained replay evidence

Dev.29 assigns each newly delivered note to the addressed actor's base and verifies that ownership after insertion and after attachments. Existing notes and cached receipts are not rewritten; reconciliation still replays the original result without moving inventory. It does not add NPCs to a shared faction. Source-translated fake-native tests and a real Caprica build do not prove Skyrim's stolen-item UI or follower-framework behavior.

JContainers claims, exact dispatch payloads and cached receipts remain in the matching co-save. This history is intentionally not expired or replaced by a collision-prone hash: deleting it could permit duplicate physical transfers after replay or restoration. The available JContainers interface has no verified lossless compression or cryptographic digest primitive. History growth remains a known limitation; an external file is not an equivalent save authority. Full forwarded letter bodies are preserved on the server and are not embedded in these bridge dispatch packets.
