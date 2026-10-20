# Changelog

## 0.1.0-dev.16 — PRE-ALPHA

- Deferred autonomy sources before claim when the Background Life connector is unavailable or invalid, while continuing due-child dispatch. A later valid connector can process an unclaimed source once.
- Kept request exceptions terminal after a model-callback attempt. Existing `failed/model_unavailable` decisions remain terminal audit records; their old reason and `model_calls` field cannot prove that no callback was entered, so dev.16 does not retry them automatically.
- `model_calls` records model-callback attempts. It does not prove a provider request was billed or received.
- Reapplies core connector setup inside the guarded request callback so the connector flags used by `fast_request` are restored without leaking into native dialogue globals.
- Narrows prompt reads to empty, queued, and already-exposed paths. Synthetic PostgreSQL timings still show `MAX(ts)` scan cost; the faster synthetic `ts` index is not shipped, and first-exposure/effect writes remain serialized. See [verification limits](docs/VERIFICATION.md).
- Built four deterministic dev.16 assets and passed 20 fresh-fixture package tests. The pinned dev.14 reproduction path and dev.15 output protections remain.
- No provider evaluation or Skyrim gameplay result is added, and no core index or eventlog data change is shipped.

## 0.1.0-dev.15 — PRE-ALPHA

- Added the Sealed Fates woodcut illustration, including the emo llama, to the web page and GitHub README.
- Matched the sibling plugins with parchment panels, serif headings, rust-red controls and slate-blue accents, plus system light/dark styling and responsive forms and tables.
- Kept the existing web composer, mailbox, autonomy controls and optional Skyrim-interface choice. Form actions, authorization, CSRF checks, delivery and social interpretation contracts are unchanged.
- Included the local stylesheet and lossless WebP in the explicit server package payload; reused the unchanged verified bridge PEX.
- This is a web presentation release, not a new NPC/model or Skyrim gameplay evaluation. Published dev.14 assets and tag remain unchanged.

## 0.1.0-dev.14 — PRE-ALPHA

- Added bounded recovery for abandoned claims and terminal failed or expired NPC reactions. Recovery requires current scope and valid anchors, preserves the source body, and does not retry or expose it.
- Made the offscreen recovery scan rotate past invalid proofs so they cannot pin the same bounded scan window.
- Added full-copy forwarding limits: the complete original, labels, and cover must fit within 2,000 codepoints and 8,000 bytes. The system does not clip a forward to fit.
- Limited recipient prompt context to the three newest valid exposed letters found in a recent 32-entry window. Escaping protects the wrapper format; it is not a prompt-injection guarantee.
- Added read-only store transactions and explicit actor-row locks for effect paths. Identity changes remain visible while effects fail closed.
- Updated the PHP CorrespondenceStore contract. Existing custom adapters need the dev.14 methods and signatures; see [installation](docs/INSTALL.md).

### Known limits

F05 query and writer-lock cost is mitigated, not closed: freshness still uses MAX(ts), effect commits still require writer serialization, and large-campaign performance is unmeasured. Package checks and controlled database recovery do not prove native Skyrim behavior, provider semantics, or atomicity across SQL, rendered files, co-save data, and inventory.
