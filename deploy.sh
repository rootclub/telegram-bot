#!/bin/bash
# Deploy file su server web via FTP
#
# Configurazione: crea un file .deploy-config nella root del progetto con:
#   FTP_HOST=ftp.example.com
#   FTP_USER=utente
#   FTP_PASS=password
#   FTP_REMOTE_DIR=/public_html/bot
#
# Uso:
#   ./deploy.sh include/QBertClient.php include/ai.php
#   ./deploy.sh --all   (carica tutto il progetto)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CONFIG_FILE="$SCRIPT_DIR/.deploy-config"

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Errore: file $CONFIG_FILE non trovato."
    echo "Crea il file con:"
    echo "  FTP_HOST=ftp.example.com"
    echo "  FTP_USER=utente"
    echo "  FTP_PASS=password"
    echo "  FTP_REMOTE_DIR=/public_html/bot"
    exit 1
fi

source "$CONFIG_FILE"

# Rimuovi trailing slash da FTP_REMOTE_DIR, gestisci "/" come vuoto
FTP_REMOTE_DIR="${FTP_REMOTE_DIR%/}"

for var in FTP_HOST FTP_USER FTP_PASS; do
    if [[ -z "${!var:-}" ]]; then
        echo "Errore: $var non definito in $CONFIG_FILE"
        exit 1
    fi
done

if [[ $# -eq 0 ]]; then
    echo "Uso: $0 file1 [file2 ...]"
    echo "      $0 --all"
    exit 1
fi

# Costruisci lista file
FILES=()
if [[ "$1" == "--all" ]]; then
    while IFS= read -r -d '' f; do
        FILES+=("$f")
    done < <(find "$SCRIPT_DIR" -type f \
        ! -path '*/.git/*' \
        ! -path '*/.deploy-config' \
        ! -name 'deploy.sh' \
        ! -name '*.sqlite' \
        ! -name 'debug.log' \
        ! -name 'config.php' \
        -print0)
else
    for f in "$@"; do
        if [[ -f "$SCRIPT_DIR/$f" ]]; then
            FILES+=("$SCRIPT_DIR/$f")
        elif [[ -f "$f" ]]; then
            FILES+=("$f")
        else
            echo "Attenzione: file '$f' non trovato, salto."
        fi
    done
fi

if [[ ${#FILES[@]} -eq 0 ]]; then
    echo "Nessun file da caricare."
    exit 1
fi

echo "Caricamento di ${#FILES[@]} file su $FTP_HOST:$FTP_REMOTE_DIR ..."

# Genera comandi FTP
FTP_COMMANDS=""
for filepath in "${FILES[@]}"; do
    # Calcola path relativo al progetto
    rel="${filepath#$SCRIPT_DIR/}"
    remote_dir="$FTP_REMOTE_DIR/$(dirname "$rel")"
    FTP_COMMANDS+="mkdir $remote_dir
"
    FTP_COMMANDS+="put $filepath $FTP_REMOTE_DIR/$rel
"
done

curl --ftp-create-dirs -s -S \
    --user "$FTP_USER:$FTP_PASS" \
    "ftp://$FTP_HOST" \
    -Q "dummy" 2>/dev/null || true

# Carica ogni file con curl
ERRORS=0
for filepath in "${FILES[@]}"; do
    rel="${filepath#$SCRIPT_DIR/}"
    remote_path="$FTP_REMOTE_DIR/$rel"
    echo -n "  $rel -> $remote_path ... "
    if curl -s -S --ssl-reqd -k --ftp-create-dirs \
        --user "$FTP_USER:$FTP_PASS" \
        -T "$filepath" \
        "ftp://$FTP_HOST$remote_path"; then
        echo "OK"
    else
        echo "ERRORE"
        ERRORS=$((ERRORS + 1))
    fi
done

if [[ $ERRORS -eq 0 ]]; then
    echo "Deploy completato: ${#FILES[@]} file caricati."
else
    echo "Deploy completato con $ERRORS errori su ${#FILES[@]} file."
    exit 1
fi
