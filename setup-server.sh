#!/bin/bash
set -e

# =============================================================================
#  setup-server.sh — Déploiement d'une instance client
#
#  Usage :
#     sudo ./setup-server.sh <slug-client>
#
#  Exemple :
#     sudo ./setup-server.sh dupont
#     → déploie l'instance sur  dupont.<BASE_DOMAIN>
#       dans le dossier         <INSTALL_ROOT>/dupont
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
[ -f "$SCRIPT_DIR/setup.conf" ] || err "Fichier setup.conf introuvable à côté du script."
# shellcheck source=/dev/null
source "$SCRIPT_DIR/setup.conf"

# --- Validation de la configuration ------------------------------------------
[ -z "$BASE_DOMAIN" ] && err "BASE_DOMAIN est vide. Renseigne-le dans setup.conf (ex: tarify.app)."
[ -z "$ADMIN_EMAIL" ] && err "ADMIN_EMAIL est vide. Renseigne-le dans setup.conf."

CLIENT_SLUG="${1:-}"
[ -z "$CLIENT_SLUG" ] && err "Usage : sudo ./setup-server.sh <slug-client>   (ex: dupont)"
# Slug : minuscules, chiffres et tirets uniquement
echo "$CLIENT_SLUG" | grep -qE '^[a-z0-9][a-z0-9-]*$' || err "Slug invalide. Utilise minuscules, chiffres et tirets (ex: ma-boutique)."

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
    apt install -y curl git unzip nginx openssl

    # Détecter la meilleure version PHP disponible (8.4 > 8.3 > 8.2 > 8.1)
    PHP_V=""
    for v in 8.4 8.3 8.2 8.1; do
        if apt-cache show php${v}-cli &>/dev/null 2>&1; then PHP_V=$v; break; fi
    done
    [ -z "$PHP_V" ] && err "Aucune version PHP 8.x trouvée dans les dépôts."
    log "PHP $PHP_V détecté."
    apt install -y php${PHP_V}-cli php${PHP_V}-fpm php${PHP_V}-sqlite3 php${PHP_V}-xml php${PHP_V}-intl php${PHP_V}-mbstring php${PHP_V}-curl

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

    echo "PHP_V=$PHP_V" > "$BOOTSTRAP_FLAG"
    log "Bootstrap système terminé."
else
    # shellcheck source=/dev/null
    source "$BOOTSTRAP_FLAG"   # récupère PHP_V
    log "Serveur déjà initialisé (PHP $PHP_V). Étape système ignorée."
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

# --- .env.local propre à ce client -------------------------------------------
if [ ! -f ".env.local" ]; then
    log "Création du .env.local…"
    cat > .env.local <<EOF
APP_ENV=prod
APP_SECRET=$(openssl rand -hex 16)
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data/recettes.db"
GOTENBERG_DSN=http://localhost:${GOTENBERG_PORT}
WEBHOOK_SECRET=$(openssl rand -hex 20)
EOF
fi

# --- Dossier d'upload du logo (module Paramètres) ----------------------------
mkdir -p public/uploads/boutique

# --- Déploiement applicatif (composer, schéma, seed, cache) ------------------
log "Déploiement initial via deploy.sh…"
chmod +x deploy.sh
GIT_BRANCH="$GIT_BRANCH" ./deploy.sh

chown -R www-data:www-data var/ public/uploads/
chmod -R 775 var/ public/uploads/

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