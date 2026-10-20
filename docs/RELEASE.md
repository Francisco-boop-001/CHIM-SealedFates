# CHIM Sealed Fates 0.1.0-dev.16 -- PRE-ALPHA

Skyrim still has one courier, and he is VERY good at his job. I continue to reward him with love letters, forged apologies, and forwarded insults; the maintenance work is intended to keep that tiny bureaucracy from eating its own paperwork.

## What changed

- Preflighted the Background Life connector before source claim. Due queued letters still dispatch when the connector is unavailable; a new source remains unclaimed and eligible until a valid connector can process it.
- Reapplied the full core connector setup inside the guarded request callback, so `fast_request` sees the connector's own action flags while native dialogue globals are restored afterward.
- Kept request exceptions terminal after entering the model callback. Existing `failed/model_unavailable` decisions remain terminal audit records because their historical reason and `model_calls` value cannot establish whether a callback ran. `model_calls` records callback attempts, not provider billing or receipt.
- Narrowed prompt reads to empty, queued, and already-exposed paths. First exposure still revalidates the complete body and references; effect writes remain serialized. F-05 measurements and their limits are in the [verification notes](VERIFICATION.md).
- Built deterministic dev.16 assets and passed the fresh-fixture package suite. This does not add a provider evaluation or Skyrim gameplay result.

## Interface and pairing

The in-game Department-owned interface is optional; the existing Sealed Fates web plugin page remains usable. Exact dev.16 compatibility pins await owner confirmation, and no dev.16 sibling bundle is included. The matching Papyrus bridge remains required for physical notes and item/gold transfers.

Normal CHIM update checks may replace the historical kit's tested sibling candidates and invalidate that pairing. An older enabled Mind Poisoning MO2 package may also synchronize an older build back. Do not stack old and new routes. The [dev.15 web presentation release](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.15) is historical dev.15 evidence only; the [dev.14 recovery kit](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.14) remains evidence for its exact older pairing only.

## Assets

Verify downloaded files against these SHA-256 digests and SHA256SUMS.txt.

| Candidate asset | SHA-256 | Bytes |
| --- | --- | ---: |
| [Paired MO2 bridge and CHIM server package](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.16/CHIM-Sealed-Fates-0.1.0-dev.16.zip) | `53cd53f25825e06731d4f56427490f84400a1b4dbc43210a582f2affbad3d74d` | 2,718,035 |
| [Server package](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.16/correspondence-0.1.0-dev.16.dwpkg) | `4bce754c04e4285d9d02fe02db5eae10406197ceffc67ccf6b97b47236d1b4d1` | 2,699,157 |
| [Server-only catalog archive](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.16/correspondence.tar.gz) | `652d939f28b820cdf5883dddf52b04d436cda906c21a992b840aefa2658db1aa` | 2,407,616 |
| [Checksum list](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.16/SHA256SUMS.txt) | `087ab06825cf30073dde25e7cb0e8ab6574fec43c37529376deb3487e33a5810` | 289 |

The paired ZIP reuses the unchanged, hash-verified bridge PEX pinned in [game/bridge-manifest.json](../game/bridge-manifest.json): SHA-256 `7020f4d7dca2c5d94628102540add58227d93b106ef619ae65a33c00ccc8fd8a`, 18,496 bytes. The package builder verifies the manifest, sanitized metadata, and preserved compiled body. No new Caprica build is part of dev.16.

## Verification and limits

The fresh-fixture Windows package suite passed 20 tests. Nine integrated PostgreSQL suites passed on owned disposable socket databases, including 41 autonomy-recovery checks and the F-05 store fixture. The pure host regression passed. The pure PHP batch's one environment issue and the complete F-05 measurements are described in [verification notes](VERIFICATION.md).

The isolated test cluster was stopped and its runtime lease returned. Protected live-VHD size and modification time matched the preflight metadata; this is not a whole-volume content hash. No provider or gameplay session was run. The measurements are synthetic and single-fixture: they do not establish campaign-scale performance, live mod compatibility, note display, inventory/co-save atomicity, or NPC interpretation quality.

---

PRE-ALPHA. No automatic retry of historical ambiguous failures, no provider receipt/billing claim, and no new Skyrim gameplay claim.
