# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP-based Telegram bot that handles group chat interactions with various features including:
- AI-powered responses using Ollama API
- Order management system ("pappatoie" - food ordering)
- Image handling and storage
- Profanity moderation
- Context-aware conversation capabilities

## Architecture

### Core Components

1. **Entry Point**: `bot.php` - Main webhook handler that processes Telegram updates
2. **Configuration**: `config.php` - Contains bot token, API endpoints, and settings
3. **Database**: SQLite database (`telegram_bot.sqlite`)

### Module Structure (`include/` directory)

- `api.php`: Telegram Bot API wrapper functions
- `database.php`: Database initialization and schema management
- `ai.php`: Ollama AI integration for generating responses
- `message.php`: Message processing and command handling
- `orders.php`: Food ordering system ("pappatoie") management
- `moderation.php`: Profanity detection and user moderation
- `image.php`: Image download and storage handling
- `help.php`: Help command responses

## Database Schema

### Tables Overview

1. **pappatoie** - Restaurants/food places (8 entries)
   - `id` INTEGER PRIMARY KEY AUTOINCREMENT
   - `pappatoia` TEXT NOT NULL UNIQUE - Restaurant name
   - `indirizzo` TEXT - Address
   - `telefono` TEXT - Phone number
   - `giorni_chiusura` TEXT - Closing days

2. **immagini_pappatoie** - Restaurant images/menus
   - `id` INTEGER PRIMARY KEY AUTOINCREMENT
   - `pappatoia_id` INTEGER - Foreign key to pappatoie
   - `immagine` TEXT - Image file path

3. **ordini** - Order records (69 entries)
   - `id` INTEGER PRIMARY KEY AUTOINCREMENT
   - `data` DATE NOT NULL - Order date
   - `pappatoia` INTEGER - Restaurant ID
   - `ordinante` INTEGER - User who created the order
   - `ritirante` INTEGER - User who will pick up the order

4. **elementi_ordini** - Individual order items
   - `id` INTEGER PRIMARY KEY AUTOINCREMENT
   - `id_ordine` INTEGER - Foreign key to ordini
   - `utente` INTEGER - User ID
   - `descrizione` TEXT - Order description

5. **contesto_chat** - Message context for AI (1150+ entries)
   - `id` INTEGER PRIMARY KEY AUTOINCREMENT
   - `group_id` INTEGER - Telegram group ID
   - `user_name` TEXT - User's first name
   - `message_text` TEXT - Message content
   - `timestamp` INTEGER - Unix timestamp

6. **profanity_stats** - User profanity tracking (23 users tracked)
   - `user_id` INTEGER PRIMARY KEY - Telegram user ID
   - `user_name` TEXT
   - `lieve` INTEGER - Light profanity count
   - `moderata` INTEGER - Moderate profanity count
   - `grave` INTEGER - Severe profanity count
   - `bestemmia` INTEGER - Blasphemy count
   - `x` INTEGER - X platform mentions
   - `facebook` INTEGER - Facebook mentions
   - `last_updated` DATETIME

7. **bot_silence** - Bot silence management
   - `id` INTEGER PRIMARY KEY (always 1)
   - `silence_until` INTEGER - Unix timestamp until silence

8. **user_states** - User interaction states
   - `chat_id` INTEGER
   - `state` TEXT - Current user state
   - `data` TEXT - State data

## Development Commands

### Setting up the webhook
```bash
php set_webhook.php
```

### Database inspection
```bash
# View all tables
sqlite3 telegram_bot.sqlite ".tables"

# View table schema
sqlite3 telegram_bot.sqlite ".schema TABLE_NAME"

# Query examples
sqlite3 telegram_bot.sqlite "SELECT * FROM pappatoie;"
sqlite3 telegram_bot.sqlite "SELECT COUNT(*) FROM contesto_chat WHERE group_id = GROUP_ID;"
```

### Testing locally
Since this is a webhook-based bot, you'll need:
1. A public URL (configured in `config.php` as `WEBHOOK_URL`)
2. Valid Telegram bot token (configured as `BOT_TOKEN`)
3. PHP with SQLite3 extension
4. Write permissions for `images/` directory and SQLite database

### Dependencies
- PHP 7+ with:
  - SQLite3 extension
  - cURL extension
  - JSON support
- External service: Ollama API server (configured in `OLLAMA_URL`)
- SQLite3 CLI tool for database management

## Key Implementation Details

### Message Flow
1. Telegram sends webhook updates to `bot.php`
2. Updates are logged to `debug.log` for troubleshooting
3. Messages are processed based on type (regular message, edited message, callback query)
4. Context is saved for AI responses
5. Commands are handled via pattern matching in `message.php`

### AI Integration
- Uses Ollama API with configurable model (`OLLAMA_MODEL`)
- Maintains conversation context from last 20 messages
- Implements silence periods and response probability
- Context includes user names and message history

### Order System
- Interactive inline keyboard menus for restaurant selection
- Multi-step order process with user assignment
- Image management for menu items
- Daily order tracking and modification

### Configuration Constants
Key settings in `config.php`:
- `SILENCE_DURATION`: Bot silence period (default: 120 seconds)
- `RESPONSE_PROBABILITY`: AI response probability (default: 0.8)
- `IMAGE_SAVE_PATH`: Local storage for downloaded images
- Locale set to Italian (`it_IT.utf8`) with Rome timezone