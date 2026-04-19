#!/bin/bash
set -e

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
GIT_BRANCH="${GIT_BRANCH:-master}"
DB_PATH="${APP_DIR}/var/data/recettes.db"
BACKUP_DIR="${APP_DIR}/var/backups"
WEB_USER="${WEB_USER:-www-data}"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
log()  { echo -e "${GREEN}[DEPLOY]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $1"; }
err()  { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

command -v $PHP_BIN >/dev/null 2>&1    || err "PHP non trouvé."
command -v $COMPOSER_BIN >/dev/null 2>&1 || err "Composer non trouvé."
command -v git >/dev/null 2>&1          || err "Git non trouvé."

cd "$APP_DIR"

if [ ! -d "vendor" ]; then MODE="install"; log "=== PREMIER DÉPLOIEMENT ===";
else MODE="update"; log "=== MISE À JOUR ==="; fi

# Backup before update
if [ "$MODE" = "update" ] && [ -f "$DB_PATH" ]; then
    mkdir -p "$BACKUP_DIR"
    BACKUP_FILE="${BACKUP_DIR}/recettes_$(date +%Y%m%d_%H%M%S).db"
    cp "$DB_PATH" "$BACKUP_FILE"
    log "Base sauvegardée → $BACKUP_FILE"
    ls -t "$BACKUP_DIR"/recettes_*.db 2>/dev/null | tail -n +11 | xargs -r rm
fi

# Git pull
if [ -d ".git" ]; then
    log "Git pull (branche: $GIT_BRANCH)…"
    git fetch origin
    git reset --hard "origin/$GIT_BRANCH"
    log "Code à jour ($(git log -1 --format='%h — %s'))"
fi

# Composer
log "Composer install…"
$COMPOSER_BIN config allow-plugins.symfony/runtime true --no-interaction 2>/dev/null
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction

# Database
mkdir -p "$(dirname "$DB_PATH")"
if [ ! -f "$DB_PATH" ]; then
    log "Création du schéma…"
    $PHP_BIN bin/console doctrine:schema:create --no-interaction
    log "Seed initial…"
    $PHP_BIN bin/console app:seed
else
    log "Mise à jour du schéma…"
    $PHP_BIN bin/console doctrine:schema:update --force --no-interaction 2>/dev/null || warn "Schema déjà à jour."
fi

# Cache
log "Cache clear…"
$PHP_BIN bin/console cache:clear --env=prod --no-interaction 2>/dev/null || rm -rf var/cache/*
$PHP_BIN bin/console cache:warmup --env=prod --no-interaction 2>/dev/null || true

# Permissions
log "Permissions…"
mkdir -p var/cache var/log var/data
if id "$WEB_USER" &>/dev/null; then
    chown -R "$WEB_USER:$WEB_USER" var/ 2>/dev/null || warn "chown impossible."
fi
chmod -R 775 var/

echo ""
log "============================================"
if [ "$MODE" = "install" ]; then
    log " INSTALLATION TERMINÉE"
    log " Admin : admin / admin123"
    log " Changez le mot de passe après la première connexion !"
else
    log " MISE À JOUR TERMINÉE"
    log " Commit : $(git log -1 --format='%h — %s' 2>/dev/null || echo 'n/a')"
fi
log "============================================"
