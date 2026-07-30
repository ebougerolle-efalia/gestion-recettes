#!/bin/bash
set -e

# =============================================================================
#  setup-server.sh — Déploiement d'une instance client
#
#  Usage :
#     sudo ./setup-server.sh <slug-client> [branche]
#
#  Exemples :
#     sudo ./setup-server.sh demo              → instance de dev sur master
#     sudo ./setup-server.sh dupont production → instance client sur production
#     sudo ./setup-server.sh martin            → branche définie dans setup.conf
#
#  Toute la configuration (domaine, dépôt, e-mail…) vit dans setup.conf.
#  Le script est idempotent : les paquets système ne sont installés qu'une fois.
# =============================================================================

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
log()  { echo -e "${GREEN}[SETUP]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $1"; }
err()  { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# --- Pré-requis ---------------------------------------------------------------
[ "$EUID" -ne 0 ] && err "Ce script doit être lancé avec sudo."

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
[ -f "$SCRIPT_DIR/setup.conf" ] || err "Fichier setup.conf introuvable à côté du script. Partez du modèle : cp setup.conf.example setup.conf"

# Un setup.conf enregistré sous Windows colle un retour chariot à chaque valeur.
# Le script s'exécuterait sans erreur visible, mais fabriquerait un domaine
# « demo.exemple.fr\r » : vhost nginx cassé et certbot en échec, sans indice.
# Comparaison du fichier avec lui-même privé de ses retours chariot : s'ils
# diffèrent, c'est qu'il en contient. Plus fiable qu'un grep, dont le
# traitement des CR varie d'une implémentation à l'autre.
if ! tr -d '\r' < "$SCRIPT_DIR/setup.conf" | cmp -s - "$SCRIPT_DIR/setup.conf"; then
    err "setup.conf contient des fins de ligne Windows (CRLF). Convertissez-le : sed -i 's/\r\$//' setup.conf"
fi
# shellcheck source=/dev/null
source "$SCRIPT_DIR/setup.conf"

# --- Validation de la configuration ------------------------------------------
[ -z "$BASE_DOMAIN" ] && err "BASE_DOMAIN est vide. Renseigne-le dans setup.conf (ex: tarify.app)."
[ -z "$ADMIN_EMAIL" ] && err "ADMIN_EMAIL est vide. Renseigne-le dans setup.conf."

CLIENT_SLUG="${1:-}"
[ -z "$CLIENT_SLUG" ] && err "Usage : sudo ./setup-server.sh <slug-client> [branche]   (ex: dupont production)"
echo "$CLIENT_SLUG" | grep -qE '^[a-z0-9][a-z0-9-]*$' || err "Slug invalide. Utilise minuscules, chiffres et tirets (ex: ma-boutique)."

# Branche optionnelle en 2e argument — remplace GIT_BRANCH de setup.conf
if [ -n "${2:-}" ]; then
    GIT_BRANCH="${2}"
    log "Branche     : $GIT_BRANCH (argument)"
else
    log "Branche     : $GIT_BRANCH (setup.conf)"
fi

DOMAIN="${CLIENT_SLUG}.${BASE_DOMAIN}"
APP_DIR="${INSTALL_ROOT}/${CLIENT_SLUG}"
SITE_NAME="${APP_NAME}-${CLIENT_SLUG}"
BOOTSTRAP_FLAG="/etc/${APP_NAME}-bootstrap.done"

log "Client      : $CLIENT_SLUG"
log "Domaine     : $DOMAIN"
log "Dossier     : $APP_DIR"

# --- Bootstrap système (une seule fois) --------------------------------------
if [ ! -f "$BOOTSTRAP_FLAG" ]; then
    log "Premier déploiement sur ce serveur : installation des paquets…"
    apt update
    # sudo est requis par deploy.sh (exécution sous www-data) et par le webhook.
    apt install -y curl git unzip nginx openssl sudo

    # Détecter la meilleure version PHP disponible (8.4 > 8.3 > 8.2 > 8.1)
    PHP_V=""
    for v in 8.4 8.3 8.2 8.1; do
        if apt-cache show php${v}-cli &>/dev/null 2>&1; then PHP_V=$v; break; fi
    done
    [ -z "$PHP_V" ] && err "Aucune version PHP 8.x trouvée dans les dépôts."
    log "PHP $PHP_V détecté."
    apt install -y php${PHP_V}-cli php${PHP_V}-fpm php${PHP_V}-pgsql php${PHP_V}-xml php${PHP_V}-intl php${PHP_V}-mbstring php${PHP_V}-curl

    # PostgreSQL. postgresql-contrib fournit pg_trgm et unaccent, utilises par
    # l'appariement Ciqual (extensions a activer par base, pas des paquets PHP).
    apt install -y postgresql postgresql-contrib
    systemctl enable postgresql && systemctl start postgresql

    # La version majeure dépend du dépôt de la distribution (15 sur Bookworm,
    # 17 sur Trixie) : elle est détectée, jamais supposée. Elle finit dans
    # DATABASE_URL, où une valeur fausse ferait choisir à Doctrine le mauvais
    # dialecte SQL.
    PG_V="$(psql --version 2>/dev/null | grep -oE '[0-9]+' | head -1)"
    [ -z "$PG_V" ] && err "PostgreSQL installé mais psql introuvable."
    log "PostgreSQL $PG_V détecté."

    # Docker + Gotenberg (partagés par toutes les instances)
    if ! command -v docker &>/dev/null; then
        log "Installation de Docker…"
        curl -fsSL https://get.docker.com | sh
        systemctl enable docker && systemctl start docker
    fi
    if ! docker ps --format '{{.Names}}' | grep -q gotenberg; then
        log "Lancement de Gotenberg (port ${GOTENBERG_PORT})…"
        docker run -d --name gotenberg --restart unless-stopped -p ${GOTENBERG_PORT}:3000 gotenberg/gotenberg:8
    fi

    # Composer
    if ! command -v composer &>/dev/null; then
        log "Installation de Composer…"
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    fi

    # Certbot
    apt install -y certbot python3-certbot-nginx

    printf 'PHP_V=%s\nPG_V=%s\n' "$PHP_V" "$PG_V" > "$BOOTSTRAP_FLAG"
    log "Bootstrap système terminé."
else
    # shellcheck source=/dev/null
    source "$BOOTSTRAP_FLAG"   # récupère PHP_V et PG_V
    # Serveur initialisé avant l'ajout de PG_V au drapeau : on le redétecte.
    PG_V="${PG_V:-$(psql --version 2>/dev/null | grep -oE '[0-9]+' | head -1)}"
    [ -z "$PG_V" ] && err "PostgreSQL introuvable sur ce serveur déjà initialisé."
    log "Serveur déjà initialisé (PHP $PHP_V, PostgreSQL $PG_V). Étape système ignorée."
fi

# --- Clonage de l'instance client --------------------------------------------
if [ -d "$APP_DIR" ]; then
    warn "Le dossier $APP_DIR existe déjà — instance non réinstallée."
else
    log "Clonage du dépôt → $APP_DIR"
    mkdir -p "$INSTALL_ROOT"
    git clone -b "$GIT_BRANCH" "$REPO_URL" "$APP_DIR"
fi

cd "$APP_DIR"

# --- Base PostgreSQL propre à ce client --------------------------------------
# Un rôle et une base par instance : les clients restent isolés, et une
# restauration n'affecte qu'un seul d'entre eux.
# printf et non echo : echo ajoute un saut de ligne, que tr — qui remplace tout
# caractere non alphanumerique — convertirait en underscore final.
DB_NAME="$(printf '%s' "recettes_${CLIENT_SLUG}" | tr -c '[:alnum:]_' '_')"
DB_USER="$DB_NAME"
DB_PASS="$(openssl rand -hex 16)"

if sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname = '$DB_NAME'" | grep -q 1; then
    warn "Base $DB_NAME déjà présente — création ignorée."
    EXISTING_DB=1
else
    log "Création du rôle et de la base $DB_NAME…"
    sudo -u postgres psql -c "CREATE ROLE \"$DB_USER\" LOGIN PASSWORD '$DB_PASS'"
    sudo -u postgres createdb -O "$DB_USER" -E UTF8 "$DB_NAME"
    EXISTING_DB=0
fi

# --- .env.local propre à ce client -------------------------------------------
if [ ! -f ".env.local" ]; then
    # Le mot de passe n'existe qu'en mémoire : sans .env.local à écrire, il est
    # perdu et la base existante devient inaccessible.
    [ "$EXISTING_DB" = "1" ] && err "Base $DB_NAME présente mais .env.local absent : mot de passe inconnu, intervention manuelle requise."
    log "Création du .env.local…"
    cat > .env.local <<EOF
APP_ENV=prod
APP_SECRET=$(openssl rand -hex 16)
DATABASE_URL="postgresql://${DB_USER}:${DB_PASS}@127.0.0.1:5432/${DB_NAME}?serverVersion=${PG_V}&charset=utf8"
GOTENBERG_DSN=http://localhost:${GOTENBERG_PORT}
WEBHOOK_SECRET=$(openssl rand -hex 20)
EOF
    # Le fichier porte le mot de passe de la base : il ne doit pas être lisible
    # par tous. Mais deploy.sh exécute la console sous www-data, qui doit
    # pouvoir le lire — d'où le groupe. Sans ce chown, le déploiement échoue
    # sur « Unable to read the .env.local environment file ».
    chown root:www-data .env.local
    chmod 640 .env.local
fi

# --- Dossier d'upload du logo (module Paramètres) ----------------------------
mkdir -p public/uploads/boutique

# --- Déploiement applicatif (composer, schéma, seed, cache) ------------------
log "Déploiement initial via deploy.sh…"
chmod +x deploy.sh
GIT_BRANCH="$GIT_BRANCH" ./deploy.sh

chown -R www-data:www-data var/ public/uploads/
chmod -R 775 var/ public/uploads/

# --- Sauvegarde quotidienne de la base ---------------------------------------
# Indispensable depuis le passage à PostgreSQL : il n'y a plus de fichier à
# copier, et une copie à chaud du répertoire de données serait inexploitable.
BACKUP_CRON="/etc/cron.daily/${APP_NAME}-backup-${CLIENT_SLUG}"
if [ ! -f "$BACKUP_CRON" ]; then
    log "Installation de la sauvegarde quotidienne…"
    cat > "$BACKUP_CRON" <<CRON
#!/bin/bash
set -euo pipefail
DIR="${APP_DIR}/var/backups"
mkdir -p "\$DIR"
URL="\$(grep -E '^DATABASE_URL=' ${APP_DIR}/.env.local | tail -1 | cut -d= -f2- | tr -d '"')"
# libpq refuse les paramètres propres à Doctrine (serverVersion, charset) :
# « invalid URI query parameter ». On coupe tout ce qui suit le point d'interrogation.
URL="\${URL%%\\?*}"
pg_dump --format=custom --file="\$DIR/daily_\$(date +%Y%m%d).dump" "\$URL"
# Rétention : 14 jours.
find "\$DIR" -name 'daily_*.dump' -mtime +14 -delete
CRON
    chmod 750 "$BACKUP_CRON"
fi

# --- Vhost nginx pour ce sous-domaine ----------------------------------------
log "Configuration Nginx pour $DOMAIN…"
cat > /etc/nginx/sites-available/${SITE_NAME} <<NGINX
server {
    listen 80;
    server_name $DOMAIN;
    root $APP_DIR/public;
    index index.php;

    access_log /var/log/nginx/${SITE_NAME}_access.log;
    error_log  /var/log/nginx/${SITE_NAME}_error.log;

    client_max_body_size 20M;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location ~ ^/index\.php(/|\$) {
        fastcgi_pass unix:/run/php/php${PHP_V}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        internal;
    }

    location ~ /\. { deny all; }
    location ~ /(var|config|src|vendor|bin|templates)/ { deny all; }
}
NGINX

ln -sf /etc/nginx/sites-available/${SITE_NAME} /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# --- HTTPS (obligatoire pour le TLD .app) ------------------------------------
log "Activation HTTPS pour $DOMAIN…"
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --email "$ADMIN_EMAIL" --redirect || {
    warn "Certbot a échoué. Vérifie que le DNS de $DOMAIN pointe bien vers ce serveur."
    warn "Relance ensuite : certbot --nginx -d $DOMAIN"
}

# --- Webhook de déploiement (sudoers) ----------------------------------------
echo "www-data ALL=(ALL) NOPASSWD: ${APP_DIR}/deploy.sh" > /etc/sudoers.d/${SITE_NAME}
chmod 440 /etc/sudoers.d/${SITE_NAME}

# --- Résumé -------------------------------------------------------------------
echo ""
log "============================================"
log " INSTANCE CLIENT CONFIGURÉE"
log " Client     : $CLIENT_SLUG"
log " URL        : https://$DOMAIN"
log " Admin      : admin / admin123  (à changer !)"
log " Dossier    : $APP_DIR"
log " Webhook    : https://$DOMAIN/webhook.php"
log "              secret → WEBHOOK_SECRET dans .env.local"
log "============================================"