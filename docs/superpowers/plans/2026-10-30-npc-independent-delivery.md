# NPC-independent delivery implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans task-by-task. Root coordinates the existing user-selected Luna/max workers and owner chats.

**Goal:** Send saved manual letters without a nearby NPC through the same safe web/Department owner service.
**Architecture:** Reuse the existing Narrator carrier and 45-second native-activity authority in the existing presence row. Preserve all restore, identity and operation fences.
**Tech Stack:** Existing PHP/PostgreSQL/CHIM external-command transport; no dependency or native binary addition.
**Spec:** ../specs/2026-10-30-npc-independent-delivery-design.md

## Global constraints

No live/game/provider access. Test2 only through reviewed owned isolation. Treat native ts as opaque, not Unix time. No migration, auto retry, replacement letter or weakening of legacy/version checks. Department owns its code and source pins; preserve its concurrent UI release.

## Tasks

- [ ] Owner worker first writes causal PG reproductions for zero-NPC manual delivery and safeguards, records RED against existing production path, then implements only store/preprocessing/shared typed helper changes. Existing managed snapshots remain compatible. Include strict Narrator authorization, active/passive transitions, replay and 45-second expiry.
- [ ] Harness worker prepares private frozen-source/socket-only isolated test2 runner, ownership/identity/network/cleanup receipts and raw outputs. Root reviews before execution; no shared database or campaign config.
- [ ] Root runs offline suite and new PG RED/GREEN/regressions on test2; exercise current core queue/channel framing and document no NPC/model requirement. Preserve failed fixtures without relaxing guards.
- [ ] Freeze owner dev.31 source; Department ports exact source pins and version-aware recovery wording in its own component, then verifies web/Department parity, same saved operation and unsupported older combinations. No optional-UI dependency in the web path.
- [ ] Fresh independent bughunt of full changed boundaries; fix valid findings and rerun affected tests until no unresolved in-scope finding remains.
- [ ] Root updates versioned manifests/package callers, user instructions and explicit gameplay limits; commit only reviewed files, build reproducibly, validate official package archives, publish exact verified assets and confirm older tags/assets unchanged. Department publishes its matching verified consumer separately.

## Review focus

Passive native traffic must not authorize delivery; equal/older counters cannot renew wall TTL; activity/snapshot writes cannot clobber each other; stale/null/changed restore tokens and pre-floor activity remain refused; retries and concurrent UI clients must retain the original document/operation and never duplicate transfers. Each condition belongs in the owner/consumer regression cases above.
