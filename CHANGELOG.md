# Changelog

## 0.1.0-dev.14 — PRE-ALPHA

- Added bounded recovery for abandoned claims and terminal failed or expired NPC reactions. Recovery requires current scope and valid anchors, preserves the source body, and does not retry or expose it.
- Made the offscreen recovery scan rotate past invalid proofs so they cannot pin the same bounded scan window.
- Added full-copy forwarding limits: the complete original, labels, and cover must fit within 2,000 codepoints and 8,000 bytes. The system does not clip a forward to fit.
- Limited recipient prompt context to the three newest valid exposed letters found in a recent 32-entry window. Escaping protects the wrapper format; it is not a prompt-injection guarantee.
- Added read-only store transactions and explicit actor-row locks for effect paths. Identity changes remain visible while effects fail closed.
- Updated the PHP CorrespondenceStore contract. Existing custom adapters need the dev.14 methods and signatures; see [installation](docs/INSTALL.md).

### Known limits

F05 query and writer-lock cost is mitigated, not closed: freshness still uses MAX(ts), effect commits still require writer serialization, and large-campaign performance is unmeasured. Package checks and controlled database recovery do not prove native Skyrim behavior, provider semantics, or atomicity across SQL, rendered files, co-save data, and inventory.
