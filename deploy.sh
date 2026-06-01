#!/bin/bash
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
# Priorité : variable d'env → branche active du clone local → master
GIT_BRANCH="${GIT_BRANCH:-$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo master)}"
DB_PATH="${APP_DIR}/var/data/recettes.db"
BACKUP_DIR="${APP_DIR}/var/backups"
WEB_USER="${WEB_USER:-www-data}"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
log()  { echo -e "${GREEN}[DEPLOY]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $1"; }
err()  { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# Toute commande qui echoue stoppe net le deploiement (avec le n de ligne).
trap 'err "Echec du deploiement a la ligne $LINENO - site NON mis a jour."' ERR

# Execute la console Symfony sous l'utilisateur web, pour que le cache,
# les proxies Doctrine et la base restent toujours possedes par $WEB_USER,
# meme si le script est lance en root (intervention manuelle).
run_php() {
    if [ "$(id -un)" = "$WEB_USER" ]; then
        "$PHP_BIN" "$@"
    elif command -v sudo >/dev/null 2>&1 && id "$WEB_USER" &>/dev/null; then
        sudo -u "$WEB_USER" "$PHP_BIN" "$@"
    else
        warn "Utilisateur '$WEB_USER' indisponible : execution en $(id -un)."
        "$PHP_BIN" "$@"
    fi
}

command -v "$PHP_BIN" >/dev/null 2>&1      || err "PHP non trouve."
command -v "$COMPOSER_BIN" >/dev/null 2>&1 || err "Composer non trouve."
command -v git >/dev/null 2>&1             || err "Git non trouve."

cd "$APP_DIR"

if [ ! -d "vendor" ]; then MODE="install"; log "=== PREMIER DEPLOIEMENT ===";
else MODE="update"; log "=== MISE A JOUR ==="; fi

# --- Sauvegarde de la base avant toute mise a jour -------------------------
if [ "$MODE" = "update" ] && [ -f "$DB_PATH" ]; then
    mkdir -p "$BACKUP_DIR"
    BACKUP_FILE="${BACKUP_DIR}/recettes_$(date +%Y%m%d_%H%M%S).db"
    cp "$DB_PATH" "$BACKUP_FILE"
    log "Base sauvegardee -> $BACKUP_FILE"
    ls -t "$BACKUP_DIR"/recettes_*.db 2>/dev/null | tail -n +11 | xargs -r rm || true
fi

# --- Recuperation du code (synchro complete avec le depot) -----------------
if [ -d ".git" ]; then
    log "Git pull (branche: $GIT_BRANCH)..."
    git fetch origin
    git reset --hard "origin/$GIT_BRANCH"
    log "Code a jour ($(git log -1 --format='%h - %s'))"
fi

# --- Dependances -----------------------------------------------------------
log "Composer install..."
"$COMPOSER_BIN" config allow-plugins.symfony/runtime true --no-interaction >/dev/null 2>&1 || true
"$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

# --- Base de donnees -------------------------------------------------------
mkdir -p "$(dirname "$DB_PATH")"
if [ ! -f "$DB_PATH" ]; then
    log "Creation du schema..."
    run_php bin/console doctrine:schema:create --no-interaction
    log "Seed initial..."
    run_php bin/console app:seed
else
    # Pas de masquage : schema:update sort 0 si rien a faire, et echoue
    # franchement (donc stoppe le deploiement) en cas de vrai probleme.
    log "Mise a jour du schema..."
    run_php bin/console doctrine:schema:update --force --no-interaction
fi

# --- Cache : reconstruction propre, echec bloquant -------------------------
# On repart d'un cache vide puis on le rechauffe. Si un template, une
# fonction Twig ou un service est casse/absent, le warmup ECHOUE et le
# deploiement s'arrete (au lieu de livrer un site casse en silence).
log "Reconstruction du cache..."
rm -rf var/cache/* 2>/dev/null || true
run_php bin/console cache:warmup --env=prod --no-interaction

# --- Permissions (filet de securite) ---------------------------------------
log "Permissions..."
mkdir -p var/cache var/log var/data
if id "$WEB_USER" &>/dev/null; then
    chown -R "$WEB_USER:$WEB_USER" var/ 2>/dev/null || warn "chown impossible."
fi
chmod -R u+rwX,g+rwX var/

echo ""
log "============================================"
if [ "$MODE" = "install" ]; then
    log " INSTALLATION TERMINEE"
    log " Admin : admin / admin123"
    log " Changez le mot de passe apres la premiere connexion !"
else
    log " MISE A JOUR TERMINEE"
    log " Commit : $(git log -1 --format='%h - %s' 2>/dev/null || echo 'n/a')"
fi
log "============================================"