# Dev.29 GitHub findings: scope and proof

## Decisions

| Report | Disposition | Remaining boundary |
| --- | --- | --- |
| #1 note ownership | Replace faction ownership with recipient actor-base ownership and verify it after insertion/transfers. | Source-translated fake natives and Caprica compile; actual Skyrim stolen flags untested. |
| #2 idle poll locks | Already repaired in dev.26: outer disabled/cooldown reads precede the writer, with due-state rechecks inside it. | Pure host/policy checks are separate from live lock timing. |
| #3 CHIM 3.4.2 connector | Add only the source-verified legacy `openaijson`/`openrouterjson` dialogue contracts; preserve newer classifier behavior and unknown-driver refusal. | Actual retained core preflight with fake DB/config, no provider callback or installed-core execution. Unknown/custom legacy drivers remain unsupported. |
| #4.1 companion actions | Already repaired in dev.26, including safe Close and original-operation reconciliation. | No automatic resend/refund. |
| #4.2 history × actors | Memoize the derived actor catalog inside one transaction, including the player; clear at transaction boundaries. | Full document/reference validation remains linear in history to preserve sibling-plugin backlog semantics. No bounded-history claim. |
| #4.3 duplicate scan | A plugin-named partial expression index on authoritative eventlog rows, with a CASE-guarded JSON cast and exact duplicate query. | Migration006 builds a core-table index once and needs eventlog owner/admin rights. Rows, restore semantics and other writers remain authoritative; no separate tombstone/projection is introduced. The 3 s timeout bounds lock acquisition, not build duration: the one-time build holds the eventlog lock without a duration cap, so use maintenance/pre-creation before resuming work. |
| #4.4 JDB growth | Confirmed, retained deliberately pending a supported safe representation. | Exact-wire conflict checks, claims/tombstones and cached receipts cannot be expired without changing replay/restore safety. |
| #4.5 archive dates | New release uses a fixed explicit 2026-10-28 member timestamp; missing future timestamp fails closed. | One active package remains required; actual MO2 timestamp preservation untested. Historical assets are unchanged. |
| #4.6 documentation | Restore missing changelog entries/current feature summary and document the separate companion observer. | Historical evidence stays labeled by exact version. |
| #4.7 licenses | MIT for project code/docs; CC BY 4.0 for project artwork, credit required. Notices ship in ZIP/DWPkg/tar. | Third-party dependencies retain their own terms. |
| #4.8 artwork | Re-encode the same 1536×1024 illustration at WebP quality 85: 2,331,264→484,714 bytes. | Lossy distribution image; source PNG unchanged and retained. |

## Safety and test authority

No letter ordering/pagination/cursor change, no trimmed body or reference set, no journal expiry, no new model retry and no transfer resend is introduced. Existing restore authority and identity checks remain the gate. PostgreSQL staging cannot roll back files, JContainers or inventory.

The exact final regression results, build identities and publication byte checks are recorded in the [publication review](DEV29-PUBLICATION.md). A passing source fixture is not Skyrim execution, installed-server compatibility or real-model acceptance.
