# Dev.23 PRE-ALPHA publication record

Release source: `01d62982d9b10ca4fce040af9d97fe7111231ba3` at immutable tag `correspondence-v0.1.0-dev.23`.
Published at 2026-10-24T22:50:01Z: [release and gameplay limits](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/tag/correspondence-v0.1.0-dev.23).

**Install one file:** [CHIM-Sealed-Fates-0.1.0-dev.23.zip](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.23/CHIM-Sealed-Fates-0.1.0-dev.23.zip).

| Asset | SHA-256 | Bytes |
| --- | --- | ---: |
| CHIM-Sealed-Fates-0.1.0-dev.23.zip | `6fbf1154df8de5eb34ef3d48658ea4ff43d2946742bdeab2247a58b7e9046260` | 2889222 |
| SHA256SUMS.txt | `467b8ff3ffcbbb36e2706e868b2e14fa59d18a43bbd78202b7a05c1045b40795` | 289 |
| correspondence-0.1.0-dev.23.dwpkg | `7b9002f37970011b3c41c5728aca8f138082f70826ed25ca3e56748f8b03ae81` | 2856503 |
| correspondence.tar.gz | `7595a7e717beafe4b426612b04da1775b510bf276918d18f1c42123c7d67456a` | 2436607 |

All four assets were rebuilt from a clean tag export, downloaded from the draft, then downloaded anonymously after publication and matched byte-for-byte. The MO2 ZIP and DWPkg match the verified repair candidate. Both compiled PEX files remain unchanged from that candidate. Earlier tags/assets were preserved.

The three bug reproductions have retained failing-before/passing-after results on isolated test2. The full companion recovery and actual-core idle fixtures pass; the tagged export also passes 18 affected pure suites plus companion policy, 60 package/release checks and 66 translated native-policy checks. Runtime/native source hashes match the tested repair; the [portable verification receipt](../release-inputs/dev23-verification.json) binds the server payload.

**Limits remain:** actual Skyrim native/Papyrus execution, first-new-game Narrator initialization, unloaded actor/framework observations, co-save/save-load behavior, renderer output and physical note/inventory delivery require gameplay tests. Earlier six model cases were prompt-development regressions, not an untouched held-out evaluation or full installed-connector acceptance. At this original publication, no dev.23 Department pairing was claimed. The subsequent [Department prealpha.13 pairing](PRISMA-COMPATIBILITY.md) adds server/API proof while retaining these gameplay limits. The optional interface is separate and the existing web page remains usable.

Publication changed GitHub/source documentation only. Live data, installed game, providers and production configuration were untouched; the owned test2 PostgreSQL cluster is stopped, tracked core is clean and protected live VHD metadata is unchanged. The tag preserves the release source; later main-branch publication notes do not change its payload.
