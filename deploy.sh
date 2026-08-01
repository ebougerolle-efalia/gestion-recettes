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

# DATABASE_URL vit dans .env.local ; on la lit sans exporter tout le fichier.
# libpq rejette les parametres propres a Doctrine (serverVersion, charset) avec
# « invalid URI query parameter » : on coupe la chaine de requete.
DB_URL="$(grep -E '^DATABASE_URL=' .env.local 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"')"
DB_URL="${DB_URL%%\?*}"

# --- Sauvegarde de la base avant toute mise a jour -------------------------
# PostgreSQL : pg_dump obligatoire. Copier le repertoire de donnees a chaud
# produirait une sauvegarde inexploitable.
if [ "$MODE" = "update" ] && command -v pg_dump >/dev/null 2>&1; then
    mkdir -p "$BACKUP_DIR"
    BACKUP_FILE="${BACKUP_DIR}/recettes_$(date +%Y%m%d_%H%M%S).dump"
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

# --- Origin git en SSH (depot prive) ----------------------------------------
# Une instance clonee avant le passage du depot en prive garde un remote HTTPS
# anonyme dans son .git/config : changer REPO_URL dans setup.conf ne la repare
# pas retroactivement, seul un nouveau clone en profiterait. Ce correctif
# tourne donc a chaque deploiement, meme logique que celui des droits de
# .env.local un peu plus bas — la panne s'est deja produite une fois pour
# .env.local, elle guette pareillement ici tant que l'instance n'est pas migree.
if [ -d ".git" ] && [ -n "${REPO_URL:-}" ]; then
    CURRENT_ORIGIN="$(git remote get-url origin 2>/dev/null || echo '')"
    if [ "$CURRENT_ORIGIN" != "$REPO_URL" ] && [[ "$CURRENT_ORIGIN" == https://* || "$CURRENT_ORIGIN" == http://* ]]; then
        warn "Origin git en HTTPS anonyme ($CURRENT_ORIGIN) - bascule vers $REPO_URL."
        git remote set-url origin "$REPO_URL"
    fi
fi

# --- Recuperation du code (synchro complete avec le depot) -----------------
if [ -d ".git" ]; then
    log "Git pull (branche: $GIT_BRANCH)..."
    git fetch origin \
        || err "git fetch a echoue. Depot prive : verifiez la cle de deploiement SSH de www-data (README, section Depots prives)."
    git reset --hard "origin/$GIT_BRANCH"
    log "Code a jour ($(git log -1 --format='%h - %s'))"
fi

# --- Dependances -----------------------------------------------------------
log "Composer install..."
"$COMPOSER_BIN" config allow-plugins.symfony/runtime true --no-interaction >/dev/null 2>&1 || true
"$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

# --- .env.local lisible par l'utilisateur web ------------------------------
# La console tourne sous $WEB_USER : un .env.local en root:root la fait echouer
# sur « Unable to read the .env.local environment file ». Le controle vit ici et
# pas seulement dans setup-server.sh, parce que deploy.sh est livre par le clone
# a chaque nouvelle instance : il est donc toujours a jour, meme quand la copie
# locale du script de provisionnement est perimee — ce qui est deja arrive.
if [ -f ".env.local" ] && [ "$(id -u)" = "0" ] && id "$WEB_USER" &>/dev/null; then
    if ! sudo -u "$WEB_USER" test -r .env.local 2>/dev/null; then
        warn ".env.local illisible par $WEB_USER - droits corriges."
        chown "root:$WEB_USER" .env.local
        chmod 640 .env.local
    fi
fi

# --- Dossiers inscriptibles AVANT tout appel a la console ------------------
# La console est executee sous $WEB_USER : sans ces droits, la premiere
# commande echoue sur « Unable to create the cache directory ». Le bloc
# Permissions en fin de script arrive trop tard pour ce premier appel.
log "Preparation des dossiers var/..."
mkdir -p var/cache var/log var/data var/backups var/invoices public/uploads/boutique
if id "$WEB_USER" &>/dev/null; then
    chown -R "$WEB_USER:$WEB_USER" var/ public/uploads/ 2>/dev/null || warn "chown initial impossible."
fi

# --- Base de donnees -------------------------------------------------------
# Migrations versionnees : rejouables, reversibles, et tracees dans la table
# doctrine_migration_versions. schema:update est proscrit ici, il peut
# supprimer une colonne sans prevenir sur une base contenant des donnees.
log "Migrations..."
run_php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Le seed depend de l'etat de la BASE, pas de la presence de vendor/ : un
# premier deploiement interrompu apres composer install faisait basculer tous
# les suivants en « mise a jour », et l'administrateur n'etait jamais cree.
# app:seed est idempotent, mais on evite de le rejouer sur une instance vivante :
# il recreerait les categories et familles par defaut qu'un client aurait
# supprimees.
NEEDS_SEED=0
if [ "$MODE" = "install" ]; then
    NEEDS_SEED=1
elif [ -n "$DB_URL" ] && command -v psql >/dev/null 2>&1; then
    # « || true » indispensable : avec set -euo pipefail, un psql en echec
    # ferait avorter tout le deploiement sur une simple interrogation.
    USER_COUNT="$(psql "$DB_URL" -tAc 'SELECT COUNT(*) FROM users' 2>/dev/null | tr -d '[:space:]' || true)"
    [ "$USER_COUNT" = "0" ] && NEEDS_SEED=1
fi

if [ "$NEEDS_SEED" = "1" ]; then
    log "Seed initial (aucun utilisateur en base)..."
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
mkdir -p var/cache var/log var/data var/invoices
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