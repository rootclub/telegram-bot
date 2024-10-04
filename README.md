# Telegram Bot per Ordinazioni /root/bar

## Descrizione
Questo bot Telegram è stato sviluppato per gestire le ordinazioni dei pasti presso il circolo /root/bar. Facilita la raccolta delle ordinazioni dai membri del gruppo Telegram nelle serate di apertura del circolo.

## Caratteristiche
- Gestione automatizzata delle ordinazioni
- Integrazione con Telegram
- Interfaccia user-friendly per i membri del gruppo

## Requisiti
- Server web con PHP
- Account Telegram
- Istanza Ollama in esecuzione

## Installazione

1. **Clonare il Repository**
git clone https://github.com/rootclub/telegram-bot.git
cd telegram-bot

2. **Configurazione Server**
- Copiare i file in una directory del server web
- Assicurarsi che la directory `images` abbia i permessi di scrittura:
  ```
  chmod 755 images
  ```

3. **Creare un Bot Telegram**
- Utilizzare [@BotFather](https://t.me/botfather) su Telegram per creare un nuovo bot
- Annotare il token del bot fornito

4. **Configurazione**
- Modificare il file `config.php` con le seguenti informazioni:
  - Token Telegram
  - URL dello script PHP
  - URL dell'istanza Ollama

5. **Impostazione Webhook**
- Eseguire lo script `set_webhook.php` dal browser per configurare il webhook Telegram

## Utilizzo
Una volta configurato, il bot risponderà automaticamente ai comandi nel gruppo Telegram designato, gestendo le ordinazioni dei membri.

## Contributi
Sono benvenuti contributi e suggerimenti. Apri una issue o una pull request per proporre modifiche o miglioramenti.

## Licenza
MIT

## Contatti
Per supporto o domande, contattare il gruppo telegram /root/bar.
