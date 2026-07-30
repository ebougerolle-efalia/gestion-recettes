#!/bin/bash
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
# Priorité : variable d'env → branche active du clone local → master
GIT_BRANCH="${GIT_BRANCH:-$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo master)}"
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
# PostgreSQL : pg_dump obligatoire. Copier le repertoire de donnees a chaud
# produirait une sauvegarde inexploitable.
if [ "$MODE" = "update" ] && command -v pg_dump >/dev/null 2>&1; then
    mkdir -p "$BACKUP_DIR"
    BACKUP_FILE="${BACKUP_DIR}/recettes_$(date +%Y%m%d_%H%M%S).dump"
    # DATABASE_URL vit dans .env.local ; on la lit sans exporter tout le fichier.
    DB_URL="$(grep -E '^DATABASE_URL=' .env.local 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"')"
    # libpq rejette les parametres propres a Doctrine (serverVersion, charset)
    # avec « invalid URI query parameter » : on coupe la chaine de requete.
    DB_URL="${DB_URL%%\?*}"
    if [ -n "$DB_URL" ]; then
        # Echec bloquant : deployer sans sauvegarde valide n'est pas acceptable.
        pg_dump --format=custom --file="$BACKUP_FILE" "$DB_URL" \
            || err "pg_dump a echoue - deploiement interrompu, base NON modifiee."
        log "Base sauvegardee -> $BACKUP_FILE"
        ls -t "$BACKUP_DIR"/recettes_*.dump 2>/dev/null | tail -n +11 | xargs -r rm || true
    else
        warn "DATABASE_URL introuvable dans .env.local : sauvegarde ignoree."
    fi
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

# --- Dossiers inscriptibles AVANT tout appel a la console ------------------
# La console est executee sous $WEB_USER : sans ces droits, la premiere
# commande echoue sur « Unable to create the cache directory ». Le bloc
# Permissions en fin de script arrive trop tard pour ce premier appel.
log "Preparation des dossiers var/..."
mkdir -p var/cache var/log var/data var/backups public/uploads/boutique
if id "$WEB_USER" &>/dev/null; then
    chown -R "$WEB_USER:$WEB_USER" var/ public/uploads/ 2>/dev/null || warn "chown initial impossible."
fi

# --- Base de donnees -------------------------------------------------------
# Migrations versionnees : rejouables, reversibles, et tracees dans la table
# doctrine_migration_versions. schema:update est proscrit ici, il peut
# supprimer une colonne sans prevenir sur une base contenant des donnees.
log "Migrations..."
run_php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

if [ "$MODE" = "install" ]; then
    log "Seed initial..."
    run_php bin/console app:seed
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