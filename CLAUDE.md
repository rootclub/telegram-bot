# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP-based Telegram bot that handles group chat interactions with various features including:
- AI-powered responses using Ollama API (Gemma 4) with modular intent dispatcher
- Order management system ("pappatoie" - food ordering)
- Image handling, vision analysis, image recall via sub-agent, and AI image generation via ComfyUI
- Quiz/trivia system with Wikipedia integration
- Event management with multi-step workflows
- TTS (Text-to-Speech) via voice clone
- Profanity moderation
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
- `moderation.php`: Profanity detection and user moderation
- `image.php`: Image download and storage handling
- `help.php`: Help command responses
- `user_memory.php`: User profile memory system (extractors, aggregators)
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
3. Parser applies clamps: BPM ∈ [60,200], DURATION ∈ [90,240]; fallbacks for missing fields; aborts before ComfyUI if CAPTION or LYRICS missing
4. Workflow `workflows/audio_ace_step1_5_xl_turbo.json` loaded and parameterized (nodes `94` tags/lyrics/bpm/keyscale/language/duration, `98` seconds, `109` seed)
5. Polls `/history` up to 240s, fetches MP3 from `/view?...&subfolder=audio`, sends via `sendAudio` with `title`, `performer="rootbot"`, `duration`
- Rate limit: 6 songs/hour per user (tracked in `audio_gen_usage` table)
- Recent chat context used to resolve references ("stesso stile ma più lento", "fanne uno in italiano")

### Cron Jobs

- **`cron_saluto.php`** — Daily evening recap at 23:50, calls `_saluto()` and sends to main group with TTS button
- **`cron_dj.php`** — Hourly spontaneous DJ commentary, Hacker News integration, configurable probability (15%), min 2h between posts, min 3 messages to trigger
- **`cron_rassegna.php`** — Morning press digest at 08:00, fetches from rootclub.it/news/. Considers articles from the last 48h (buffer against skipped runs); dedup via `rassegna_posted` table (URL as PK) ensures no duplicates across days

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

6. **profanity_stats** - User profanity tracking
   - `user_id` (PK), `user_name`, `lieve`, `moderata`, `grave`, `bestemmia`, `last_updated`

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

22. **image_gen_usage**, **audio_gen_usage** — rate-limit tables owned by the `image_gen` and `audio_gen` agents respectively (schema in each agent's `agent.php`). Auto-cleanup: 1 hour.

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
Delete the agent's directory. The classifier no longer offers that intent; the owned tables remain in the DB (harmless) and can be dropped manually if desired. **Note:** slash commands and help entries are still hardcoded in `message.php`/`help.php` (see TODO in memory: `project_agent_improvements.md`) — remove those references manually.

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
- `logs/image_debug.log` — Image download/processing debug
- `logs/memory.log` — User memory extraction diagnostics
- `logs/memory_cron.log` — Nightly user-memory cron job
- `logs/quiz.log` — Quiz generation pipeline
- `logs/telegram.log` — Telegram API wrapper (retry, errors)

### Utility Scripts
- `admin_user_memory.php` — Web interface for user memory management (protected by `MEMORY_ADMIN_TOKEN`)
- `extract_user_memory.php` — CLI tool for user profile extraction
- `import_telegram_export.php` — Import Telegram Desktop JSON exports into `storico_messaggi`
- `clear_opcache.php` — Reset OPcache on server

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
- `MAIN_GROUP_ID`: -1001402757977 (gruppo principale)
- `DEBUG_CHAT_ID`: 138516148 (chat privata per test/debug, evita spam sul gruppo)
