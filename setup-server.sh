#!/bin/bash
set -e

DOMAIN="${1:-gestion-recettes.bougerolle.ovh}"
APP_DIR="/var/www/gestion-recettes"
REPO_URL="${2:-https://github.com/ebougerolle-efalia/gestion-recettes.git}"

GREEN='\033[0;32m'; NC='\033[0m'
log() { echo -e "${GREEN}[SETUP]${NC} $1"; }

if [ "$EUID" -ne 0 ]; then echo "Ce script doit être lancé avec sudo."; exit 1; fi

log "Installation des paquets…"
apt update
apt install -y curl git unzip nginx

# Détecter la meilleure version PHP disponible (8.4 > 8.3 > 8.2 > 8.1)
PHP_V=""
for v in 8.4 8.3 8.2 8.1; do
    if apt-cache show php${v}-cli &>/dev/null 2>&1; then
        PHP_V=$v
        break
    fi
done
if [ -z "$PHP_V" ]; then
    echo "[ERROR] Aucune version PHP 8.x trouvée dans les dépôts."
    exit 1
fi
log "PHP $PHP_V détecté."

apt install -y php${PHP_V}-cli php${PHP_V}-fpm php${PHP_V}-sqlite3 php${PHP_V}-xml php${PHP_V}-intl php${PHP_V}-mbstring php${PHP_V}-curl

# --- Docker + Gotenberg -------------------------------------------------------
if ! command -v docker &>/dev/null; then
    log "Installation de Docker…"
    curl -fsSL https://get.docker.com | sh
    systemctl enable docker
    systemctl start docker
fi

if ! docker ps --format '{{.Names}}' | grep -q gotenberg; then
    log "Lancement du conteneur Gotenberg…"
    docker run -d --name gotenberg --restart unless-stopped -p 3000:3000 gotenberg/gotenberg:8
    log "Gotenberg démarré sur le port 3000."
else
    log "Gotenberg déjà en cours d'exécution."
fi

if ! command -v composer &>/dev/null; then
    log "Installation de Composer…"
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

if [ ! -d "$APP_DIR" ]; then
    log "Clonage du dépôt → $APP_DIR"
    git clone "$REPO_URL" "$APP_DIR"
else
    log "Le dossier $APP_DIR existe déjà."
fi

cd "$APP_DIR"

if [ ! -f ".env.local" ]; then
    log "Création du .env.local…"
    SECRET=$(openssl rand -hex 16)
    cat > .env.local <<EOF
APP_ENV=prod
APP_SECRET=$SECRET
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data/recettes.db"
GOTENBERG_DSN=http://localhost:3000
WEBHOOK_SECRET=$(openssl rand -hex 20)
EOF
fi

log "Déploiement initial…"
chmod +x deploy.sh
./deploy.sh

chown -R www-data:www-data var/
chmod -R 775 var/

log "Configuration Nginx pour $DOMAIN…"
cat > /etc/nginx/sites-available/gestion-recettes <<NGINX
server {
    listen 80;
    server_name $DOMAIN;
    root $APP_DIR/public;
    index index.php;

    access_log /var/log/nginx/gestion-recettes_access.log;
    error_log  /var/log/nginx/gestion-recettes_error.log;

    client_max_body_size 20M;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location ~ ^/index\\.php(/|$) {
        fastcgi_pass unix:/run/php/php${PHP_V}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        internal;
    }

    location ~ /\\. { deny all; }
    location ~ /(var|config|src|vendor|bin|templates)/ { deny all; }
}
NGINX

ln -sf /etc/nginx/sites-available/gestion-recettes /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

log "Installation de Certbot et activation HTTPS…"
apt install -y certbot python3-certbot-nginx
certbot --nginx -d $DOMAIN --non-interactive --agree-tos --email admin@bougerolle.ovh --redirect || {
    echo "[WARN] Certbot a échoué. Vérifiez que le DNS pointe vers ce serveur."
    echo "[WARN] Relancez : certbot --nginx -d $DOMAIN"
}

log "Configuration sudoers pour le webhook…"
echo "www-data ALL=(ALL) NOPASSWD: $APP_DIR/deploy.sh" > /etc/sudoers.d/gestion-recettes
chmod 440 /etc/sudoers.d/gestion-recettes

echo ""
log "============================================"
log " SERVEUR CONFIGURÉ"
log " URL : https://$DOMAIN"
log " Admin : admin / admin123"
log " Gotenberg : http://localhost:3000 (Docker)"
log ""
log " Webhook GitHub :"
log "   Payload URL : https://$DOMAIN/webhook.php"
log "   Secret : voir WEBHOOK_SECRET dans .env.local"
log "============================================"
