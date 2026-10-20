## CHIM Sealed Fates 0.1.0-dev.15 — PRE-ALPHA

Skyrim has one courier, and he is VERY good at his job. Let's reward him with love letters, forged apologies, and forwarded insults. I call it 'emergent storytelling' because 'I have made this man's working conditions worse' looks dreadful on a feature list.

The courier now has a proper illustrated web page. Still no assistant. Apparently I spent the staffing budget on an emo llama.

![The Sealed Fates courier, two very different letters, and the emo llama.](https://raw.githubusercontent.com/Francisco-boop-001/CHIM-SealedFates/correspondence-v0.1.0-dev.15/assets/dashboard-art-source.png)

### What changed

Sealed Fates now shares its siblings' parchment panels, serif headings, rust-red controls and slate-blue accents. The new Nordic woodcut stays uncropped, including the tiny emo llama, and the page follows your system light/dark preference. Forms adapt to narrow screens and wide mail tables scroll within their own panels.

The existing composer, inbox/outbox, delivery-status checks and autonomous-correspondence controls remain available. Authorization, CSRF validation, escaped output, mail actions and delivery/interpretation rules are preserved. This is a web presentation release; it does not change NPC reasoning, transfers or the database contract.

### Pick your interface

**The new in-game Skyrim interface is optional. The existing Sealed Fates web plugin page still works.** You can use the web controls without installing the new Skyrim interface. The matching Sealed Fates Papyrus bridge is a separate requirement for physical notes and item/gold transfers; this release reuses its unchanged verified PEX.

### Install

Use the paired dev.15 MO2 ZIP and its included server package together. It includes the new web artwork and stylesheet. Existing dev.14 archives and the historical recovery kit remain unchanged; no old kit is relabeled as a dev.15 integration test. See the [installation guide](https://github.com/Francisco-boop-001/CHIM-SealedFates/blob/correspondence-v0.1.0-dev.15/docs/INSTALL.md).

### Assets

- [Paired MO2 bridge and CHIM server package](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.15/CHIM-Sealed-Fates-0.1.0-dev.15.zip) — SHA-256 **d8ed9a0bc1eebf1084cc951c1a5c2fcabc9f47472f1eeaf6ce3bbd0af40d7888**
- [Server package](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.15/correspondence-0.1.0-dev.15.dwpkg) — SHA-256 **ed4c5bccd8bf2eb067304eb92d843faba15fe53e50767fb242a6b48977d9437b**
- [Server-only catalog archive](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.15/correspondence.tar.gz) — SHA-256 **d852da6170bce1625e5a104880d257b486358b5a42d30b1f75cb3a952c34df49**
- [Checksums](https://github.com/Francisco-boop-001/CHIM-SealedFates/releases/download/correspondence-v0.1.0-dev.15/SHA256SUMS.txt) — SHA-256 **f1ad6d68c1faff7ed1b1945196af8aa85f1d55e27e31c5911d3c8e96590ea3ed**

### Verification and limits

Fresh checks passed: **18 package tests**, deterministic builds of the four assets, **three pure PHP suites and four PHP lints** on the registered disposable server, and **12 browser scenarios** covering light/dark desktop, tablet and phone layouts, empty and opened mail, error views, long Unicode names, keyboard navigation and horizontal table panning. A synthetic composer submission was intercepted before any backend call. The browser caught a long-name heading overflow; wrapping was fixed and the full scenario set rerun. Final PHP source stayed unchanged during that CSS-only correction.

The approved PNG and packaged lossless WebP have identical decoded pixels. Native checks used synthetic data without database, core or provider calls; the disposable distro was stopped and handed back, with protected live VHD size and modification time unchanged. These are rendering and package results, not a deployed live-site or Skyrim gameplay result.

PRE-ALPHA. No new provider/model or Skyrim gameplay test is claimed. This release does not establish native note display, inventory/co-save atomicity, or reliable detection of forged authorship. The existing performance and recovery limits remain; see the [verification notes](https://github.com/Francisco-boop-001/CHIM-SealedFates/blob/correspondence-v0.1.0-dev.15/docs/VERIFICATION.md).
