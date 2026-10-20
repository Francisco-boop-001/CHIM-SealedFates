## CHIM Sealed Fates 0.1.0-dev.14 — PRE-ALPHA

Skyrim's inbox has acquired consequences. The developer is still capable of forgetting a plot thread; the package checksums are less sentimental.

This release pairs the CHIM Correspondence server package with its compiled Papyrus bridge. Letters can carry selected items or gold. A delivery receipt records the bridge's observed transfer; it does not prove the recipient read the note. NPC exposure happens only through that recipient's context render.

With compatible Mind Poisoning or Sworn & Scorned integrations enabled, NPCs may interpret exposed letters under those mods' normal rules, affecting opinions, grievances, gratitude, or evidence-backed obligations. Neutral outcomes remain valid; there is no fixed gratitude bonus, and a signature does not prove authorship.

Autonomous correspondence is off by default. Native decision ticks require global opt-in and Background Life plus Send letters for each NPC, and run on eligible native dialogue postrequests. A separate, opt-in, bounded offscreen text-mail worker can resume on fresh native observations so NPC mail can progress while the player is away.

### Assets

- [Paired MO2 bridge and CHIM server package](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.14/CHIM-Sealed-Fates-0.1.0-dev.14.zip) — SHA-256 **e6ef362295da9ffa1a22959f2ab7714ac18fc172f6404a4d930f458626937b1f**
- [Server package](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.14/correspondence-0.1.0-dev.14.dwpkg) — SHA-256 **20d3da4117479957471491d502126154e054b4543fdf32b03d4fb3f35ab6bd8c**
- [Optional recovery playtest kit](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.14/CHIM-Sealed-Fates-Recovery-Playtest-20261019-v1.zip) — SHA-256 **95e7d0dc429e7cb33ed8fb669d54973daffad8f39cebad08ca566e689c6790e3**
- [Server-only catalog archive](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.14/correspondence.tar.gz) — SHA-256 **ec81d61e54aaa68ab67a1a182f6af4fcd389f8c5e02e8f3d40a4539d536fa0be**
- [SHA256SUMS.txt](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.14/SHA256SUMS.txt) — SHA-256 **a79b735189489d5481e05ebc72c21757293b9ba76d76acb86bcd14f8a56dd627**

Use only the paired archive's matching dev.14 components for the main install. The tar archive has a server-only correspondence/ root and does not automatically appear in a CHIM catalog. See [installation](https://github.com/Francisco-boop-001/CHIM-SealedFates/blob/correspondence-v0.1.0-dev.14/docs/INSTALL.md).

### Verification and limits

The exact dev.14 candidate has prior results of 41 recovery/fault checks, 18 pure server regressions, and 13 package/privacy checks passed. The disposable official-manager fixture verified 90 payload files across the pinned three-package pairing without provider calls or core startup. Current release packaging is separate: **python scripts/release-assets.py** reproduced the pinned assets, and the release package suite passed 14 checks.

This publication step made no new provider/model evaluation and ran no Skyrim gameplay session. It does not establish native note display, inventory/co-save atomicity, or reliable model detection of forged authorship. Eventlog freshness scans and writer-lock costs remain known performance limits. See [verification notes](https://github.com/Francisco-boop-001/CHIM-SealedFates/blob/correspondence-v0.1.0-dev.14/docs/VERIFICATION.md).
