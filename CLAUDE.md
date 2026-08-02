# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP-based Telegram bot that handles group chat interactions with various features including:
- AI-powered responses using Ollama API (Gemma 4) with modular intent dispatcher
- Order management system ("pappatoie" - food ordering)
- Image handling, vision analysis, image recall via sub-agent, AI image generation via ComfyUI, and image → 3D model conversion
- Quiz/trivia system with Wikipedia integration
- Event management with multi-step workflows
- TTS (Text-to-Speech) via voice clone
- Context-aware conversation capabilities
- User profiling/memory system
- Automated cron tasks: daily recap, DJ/news, press digest

## Architecture

### Core Components

1. **Entry Point**: `bot.php` - Main webhook handler that processes Telegram updates
2. **Configuration**: `config.php` - Contains bot token, API endpoints, model settings (DO NOT deploy - contains credentials)
3. **Database**: SQLite database (`telegram_bot.sqlite`)
4. **Dispatcher**: `include/dispatcher.php` - Modular intent classifier and sub-agent router

### Module Structure (`include/` directory)

- `api.php`: Telegram Bot API wrapper functions
- `database.php`: Database initialization, schema management, migrations
- `ai.php`: Ollama AI integration — `rootbotPersona()` (shared personality), `_ai_core()` (main response), `analyzeImage()` (vision), `_saluto()` (evening recap), `_dj()` (spontaneous commentary), Wikipedia functions
- `dispatcher.php`: Modular intent classifier — loads sub-agents, builds dynamic classifier prompt, routes to handlers
- `message.php`: Message processing, command routing (elseif chain), `sendPrivateResponse()`
- `orders.php`: Food ordering system ("pappatoie") management
- `events.php`: Event creation, participation, multi-step workflows (`handleEventInput()`)
- `moderation.php`: On-demand silence, link shaming (X/Facebook) and the "porto al root" flow. Does **not** reprimand anyone for swearing and keeps no statistics — the word lists, blasphemy patterns and `$lecit_words` whitelist were removed, along with `/stats`. Still disabled at `message.php:157`
- `image.php`: Image download and storage handling
- `help.php`: Help command responses
- `user_memory.php`: User profile memory system (extractors, aggregators)
- `group_memory.php`: Group memory — the variable part of the system prompt. Two blocks (`osservato` written by cron, `corretto` written only by admins), injected into every text-generating prompt
- `QBertClient.php`: LLM queue client (ticket-based polling, priority levels)

### Sub-Agent System (`include/agents/` directory)

The dispatcher uses a **modular, plug-and-play sub-agent architecture**. Each agent is a **self-contained directory** `include/agents/{id}/` that owns its declaration, DB schema, workflow files and helper functions. Installing/removing an agent is a drag-and-drop of its directory — no other file needs to change.

At bootstrap, `loadAgentRegistry()` globs `include/agents/*/agent.php`; `initAgentSchemas($db)` (called at the end of `initDatabase()`) invokes each agent's `schema` callable to create/migrate its tables idempotently.

#### Agent Directory Layout

```
include/agents/{id}/
├── agent.php            # declaration array (required entry point)
├── workflows/*.json     # optional: ComfyUI workflows
└── *.php                # optional: helper modules (require_once'd by agent.php)
```

#### Agent Declaration Format

```php
return [
    'id' => 'agent_name',
    'description' => "When this agent should be triggered (in Italian, used by classifier)",
    'parameters' => [
        'param_name' => "Description of what to extract",
    ],
    'handler' => function(array $ctx, array $params): ?array { ... },
    // Optional fields:
    'default' => true,              // Fallback agent (only one)
    'enriches' => 'other_agent_id', // Enrichment pattern (runs before target agent)
    'sends_own_response' => true,   // Agent sends its own Telegram messages
    'help' => "Text block appended to /help output (see help.php)",
    'schema' => function(SQLite3 $db): void {
        // CREATE TABLE IF NOT EXISTS ..., ALTER TABLE guarded by PRAGMA table_info,
        // INSERT OR IGNORE seeds, DELETE cleanup policies. Must be idempotent.
    },
];
```

Helper functions defined at require-time inside `agent.php` (before the `return`) become globally available, e.g. `saveImageLog()` from `image_query/agent.php` is used by `bot.php` in the vision flow.

#### Current Agents

- **`chat/`** (default): Normal conversation — calls `_ai_core()` with optional enrichments
- **`wikipedia/`** (enriches `chat`): Factual/encyclopedic questions — searches Wikipedia, injects context into chat prompt
- **`quiz/`**: Quiz/trivia requests — calls `generateAndSendQuiz()`, sends its own poll. Bundles `quiz.php` (full quiz logic: Wikipedia search, dual LLM generator+reviewer, leaderboard, topic admin) and owns tables `quiz_topics`, `quiz_history`, `quiz_responses`
- **`image_query/`**: Questions about previously shared images — matches reference via LLM light, re-analyzes with `analyzeImage()`. Owns table `image_log` and exposes `saveImageLog()` / `getRecentImages()`
- **`image_gen/`**: Image generation via ComfyUI — bundled workflow `workflows/z_image_turbo.json`. Rate-limited (6/hour) via owned table `image_gen_usage`
- **`audio_gen/`**: Music/song generation via ComfyUI (ACE-Step 1.5 XL Turbo) — bundled workflow `workflows/ace_step1_5_xl_turbo.json`. Rate-limited (6/hour) via owned table `audio_gen_usage`
- **`3D_gen/`** (id `3d_gen` — nota: la directory è maiuscola, l'id no): Image → 3D model (`.glb`). Two backends: standard via ComfyUI/Hunyuan3D v2.1, HD via TRELLIS.2. Rate-limited (6/hour) via owned table `threed_gen_usage`
- **`profile_self/`**: Tells the user what the bot has memorized about them (reads `memorie_utenti`)
- **`workload/`**: Answers "sei libero?" / "che stai facendo?" — reports the bot's own current activity

#### Classifier Flow

1. User mentions bot or writes in private chat
2. `dispatchIntent()` calls `classifyIntent()` with the message + last 3 chat messages as context
3. Classifier uses `OLLAMA_MODEL_LIGHT` to return JSON: `{"intent": "...", "params": {...}}`
4. Dispatcher routes to the matching agent handler
5. Enrichment agents (e.g., wikipedia) run first, then pass data to the target agent
6. Fallback to default agent (chat) if classification fails

### Bot Personality

Shared personality defined in `rootbotPersona()` (ai.php), used across all prompts:
- Observer of humanity, curious and benevolent
- Mix of Bender (Futurama) and Sheldon (Big Bang Theory)
- Sarcastic but never rude, affectionate teasing
- Each prompt adds context-specific instructions on top of the shared persona

### Message Flow

1. Telegram sends webhook updates to `bot.php`
2. `bot.php` responds 200 immediately, then processes in background
3. **Anti-spam**: `isDuplicateMessage()` checks for duplicate messages within 60s window
4. Image analysis happens BEFORE `processMessage()` — descriptions saved to context + `image_log`
5. **Reply-to-image vision**: if user replies to an image mentioning the bot, re-analyzes with the question as prompt
6. `processMessage()` routes via elseif chain:
   - Multi-step event input (`handleEventInput`)
   - Explicit commands (`/quiz`, `/ordino`, `/evento`, `/saluto`, `/dj`, `/genera`, etc.)
   - **Dispatcher** (bot mentioned or private chat) → classifier → sub-agent
   - Fallback command suggestion (`_suggerisci_comando`)
7. Generic response block sends with TTS button, HTML parse, and retry logic
8. **Edited messages**: `edited_message` updates are processed if bot is mentioned and hasn't already replied (tracked in `bot_replied` table)

### AI Models (Gemma 4)

Configured in `config.php`:
- `OLLAMA_MODEL` (`gemma4:26b` GPU): Main responses, quiz generation
- `OLLAMA_MODEL_LIGHT` (`gemma4:e4b`): Intent classification, Wikipedia classification, quiz review, subtopic generation
- `OLLAMA_MODEL_VISION` (`gemma4:e4b`): Image analysis

All LLM calls go through QBert queue system (`QBertClient.php`) with priority levels: URGENT, NORMAL, LAZY.

### Image Handling

Images follow two paths:
1. **bot.php** (before processMessage): Vision analysis via `analyzeImage()`, saves `file_id` to `image_log` table for future recall, saves description to chat context
2. **message.php** (inside processMessage): Menu image storage for pappatoie

The `image_query` agent can retrieve and re-analyze past images by matching user references against saved descriptions.

### Image Generation

The `image_gen` agent generates images via ComfyUI (z-image turbo workflow):
1. Italian prompt is translated/enhanced to English via LLM (`OLLAMA_MODEL_LIGHT`)
2. Workflow JSON is loaded from `workflows/image_z_image_turbo.json` and parameterized
3. Submitted to ComfyUI via QBert, polls `/history` until output is ready
4. Image downloaded and sent to chat as photo
- Supports formats: landscape (1280x720), portrait (720x1280), square (1024x1024)
- Rate limit: 6 images/hour per user (tracked in `image_gen_usage` table)
- Recent chat context used to resolve references ("fanne un'altra ma con...")

### Audio/Music Generation

The `audio_gen` agent generates songs via ComfyUI (ACE-Step 1.5 XL Turbo):
1. LLM (`OLLAMA_MODEL` full) receives the ACE-Step compact guide as system prompt and composes the full brief
2. LLM output format parsed: `##CAPTION:` (style tags), `##LYRICS:` (structured lyrics), `#BPM:`, `#KEYSCALE:`, `#LANGUAGE:` (ISO 2-letter), `#DURATION:` (seconds)
3. Parser applies clamps: BPM ∈ [60,200], DURATION ∈ [10,210]; KEYSCALE is validated against the `{Note}{Accidental?} {major|minor}` whitelist (first valid match wins, fallback `C major`, normalization logged); fallbacks for missing fields; aborts before ComfyUI if CAPTION or LYRICS missing
4. Workflow `workflows/audio_ace_step1_5_xl_turbo.json` loaded and parameterized (nodes `94` tags/lyrics/bpm/keyscale/language/duration, `98` seconds, `109` seed)
5. Polls `/history` up to `AUDIO_GEN_POLL_TIMEOUT` (420s), fetches MP3 from `/view?...&subfolder=audio`, sends via `sendAudio` with `title`, `performer="rootbot"`, `duration`
- Rate limit: 6 songs/hour per user (tracked in `audio_gen_usage` table)
- Recent chat context used to resolve references ("stesso stile ma più lento", "fanne uno in italiano")

### 3D Model Generation

The `3d_gen` agent (directory `include/agents/3D_gen/`) turns an **image** into a `.glb` 3D model. It never generates from text: an image is always required.

**Source image resolution** (in order):
1. **Reply to a photo/image document** — takes the largest `photo` size, or a `document` accepted by `isImageDocument()` (needs `ctx['raw']`)
2. **Fallback on `image_log`** — the `riferimento` parameter is matched against the last 10 images. `ultima`/`recente`/`prima`/`precedente`/empty short-circuits to `image_log[0]`; anything else goes through `OLLAMA_MODEL_LIGHT`, which picks the index (`0` = no match → the agent gives up and says so)

**Two quality tiers**, chosen by a regex on the raw message text (`$raw['text']` or `$raw['caption']`), *not* by the classifier:

| Tier | Trigger | Backend | Notes |
|---|---|---|---|
| standard | default | ComfyUI + Hunyuan3D v2.1 | async: `/upload/image` → `/prompt` → poll `/history` → `/view` |
| HD | `hq`, `alta qualità`, `qualità superiore/alta/massima`, `high quality`, `massima qualità` | TRELLIS.2 (QBert service `trellis`, `POST /generate`) | synchronous, ~60s, returns the GLB bytes directly |

TRELLIS.2 params: `resolution=1024`, `decimation_target=500000`, `texture_size=2048`, random seed.
Since the trigger is a plain regex on the user's text, the keyword must appear in the message itself — the classifier does not carry it into `params`.

**Blocking**: both tiers occupy the PHP process for the whole generation — `QBertClient::post()` is `submit()` + `waitForTicket()`, and `waitForTicket()` is a blocking poll loop by design (its docblock: *"ATTENZIONE: blocca, usare solo in script CLI/worker"*). QBert serializes GPU access between apps; it does **not** free the caller. To actually release the process, the flow would have to move to `submit(callbackUrl: ...)` plus a callback endpoint. The `/history` poll loops in `image_gen`, `audio_gen` and `3d_gen` each have their own deadline constant (`*_POLL_TIMEOUT`) because the QBert timeout applies per request, not to the loop.

**ComfyUI workflow** `workflows/3d_hunyuan3d-v2.1.json` (checkpoint `hunyuan_3d_v2.1.safetensors`). Only two nodes are parameterized: `2` (`LoadImage.image` = uploaded filename) and `7` (`KSampler.seed`). Fixed values: latent resolution 4096, 30 steps, cfg 5, euler/normal; `VAEDecodeHunyuan3D` octree 256 / 8000 chunks; `VoxelToMesh` surface-net @ 0.6; output node `10` is `SaveGLB`. The poll scans **all** groups under `outputs['10']` for the first `*.glb` filename, because SaveGLB exposes it under varying keys (`3d`, `result`, `gltf`).

**Delivery**: the GLB is saved to `models/{32-hex-uuid}.glb` at project root, sent as a Telegram document (`model/gltf-binary`, `model.glb`) with an inline **"Anteprima 3D"** button pointing at `viewer.php?id={uuid}`. The viewer validates the id against `/^[a-f0-9]{32}$/` and renders the model with `<model-viewer>` 3.5.0 (auto-rotate, camera controls, AR via webxr/scene-viewer/quick-look). The unguessable id is what protects the file — there is no other auth.

- Rate limit: 6 models/hour per user (`threed_gen_usage`)
- `models/` is created on demand; lazy cleanup deletes `.glb` files older than 30 days on every run
- Status message ("Sto generando il modello 3D...") is deleted at the end; `upload_document` chat action is refreshed every 3s while polling
- On success writes `[modello 3D generato]` to `contesto_chat`
- Logs to `logs/3d_gen.log`

### Cron Jobs

- **`cron_saluto.php`** — Daily evening recap at 23:50, calls `_saluto()` and sends to main group with TTS button
- **`cron_dj.php`** — Spontaneous DJ commentary. **Runs every 15 minutes**, not hourly (measured: median gap of 14.9 min between runs, plus the script's own `sleep(rand(0,300))` jitter), Hacker News integration, configurable probability (80%), min 2h between posts, min 3 messages in the last 6h to trigger. The dice only decide whether to *attempt*: whether anything is actually posted depends on the quality gates inside `_dj()` (verifiable Wikipedia fact, HN fallback with its own cap, final LLM judge). Both source branches deduplicate against what has already been published: HN via the `hn_posted` table, Wikipedia via `bot_state['dj_wiki_terms']` (last 15 entry titles, 7-day window). The wiki list is both injected into the hook prompt — so the model looks for a different angle instead of falling silent — and enforced after it, before the Wikipedia lookup. Entries are burned only on actual publication, so a run rejected by the judge does not consume one
- **`cron_rassegna.php`** — Morning press digest at 08:00, fetches from rootclub.it/news/. Considers articles from the last 48h (buffer against skipped runs); dedup via `rassegna_posted` table (URL as PK) ensures no duplicates across days
- **`cron_tasks.php`** — Generic scheduler for batch jobs, every 15 minutes. Currently runs one task: `group_memory` (hourly, see "Group Memory"). Holds a `$TASKS` registry (`descrizione`, `ogni` = minimum seconds between runs, `run` = callable); last-run timestamps live in `bot_state` under `task_last_{name}`. Own lock in `/tmp/rootbot_tasks.lock`, released after `TASKS_TICK_BUDGET` (600s) if a run dies. Tasks that don't start because the tick budget ran out are logged explicitly, so a backlog that never shrinks doesn't look like a backlog that was already empty. CLI: `--list`, `--task=name`, `--force`. Every task runs at `PRIORITY_LAZY` — that, not a scheduling trick, is how GPU contention is handled. Deliberately *not* grafted onto `cron_dj.php`'s early-exit branches: that would couple unrelated features through the DJ's lock and its posting cadence

### Crontab

What is actually scheduled on the server. Paths are under the deploy root; each script also states its own schedule in its docblock.

| when | script |
|---|---|
| `0,15,30,45 * * * *` | `cron_dj.php` — every 15 min (the docblock said hourly until 2026-08-02; measured median gap is 14.9 min) |
| `0,15,30,45 * * * *` | `cron_tasks.php` — batch scheduler |
| `50 23 * * *` | `cron_saluto.php` |
| `0 8 * * *` | `cron_rassegna.php` |
| every 15 min, 00:00–06:00 | `cron_memory.php` |

A missing crontab line is invisible from the code: `cron_tasks.php` existed and worked for hours while only ever being triggered by hand over HTTP. To tell the difference, look for the unconditional heartbeat each scheduler writes — `cron_tasks.php` logs `nessun task scaduto` on every tick, so a gap longer than the interval in `logs/tasks.log` means it is not actually scheduled.

## Database Schema

### Tables Overview

1. **pappatoie** - Restaurants/food places
   - `id`, `pappatoia` (name), `indirizzo`, `telefono`, `giorni_chiusura`

2. **immagini_pappatoie** - Restaurant images/menus
   - `id`, `pappatoia_id` (FK), `immagine` (file path)

3. **ordini** - Order records
   - `id`, `data`, `pappatoia` (FK), `ordinante`, `ordinante_name`, `ritirante`, `ritirante_name`

4. **elementi_ordini** - Individual order items
   - `id`, `id_ordine` (FK), `utente`, `user_name`, `descrizione`, `delegato_da`

5. **contesto_chat** - Message context for AI
   - `id`, `group_id`, `user_name`, `message_text`, `timestamp`, `user_id`

6. **profanity_stats** — *unused*. Nothing writes to it and nothing reads it: the counting feature was removed (see "Removed features"). The table is left in place, harmless

7. **bot_silence** - Bot silence management
   - `id` (always 1), `silence_until`

8. **user_states** - User interaction states
   - `chat_id`, `user_id`, `state`, `data`, `created_at`

9. **eventi** - Events
   - `id`, `descrizione`, `data_ora`, `costo`, `creatore_id`, `creatore_name`, `chat_id`

10. **partecipanti_eventi** / **ospiti_eventi** - Event participants and guests

11. **quiz_topics**, **quiz_history**, **quiz_responses** — owned by the `quiz` agent, schema declared in `include/agents/quiz/agent.php`

12. **image_log** — owned by the `image_query` agent (`include/agents/image_query/agent.php`). Stores `file_id` + description of recent images for recall. Auto-cleanup: 30 days.

15. **memorie_utenti** - User profile memories
    - `user_id` (PK), `user_name`, `profilo`, `message_count`, `last_processed_msg_id`, `last_updated`

16. **tts_cache** - TTS voice file cache
    - `chat_id`, `message_id`, `voice_file_id`, `created_at`

17. **hn_posted** - Hacker News stories already posted by DJ

18. **bot_state** - Generic key-value bot state

19. **message_dedup** - Message deduplication (anti-spam, 60s window)

20. **bot_replied** - Tracks messages the bot has replied to (for edited_message handling)
    - Auto-cleanup: records older than 24 hours

21. **storico_messaggi** - Historical messages (imported from Telegram Desktop export, no pruning)
    - `id`, `group_id`, `telegram_msg_id`, `user_id`, `user_name`, `message_text`, `timestamp`
    - UNIQUE on `(group_id, telegram_msg_id)` for idempotent import

22. **image_gen_usage**, **audio_gen_usage**, **threed_gen_usage** — rate-limit tables owned by the `image_gen`, `audio_gen` and `3d_gen` agents respectively (schema in each agent's `agent.php`). Auto-cleanup: 1 hour.

24. **memoria_gruppo** / **memoria_gruppo_versioni** — group memory (see "Group Memory")
    - `group_id` (PK), `osservato`, `corretto`, `last_processed_id`, `updated_at`
    - Versions keep the last `GROUP_MEMORY_KEEP_VERSIONS` (20) *previous* texts per block, for diff and rollback
    - Declared by the `group_memory` agent's `schema`, and directly by `cron_tasks.php` (which doesn't load the agent registry)

23. **rassegna_posted** - Articles already posted by morning press digest (dedup)
    - `url` (PK), `title`, `posted_at`
    - Auto-cleanup: records older than 30 days

## Development Commands

### Deploy
```bash
# Deploy specific files
./deploy.sh include/ai.php include/message.php

# Deploy everything (excludes config.php, deploy.sh, .sqlite, debug.log)
./deploy.sh --all
```
**IMPORTANT**: `config.php` is a protected file and will NOT be deployed — it contains real credentials. Edit it manually on the server.

### Database inspection
```bash
sqlite3 telegram_bot.sqlite ".tables"
sqlite3 telegram_bot.sqlite ".schema TABLE_NAME"
sqlite3 telegram_bot.sqlite "SELECT * FROM pappatoie;"
sqlite3 telegram_bot.sqlite "SELECT COUNT(*) FROM image_log;"
```

### Testing locally
Since this is a webhook-based bot, you'll need:
1. A public URL (configured in `config.php` as `WEBHOOK_URL`)
2. Valid Telegram bot token (configured as `BOT_TOKEN`)
3. PHP 8+ with SQLite3, cURL, JSON, Intl extensions
4. Write permissions for `images/` directory and SQLite database

### Adding a New Sub-Agent
1. Create a directory `include/agents/your_agent/` with `agent.php` returning a declaration array
2. Define `id`, `description` (in Italian), `parameters`, `handler`
3. If the agent needs DB tables, add a `schema` callable (see "Agent Declaration Format" above) — it runs on every bootstrap, must be idempotent
4. If the agent has workflow files or helper PHP modules, put them inside the directory and reference them with `__DIR__`
5. Deploy — the dispatcher discovers it automatically via `glob('include/agents/*/agent.php')`
6. Check `dispatcher.log` on the server for classification results

### Removing a Sub-Agent
Delete the agent's directory. The classifier no longer offers that intent; the owned tables remain in the DB (harmless) and can be dropped manually if desired. Help text follows automatically for agents that declare a `help` field (`_help()` aggregates it from the registry). **Note:** any hardcoded slash-command aliases in `message.php` must still be removed manually.

### Log Files

Tutti i log vivono in `logs/` sul server. La risoluzione del path passa sempre per `logPath('channel')` definito in `include/logger.php`, che gestisce anche la rotazione automatica (soglia 10 MB, 1 backup in `$channel.log.1`). Per righe semplici c'è anche `logLine('channel', $msg)`.

Canali attivi:
- `logs/debug.log` — Telegram updates, general debug
- `logs/ai.log` — AI prompts and responses
- `logs/dispatcher.log` — Intent classification results
- `logs/wiki_search.log` — Wikipedia classification and searches
- `logs/dj_debug.log` — DJ/spontaneous comment debug
- `logs/saluto.log` — Evening recap diagnostics
- `logs/image_gen.log` — Image generation agent debug
- `logs/audio_gen.log` — Music/audio generation agent debug
- `logs/3d_gen.log` — 3D model generation agent debug (source resolution, ComfyUI/TRELLIS.2 calls, viewer URL)
- `logs/image_debug.log` — Image download/processing debug
- `logs/memory.log` — User memory extraction diagnostics
- `logs/memory_cron.log` — Nightly user-memory cron job
- `logs/quiz.log` — Quiz generation pipeline
- `logs/tasks.log` — `cron_tasks.php` scheduler: what ran, what was skipped and why
- `logs/group_memory.log` — Group memory: full block text on every change, diff counts, cursor
- `logs/telegram.log` — Telegram API wrapper (retry, errors)

### Group Memory (variable system prompt)

`rootbotPersona()` stays a constant — the bot's character is not up for negotiation. `include/group_memory.php` holds what the bot has *learned* about the group: facts and conventions, names, who handles what. It is injected into `_ai_core()`, both `_saluto()` prompts and both `_dj()` generation branches, so the bot's various outputs sound like one head rather than disconnected features. (`cron_rassegna.php` uses no LLM, so there is nothing to inject there; `_suggerisci_comando()` and the DJ hook/judge stages are deliberately excluded — they're classifiers, group lore would only cost tokens.)

**Two blocks, and the separation is the whole design:**

| block | written by | can cron overwrite it? |
|---|---|---|
| `osservato` | the hourly cron, from real messages | yes, every run |
| `corretto` | **only a group admin**, from private chat | **never** |

They're concatenated at injection time with `corretto` **last**, so on contradiction the admin wins. Without this the feature would die on first use: admin corrects at 15:00, cron regenerates at 15:30, correction gone, nobody ever uses the command again. It's also the defence against prompt injection — a troll can pollute `osservato`, but the admin's rebuttal in `corretto` cannot be automatically overwritten.

**The cron asks for a diff, not a rewrite.** `buildGroupMemoryPrompt()` numbers the existing lines and requests `{"aggiungi": [...], "rimuovi": [n, ...]}`; `applyGroupMemoryDiff()` (pure, DB-free, unit-tested) applies it in PHP. This is not a style choice — rewriting the whole block each hour is photocopying a photocopy. Measured over five real iterations of the earlier full-rewrite version: `root_camp_fratta` → `root_camp_francia`, `piedi di cobalto/balsa` → `buna`, `riferimenti surreali` → `riferella surreoli` (not Italian), plus a hard cut mid-word. With diffs the existing lines never pass through the model's output and survive verbatim.

Guards in `applyGroupMemoryDiff()`: out-of-range line numbers ignored; a request to delete *every* line is refused wholesale; near-duplicates dropped via punctuation-insensitive comparison; added lines capped at 300 chars and rejected under 10. `groupMemoryJoinLines()` enforces `GROUP_MEMORY_MAX_LEN` (2000) by dropping **whole lines from the end**, except when a single line already exceeds the cap — then it's truncated, because emptying the block is worse. Failed JSON parse logs its own distinct cause and does **not** advance the cursor.

**Compaction.** Notes grow untidily: filler openings (`Il gruppo…`, `Si parla di…`), near-duplicates, and one-off chat chronicle. Two counter-measures. At insertion, the prompt bans chronicle formulations (`Si parla di` is itself the tell), one-off technical advice, and filler subjects — the section header already says whose group it is. Then `compactGroupMemory()` runs a dense rewrite, but **only above `GROUP_MEMORY_COMPACT_AT` (1500 chars)**, so rarely: it *is* a full rewrite, i.e. exactly the generation-loss risk the diff mechanism exists to avoid. It is fenced in — temperature 0.1, an explicit order to copy proper nouns letter by letter, and three refusals: an empty answer, a result no shorter than the input (it rewrote rather than compacted), or a cut below a third of the lines. The previous text stays in history either way. First real run: 20 → 14 lines, 1739 → 1236 chars, with `root_camp_fratta`, `rootcamp.rootclub.it`, `vokoscreenNG` and `vitello dai piedi di cobalto/balsa` all intact.

**Known limitation:** a line that is garbled *at insertion* stays garbled — freezing protects against decay but not against a bad birth. That's what the admin correction path is for.

The full block text is written to `logs/group_memory.log` on every change, not just its length: it enters every prompt and rewrites itself hourly, so the on-disk history is the only way to diagnose drift later.

**Admin interface** — the `group_memory` sub-agent, private chat only, admins only. Natural language, no slash commands: "cosa hai capito del gruppo?", "aggiungi che...", "togli la parte su...", "com'era prima?". Note `groupMemoryUserIsAdmin()` checks against `MAIN_GROUP_ID`, **not** the current chat — in a private chat nobody is an administrator, so checking `$chatID` would always deny.

The edit path uses a diff too, over **both** blocks: `groupMemoryPlanEdit()` returns `{"a_rimuovi": [n], "b_aggiungi": [...], "b_rimuovi": [n]}`, where A is `osservato` (admins may only *remove* — adding there is pointless, the cron rewrites it) and B is `corretto`. Correcting a bad line therefore means: drop it from A, put the fixed sentence in B. That also stops the cron re-adding it, since `corretto` is fed to the cron prompt as already-established and not-to-be-repeated.

The first version rewrote `corretto` wholesale, and failed on its first real use: asked to fix a word inside a line the *cron* had written, it edited the wrong block (leaving the bad line in place) and replaced `corretto` with the single word "espressioni". `applyGroupMemoryDiff()` takes `$consentiSvuotamento` — false for the cron (a model asking to delete everything has lost the plot), true for the admin path (an explicit human order, reversible from history). The handler now reports what actually changed and says so plainly when nothing did; the old one claimed success unconditionally.

Maintenance: `cron_tasks.php --reset-group-memory` clears `osservato` and the cursor, leaving `corretto` untouched; `--clear-corretto` empties `corretto` (previous text kept in history).

### Removed features

- **Profanity leaderboard (`/stats`)** — removed 2026-08-02. Counting ran on `strpos()` without word boundaries, so "figa" fired inside "figata" and "cazzo" inside "cazzotto"; the blasphemy branch and its `$lecit_words` whitelist had already been commented out, and the call site at `message.php:157` was disabled, so nothing had been counted for a long time. An LLM classifier was built and measured against the group's real messages: it scored well on a clean synthetic set (0 false positives) but on real, messy chat it proposed "lavoro", "non", "civile", "signora" and "gay" as profanity. A leaderboard ranks real people, so the precision bar is high; reaching it meant a manual term-approval queue — too much upkeep for a joke feature. `profanity_stats` remains in the DB, unused and unwritten. The immediate reprimands were removed on their own merits: telling someone off for how they talk isn't funny in a group of friends.

### Utility Scripts
- `admin_user_memory.php` — Web interface for user memory management (protected by `MEMORY_ADMIN_TOKEN`)
- `extract_user_memory.php` — CLI tool for user profile extraction
- `import_telegram_export.php` — Import Telegram Desktop JSON exports into `storico_messaggi`
- `clear_opcache.php` — Reset OPcache on server
- `viewer.php` — Public interactive viewer for the `.glb` files produced by `3d_gen`; reads `models/{id}.glb`, no auth beyond the unguessable id

### Configuration Constants
Key settings in `config.php`:
- `OLLAMA_MODEL` / `OLLAMA_MODEL_LIGHT` / `OLLAMA_MODEL_VISION`: AI models
- `OLLAMA_QUIZ_SUBTOPICS` / `OLLAMA_QUIZ_GENERATOR` / `OLLAMA_QUIZ_REVIEWER` + `_GPU` variants: Quiz-specific models
- `QBERT_URL`: Gateway URL for QBert priority queue service
- `BOT_NAME`: Name set on BotFather, used as `appName` for QBert
- `SILENCE_DURATION`: Bot silence period (default: 120 seconds)
- `RESPONSE_PROBABILITY`: AI response probability (default: 0.8)
- `TTS_ENABLED` / `TTS_VOICE_PROFILE` / `TTS_LANGUAGE`: Text-to-Speech settings
- `IMAGE_SAVE_PATH`: Local storage for downloaded images
- `MEMORY_ADMIN_TOKEN`: Optional token to protect `admin_user_memory.php` web interface
- Locale set to Italian (`it_IT.utf8`) with Rome timezone

### Telegram IDs
- `MAIN_GROUP_ID`: -1001402757977 (gruppo principale) — defined in `include/ai.php`, **not** in `config.php` (which isn't deployed). The three cron scripts still define it too, but guarded with `if (!defined(...))`
- `DEBUG_CHAT_ID`: 138516148 (chat privata per test/debug, evita spam sul gruppo)
