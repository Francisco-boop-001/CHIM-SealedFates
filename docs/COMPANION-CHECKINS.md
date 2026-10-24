# Companion check-ins — dev.23 PRE-ALPHA

This candidate adds a grounded waiting-companion letter opportunity. It does not infer abandonment from missing dialogue or an NPC disappearing from the party list. It is not in the published dev.22 download.

## What it does

With global autonomous correspondence enabled, a living NPC with Background Life and Send letters enabled can consider one attachment-free letter to the player after two observed game-days of explicit waiting apart. The NPC can write or stay silent. Personality and private affinity guide the tone; they do not establish promises, romantic involvement or shared history.

The first version supports vanilla `WaitingForPlayer` or CHIM's active wait package/faction while the NPC is still a player teammate. It changes no follower packages, factions, inventory, waiting limits or follower framework settings. Custom frameworks that expose neither supported waiting signal remain unknown.

The letter can say that the NPC is waiting and would like instructions. It must not claim that the player abandoned them, promised a return date, or has been continuously absent for a precise number of days. First observation is not the time the player gave an order.

CHIM's existing periodic native `request` heartbeat schedules the observation command through its built-in player-backed `The Narrator` agent. No recent NPC dialogue snapshot or nearby NPC is required. The waiting companion is resolved by the payload's stable identity, not by the carrier name. After a decision, normal transit is twelve game-hours. Before dispatch the same episode must still have a fresh waiting observation. Physical delivery uses the unchanged correspondence bridge and its receipt; a queued document or command is not proof that Skyrim delivered a note.

## Idle lifecycle and remaining limits

The first dev.23 candidate depended on a 45-second NPC presence snapshot refreshed by dialogue. The current idle candidate removes that dependency for companion observation and scheduler-owned delivery/reconciliation. It uses the existing native heartbeat and Narrator command route rather than extending old presence data or adding a timer, Quest, ESP or DLL. Other manual letters retain their existing executor rules. See [the exact idle route and test evidence](COMPANION-IDLE.md).

CHIM must be active and normally initialized. Interaction Off pauses polling and companion worker decisions. Pausing the game, recording, application menus and native maintenance suppression can delay its heartbeat. A missing Narrator produces no valid observation receipt and therefore no fabricated episode. Normal save/load initialization creates that agent; first-ever new-game initialization and actual command execution still need an in-game check. Large gaps continue to reset observation continuity.

Polling is bounded to four NPCs per game-hour, prioritizing up to three existing waiting episodes and reserving discovery capacity. The native observer resets continuity after an unknown state, a rewind or a sample gap over 120 game-minutes. A large number of simultaneously waiting companions can therefore miss opportunities rather than invent missing observations.

## State and safety rules

| Observed situation | Consequence |
| --- | --- |
| Explicit supported waiting, known separation, eligible NPC | Start or continue a sampled waiting episode. |
| Less than two observed game-days | No model decision. |
| Same cell, loaded actor within 1,024 units | Cancel the episode and any undispatched check-in. This is proximity, not proof of a conversation. |
| Non-teammate or supported wait condition no longer true | End the episode; do not infer dismissal or abandonment. |
| Unresolved identity, unknown location, ambiguous range or missing observation | Defer/reset; no complaint. |
| Global/NPC opt-out or confirmed death | Cancel pending work. |
| Save rewind, restore-token change or different playthrough | Old observation authority cannot create or dispatch a new complaint. |
| Model unavailable before an attempt | Leave a justified opportunity eligible. |
| Model attempt fails, times out or chooses silence | Do not repeat the attempt for that same actor/episode. |
| Delivery already dispatched, including an unknown result | Preserve existing reconciliation/no-resend safety. |

The native observer uses `Utility.GetCurrentGameTime()` in game-minutes. The server cross-checks that against the incoming native game timestamp; it does not accumulate server uptime. Fast travel and game waiting advance the game clock, but a large observation gap still resets continuity. CHIM's synthetic continuity-clock recovery after a rewind may temporarily disagree with calendar time; those samples fail closed.

Each model attempt obeys the existing one-decision-per-game-hour and six-hour per-NPC correspondence cooldowns. One actor's waiting episode gets one attempt, not a repeated mail campaign. The new module shares cooldown information with the existing schedulers. Manual send/retry cannot bypass ownership of a scheduled check-in.

Core Background Life retains an independent player-letter path. The candidate checks reliable recent BGL letter history before generating and dispatching a check-in. This conservatively suppresses overlap, but there is no atomic duplicate guarantee between independent writers without a shared ownership API.

## Why this design

The inspected native party snapshot contains nearby audible teammates, not a complete follower registry. CHIM's `StartWait` changes a package/faction without emitting a structured start observation. The follow command logs a response before executing Papyrus. None of those establishes a continuous separation interval.

The plugin therefore adds a separate, read-only global Papyrus extension, `CHIMCorrespondenceCompanion`, through CHIM's existing external-command interface. It returns correlated identities, sampled state, an episode identifier and calendar minutes. It does not send private dialogue, inventory or credentials. The existing physical delivery script and its build provenance remain unchanged.

The server binds each observation to its nonce, batch, playthrough, full restore authority and game clock. A deterministic wire epoch derives from the complete opaque restore token; it is not a stripped UUID that discards restore state. Actor identity is part of episode ownership, so companions first observed in the same batch cannot cancel each other's letters.

## Closely related ideas

| Idea | Player value | Evidence available | Cost / annoyance | Decision |
| --- | --- | --- | --- | --- |
| Waiting companion asks for instructions | High | New explicit waiting observations | Smallest useful scope; one attempt per episode | Implement this candidate. |
| Dismissed friend checks in | High | Party omission does not establish dismissal | Needs authoritative dismissal/framework support | Defer. |
| Reunion acknowledgement | Medium | Positive proximity is available, conversation is not | Easy to become repetitive or narrate an unwitnessed exchange | Use proximity for cancellation only. |
| Goal-motivated NPC writes to the player | High | Goals exist, but generated goal text is not separation evidence | Separate player-initiation policy and overlap analysis | Do not mix into abandonment logic. |

## Installation and verification status

The candidate is one MO2 ZIP containing the unchanged physical bridge, the new observer script and the server package. There is no second installation layer or required manual checksum calculation. Start in a disposable profile/server; publication is not native gameplay acceptance.

Server fixtures and translated Papyrus policy checks establish only their stated boundaries. Caprica compilation is not Papyrus VM execution. Actual unloaded-actor queries, native command scheduling, installed follower-framework behavior, co-save restoration, note display and real inventory delivery require a Skyrim test that has not been authorized here.

Current runtime evidence and limitations are retained under `artifacts/chim-sealed-fates-companion-20261024`. After explicit approval, six synthetic cases ran against the configured test2 BGL model, `deepseek-flash` at `api.deepseek.com`. Final cases produced five player-addressed, attachment-free queued letters and one silence. Immediate repeats made no second model call, and synthetic reunion retired the queued decisions. An independent review accepted all six final outputs for grounding, tone and written form.

The first prompt failed all six recipient fields (`Dragonborn` instead of the literal protocol value `player`). A subsequent mechanically passing run still invented a meeting place and watch duty; independent reading rejected it. Another run safely rejected an unsupported news report. The final short check-in prompt passed the same six regression cases. Earlier outputs are retained, not relabelled as successes. These cases were used to refine the prompt; they are not an untouched final hold-out set.

The real-model lane uses production message construction, decision parsing and database tick/composition with a private OpenAI-compatible HTTPS callback. It reads the configured credential in memory from test2 under a read-only transaction and verifies unchanged connector/badge rows. It does not bootstrap CHIM's installed driver or write its campaign/logs. Transport used JSON-object response mode and a 1,536-token response cap; production uses the existing CHIM connector factory. Thus this is real-model policy/route evidence, not installed connector or full game delivery acceptance. Phrase guards reject demonstrated unsupported claims; they are not a general semantic proof for arbitrary generated text.

Targeted PostgreSQL cases exercise replay, two-session claim contention, unknown dispatch/no-resend, save authority changes, playthrough isolation, lost observations, in-flight reunion, identity changes, operator closure, opt-outs and recent BGL mail. The corrected native state-creation path has a failing-before/passing-after regression; all 66 translated native checks pass. Package checks bind the rebuilt anonymous observer to its source. No new live install or release was performed.

## Evidence-to-delivery route

| Stage | Candidate code / authority |
| --- | --- |
| Poll selected BGL + Letters NPCs during idle play | `server/lib/companion.php::poll`; native request heartbeat and player-backed Narrator, with correlated payload identity |
| Resolve stable form, sample explicit wait/proximity and calendar | `game/Source/Scripts/CHIMCorrespondenceCompanion.psc`; `Utility.GetCurrentGameTime()` and actor/package queries |
| Validate correlation and receive trusted sample | `server/lib/companion_host.php`; `companion.php::receive`; nonce, epoch, identities, native clock |
| Retain bounded episode and one-attempt decision | `server/migrations/005_companion.sql`; existing writer/restore locks |
| Wake worker and evaluate optional letter | `server/preprocessing.php`, `server/offscreen-worker.php`; strict `companion_policy.php` messages/decision |
| Queue player-addressed note, then recheck before due dispatch | `companion.php::tick/due`; existing `CorrespondenceService` and unchanged physical bridge |
| Prove delivery | Existing correlated bridge receipt; test fixtures supply synthetic receipts only, so actual Skyrim receipt remains unproved |

Inspected native source was CHIM `5ca1bc361d1e11a3c58067599b494b1101c2c77b` with retained working-file hashes, and test2 core was `468584f7bfc34c2eaf76e6188efaf3294f4fd63c`. Source inspection and a successful compiler exit do not prove the installed DLL/PEX executes this path.

The two-day threshold leaves transit room before a potential ordinary three-day follower wait timeout; that timeout is an externally corroborated compatibility concern, not a verified property of the installed framework. See the [reproduced vanilla follower script](https://www.gamesas.com/can-someone-post-the-vanilla-dialoguefollowerscript-t260493.html). This plugin changes no follower timeout.
