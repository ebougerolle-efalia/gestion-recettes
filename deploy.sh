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

# Repartition des droits : root possede le CODE, www-data possede les DONNEES.
#
# Tout ce script s'execute donc sous l'utilisateur qui l'a lance (root, en
# pratique) : git, composer et la console. Le code, vendor/ et .git/ restent
# root-owned, lisibles par www-data (755) mais NON modifiables par lui — une
# application compromise ne peut pas reecrire les fichiers PHP qu'elle
# execute. Seuls var/ et public/uploads/, ou l'application ecrit reellement,
# sont rendus a www-data en fin de course.
#
# Consequence directe : une seule cle de deploiement SSH suffit, celle de
# root. www-data n'a aucune raison de parler a GitHub.
#
# (Une version anterieure forcait tout sous www-data via un helper run_as,
# pour que la cle fonctionne aussi depuis le webhook, qui tournait sous
# PHP-FPM. Le webhook supprime, cette contrainte disparait et le partage
# ci-dessus, plus sur, redevient possible.)

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

# --- Recuperation du code (synchro complete avec le depot) -----------------
if [ -d ".git" ]; then
    log "Git pull (branche: $GIT_BRANCH)..."
    git fetch origin \
        || err "git fetch a echoue. Depot prive : verifiez la cle de deploiement SSH de root (voir gestion-recettes-ops, section Cles de deploiement)."
    git reset --hard "origin/$GIT_BRANCH"
    log "Code a jour ($(git log -1 --format='%h - %s'))"
fi

# --- Dependances -----------------------------------------------------------
log "Composer install..."
"$COMPOSER_BIN" config allow-plugins.symfony/runtime true --no-interaction >/dev/null 2>&1 || true
"$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

# --- .env.local lisible par l'utilisateur web ------------------------------
# L'application le lit a chaque requete sous $WEB_USER : un .env.local en
# root:root la fait echouer sur « Unable to read the .env.local environment
# file ». Le controle vit ici et pas seulement dans setup-server.sh, parce que
# deploy.sh est livre par le clone a chaque nouvelle instance : il est donc
# toujours a jour, meme quand la copie locale du script de provisionnement est
# perimee — ce qui est deja arrive.
if [ -f ".env.local" ] && [ "$(id -u)" = "0" ] && id "$WEB_USER" &>/dev/null; then
    if ! sudo -u "$WEB_USER" test -r .env.local 2>/dev/null; then
        warn ".env.local illisible par $WEB_USER - droits corriges."
        chown "root:$WEB_USER" .env.local
        chmod 640 .env.local
    fi
fi

# --- Dossiers de donnees ----------------------------------------------------
# Crees avant les commandes console, qui y ecrivent leur cache. Le chown vers
# $WEB_USER est refait en fin de script : la console tourne ici sous root et
# laisse donc des fichiers root-owned que l'application, elle, doit pouvoir
# reecrire a chaque requete.
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
"$PHP_BIN" bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

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
    "$PHP_BIN" bin/console app:seed
fi

# --- Cache : reconstruction propre, echec bloquant -------------------------
# On repart d'un cache vide puis on le rechauffe. Si un template, une
# fonction Twig ou un service est casse/absent, le warmup ECHOUE et le
# deploiement s'arrete (au lieu de livrer un site casse en silence).
log "Reconstruction du cache..."
rm -rf var/cache/* 2>/dev/null || true
"$PHP_BIN" bin/console cache:warmup --env=prod --no-interaction

# --- Permissions : rendre les DONNEES a www-data ----------------------------
# Le code reste root-owned, lisible mais non modifiable par l'application.
# Seuls ces deux emplacements lui sont rendus, parce qu'elle y ecrit vraiment :
#   var/            cache, journaux, factures recues, sauvegardes
#   public/uploads/ logo de l'etablissement, televerse depuis l'interface
log "Permissions..."
mkdir -p var/cache var/log var/data var/invoices public/uploads/boutique
if id "$WEB_USER" &>/dev/null; then
    chown -R "$WEB_USER:$WEB_USER" var/ public/uploads/ 2>/dev/null || warn "chown impossible."
fi
chmod -R u+rwX,g+rwX var/ public/uploads/

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

# --- Controle des ressources reellement servies -----------------------------
# Les tests fonctionnels traversent le noyau Symfony, jamais nginx : ils sont
# structurellement incapables de voir une regle de vhost qui bloque une feuille
# de style. C'est arrive — une expression « location ~ /vendor/ » non ancree
# refusait /assets/vendor/inter/inter.css en 403 : plus de mise en forme, plus
# de graphiques, et 45 tests au vert. Seul un vrai navigateur l'avait vu.
#
# Les chemins ne sont pas recopies ici mais extraits des templates : une
# ressource ajoutee demain est controlee sans que personne n'y pense.
#
# Le code est deja deploye a ce stade : l'echec ci-dessous ne dit pas que la
# mise a jour a rate, mais que le serveur web ne sert pas ce qu'il devrait.
# Le piege ERR est donc retire, son message serait trompeur.
trap - ERR

if ! command -v curl >/dev/null 2>&1; then
    warn "curl absent : controle des ressources ignore."
    exit 0
fi

# Le vhost de CETTE instance est celui dont la racine pointe ici. Plus fiable
# qu'une convention de nommage, que deploy.sh ne connait pas.
VHOST="$(grep -lF "root ${APP_DIR}/public;" /etc/nginx/sites-enabled/* 2>/dev/null | head -1)"

if [ -z "$VHOST" ]; then
    # Cas normal du tout premier deploiement : setup-server.sh ecrit le vhost
    # APRES avoir appele deploy.sh. Rien a controler, rien a signaler.
    exit 0
fi

SMOKE_DOMAIN="$(sed -n 's/^[[:space:]]*server_name[[:space:]]\{1,\}\([^;]*\);.*/\1/p' "$VHOST" \
    | head -1 | awk '{print $1}')"

if [ -z "$SMOKE_DOMAIN" ]; then
    warn "Domaine introuvable dans $VHOST : controle des ressources ignore."
    exit 0
fi

# Chemins extraits des templates, dedupliques. Seules les ressources statiques
# nous interessent : les routes applicatives sont deja couvertes par les tests.
ASSETS="$(grep -rhoE "asset\('[^']+'\)" templates/ 2>/dev/null \
    | sed -E "s/asset\('(.*)'\)/\1/" | sort -u)"

if [ -z "$ASSETS" ]; then
    warn "Aucune ressource detectee dans les templates : controle ignore."
    exit 0
fi

log "Controle des ressources servies par nginx ($SMOKE_DOMAIN)..."

SMOKE_FAILED=0
for asset in $ASSETS; do
    # --resolve : on interroge le vhost local sans dependre du DNS public ni
    # d'une sortie internet. -L pour suivre la redirection vers HTTPS posee
    # par certbot.
    CODE="$(curl -sS -L --max-time 10 -o /dev/null -w '%{http_code}' \
        --resolve "${SMOKE_DOMAIN}:80:127.0.0.1" \
        --resolve "${SMOKE_DOMAIN}:443:127.0.0.1" \
        "http://${SMOKE_DOMAIN}/${asset}" 2>/dev/null || echo "000")"

    if [ "$CODE" != "200" ]; then
        warn "  $CODE  /$asset"
        SMOKE_FAILED=1
    fi
done

if [ "$SMOKE_FAILED" = "1" ]; then
    echo ""
    err "Le code est a jour, mais nginx ne sert pas toutes les ressources.
       L'application s'affichera sans mise en forme ou sans graphiques.
       Verifiez le vhost : /etc/nginx/sites-available/$(basename "$VHOST")
       Piste la plus frequente : une regle « deny » non ancree sur ^."
fi

log "Ressources servies : $(echo "$ASSETS" | wc -l | tr -d ' ') sur $(echo "$ASSETS" | wc -l | tr -d ' '), toutes en 200."