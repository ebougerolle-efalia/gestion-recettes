#!/bin/bash
set -e

# =============================================================================
#  setup-vitrine.sh — Mise en place de la vitrine sur le domaine racine
#
#  Usage :
#     sudo ./setup-vitrine.sh [branche]
#
#  La vitrine vit dans SON PROPRE dépôt (VITRINE_REPO_URL dans setup.conf),
#  distinct de celui de l'application : historique de commits différent
#  (tarifs envisagés, formulations d'offre) et rythme de publication différent
#  — une correction de texte ne passe par aucune revue de code applicatif.
#
#  Elle occupe le domaine nu (exemple.fr), les instances clients occupant les
#  sous-domaines (dupont.exemple.fr). Panne d'un client sans effet sur la page
#  publique, et réciproquement.
#
#  Idempotent : relancer met à jour le clone, réécrit le vhost et recharge
#  nginx. La configuration déjà en place n'est jamais écrasée.
# =============================================================================

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
log()  { echo -e "${GREEN}[VITRINE]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC}    $1"; }
err()  { echo -e "${RED}[ERROR]${NC}   $1"; exit 1; }

# --- Pré-requis ---------------------------------------------------------------
[ "$EUID" -ne 0 ] && err "Ce script doit être lancé avec sudo."

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
[ -f "$SCRIPT_DIR/setup.conf" ] || err "Fichier setup.conf introuvable à côté du script. Partez du modèle : cp setup.conf.example setup.conf"

# Même garde-fou que setup-server.sh : un setup.conf enregistré sous Windows
# fabriquerait un domaine « exemple.fr\r », vhost cassé et certbot en échec,
# sans le moindre indice à l'écran.
if ! tr -d '\r' < "$SCRIPT_DIR/setup.conf" | cmp -s - "$SCRIPT_DIR/setup.conf"; then
    err "setup.conf contient des fins de ligne Windows (CRLF). Convertissez-le : sed -i 's/\r\$//' setup.conf"
fi
# shellcheck source=/dev/null
source "$SCRIPT_DIR/setup.conf"

[ -z "$BASE_DOMAIN" ]      && err "BASE_DOMAIN est vide dans setup.conf."
[ -z "$ADMIN_EMAIL" ]      && err "ADMIN_EMAIL est vide dans setup.conf."
[ -z "$VITRINE_REPO_URL" ] && err "VITRINE_REPO_URL est vide dans setup.conf. La vitrine vit dans son propre dépôt — voir setup.conf.example."

VITRINE_BRANCH="${VITRINE_BRANCH:-main}"
[ -n "${1:-}" ] && VITRINE_BRANCH="$1"

# --- Auto-mise à jour du script ----------------------------------------------
# Même garde-fou que setup-server.sh, et pour la même raison : publier avec une
# copie périmée met en ligne une vitrine qui n'est pas celle du dépôt, sans que
# rien ne le signale. Le script se met à jour puis se relance, une seule fois.
#
# Ne dispense pas du premier « git pull » : un script encore absent ne peut pas
# se mettre à jour lui-même.
if [ -z "${VITRINE_SELF_UPDATED:-}" ] && [ -d "$SCRIPT_DIR/.git" ] && command -v git >/dev/null 2>&1; then
    if git -C "$SCRIPT_DIR" fetch --quiet origin 2>/dev/null; then
        LOCAL_REV="$(git -C "$SCRIPT_DIR" rev-parse HEAD 2>/dev/null || echo x)"
        REMOTE_REV="$(git -C "$SCRIPT_DIR" rev-parse '@{u}' 2>/dev/null || echo "$LOCAL_REV")"
        if [ "$LOCAL_REV" != "$REMOTE_REV" ]; then
            log "Scripts de provisionnement obsolètes — mise à jour puis relance…"
            git -C "$SCRIPT_DIR" pull --ff-only --quiet \
                || err "Mise à jour impossible dans $SCRIPT_DIR. Corrigez à la main : git -C $SCRIPT_DIR pull"
            export VITRINE_SELF_UPDATED=1
            exec "$SCRIPT_DIR/setup-vitrine.sh" "$@"
        fi
    else
        warn "Dépôt injoignable : impossible de vérifier que le script est à jour."
    fi
fi

# --- Emplacements ---------------------------------------------------------------
# VITRINE_DIR est le clone ET la racine web : le dépôt de la vitrine ne
# contient que ce qui doit être public.
#
# DATA_DIR est son frère, jamais dans le clone : il porte config.php (le sel)
# et le journal des demandes. La protection ne dépend ainsi d'aucune règle
# nginx qu'un futur vhost pourrait oublier de reprendre — le fichier est
# structurellement hors de toute racine web possible.
VITRINE_DIR="${INSTALL_ROOT}/vitrine"
DATA_DIR="${INSTALL_ROOT}/vitrine-data"
SITE_NAME="${APP_NAME}-vitrine"
BOOTSTRAP_FLAG="/etc/${APP_NAME}-bootstrap.done"

log "Domaine  : $BASE_DOMAIN (et www.$BASE_DOMAIN)"
log "Dossier  : $VITRINE_DIR"
log "Branche  : $VITRINE_BRANCH"

# --- Paquets ------------------------------------------------------------------
# La vitrine peut être installée avant toute instance client : on ne suppose pas
# que le bootstrap de setup-server.sh a déjà eu lieu. Ni base de données, ni
# Composer, ni Docker ne sont nécessaires — seulement nginx et PHP-FPM.
if [ -f "$BOOTSTRAP_FLAG" ]; then
    # shellcheck source=/dev/null
    source "$BOOTSTRAP_FLAG"   # fournit PHP_V
fi

if [ -z "${PHP_V:-}" ]; then
    # Version déduite du socket réellement présent : plus fiable que de la
    # supposer, la distribution décidant de ce qu'elle fournit.
    PHP_V="$(ls /run/php/php*-fpm.sock 2>/dev/null \
        | sed -E 's#.*/php([0-9.]+)-fpm\.sock#\1#' | sort -Vr | head -1)"
fi

if [ -z "$PHP_V" ]; then
    log "Aucun PHP-FPM en place : installation…"
    apt update
    apt install -y curl git nginx openssl
    for v in 8.4 8.3 8.2 8.1; do
        if apt-cache show php${v}-fpm &>/dev/null 2>&1; then PHP_V=$v; break; fi
    done
    [ -z "$PHP_V" ] && err "Aucune version PHP 8.x trouvée dans les dépôts."
    apt install -y php${PHP_V}-cli php${PHP_V}-fpm php${PHP_V}-mbstring
    systemctl enable php${PHP_V}-fpm && systemctl start php${PHP_V}-fpm
else
    apt install -y curl git nginx openssl >/dev/null 2>&1 || true
fi

log "PHP $PHP_V retenu."
[ -S "/run/php/php${PHP_V}-fpm.sock" ] || err "Socket /run/php/php${PHP_V}-fpm.sock absent. PHP-FPM tourne-t-il ?"

command -v certbot >/dev/null 2>&1 || apt install -y certbot python3-certbot-nginx

# --- Clone dédié --------------------------------------------------------------
if [ -d "$VITRINE_DIR/.git" ]; then
    log "Mise à jour du clone…"
    git -C "$VITRINE_DIR" fetch --quiet origin
    git -C "$VITRINE_DIR" checkout --quiet "$VITRINE_BRANCH"
    git -C "$VITRINE_DIR" pull --ff-only --quiet \
        || err "Mise à jour impossible dans $VITRINE_DIR (modifications locales ?). Corrigez à la main."
else
    log "Clonage → $VITRINE_DIR"
    mkdir -p "$INSTALL_ROOT"
    git clone --quiet -b "$VITRINE_BRANCH" "$VITRINE_REPO_URL" "$VITRINE_DIR"
fi

[ -f "$VITRINE_DIR/index.php" ] || err "$VITRINE_DIR/index.php introuvable : la branche $VITRINE_BRANCH du dépôt vitrine contient-elle bien la page ?"

# --- Données : configuration et journal des demandes --------------------------
mkdir -p "$DATA_DIR"
chown www-data:www-data "$DATA_DIR"
chmod 750 "$DATA_DIR"

# Créée une fois, jamais réécrite : elle porte un sel dont le changement
# réinitialiserait les compteurs anti-robot.
if [ ! -f "$DATA_DIR/config.php" ]; then
    log "Création de la configuration…"
    SEL="$(openssl rand -hex 16)"
    cat > "$DATA_DIR/config.php" <<PHPCONF
<?php
// Engendré par setup-vitrine.sh. Vit hors du clone, jamais servi par le web.
return [
    'destinataire'  => '${ADMIN_EMAIL}',
    // Doit appartenir au domaine du serveur, sinon les messageries classent
    // la notification en indésirable — voire la refusent.
    'expediteur'    => 'no-reply@${BASE_DOMAIN}',
    'journal'       => '${DATA_DIR}/souscriptions.jsonl',
    'max_par_heure' => 5,
    'sel'           => '${SEL}',
];
PHPCONF
else
    log "Configuration déjà en place — conservée."
fi

# Lisible par le serveur web, par personne d'autre.
chown root:www-data "$DATA_DIR/config.php"
chmod 640 "$DATA_DIR/config.php"

# --- Vhost nginx --------------------------------------------------------------
log "Configuration Nginx…"
cat > /etc/nginx/sites-available/${SITE_NAME} <<NGINX
server {
    listen 80;
    server_name ${BASE_DOMAIN} www.${BASE_DOMAIN};
    root ${VITRINE_DIR};
    index index.php;

    access_log /var/log/nginx/${SITE_NAME}_access.log;
    error_log  /var/log/nginx/${SITE_NAME}_error.log;

    # La vitrine ne reçoit qu'un formulaire : inutile d'accepter davantage.
    client_max_body_size 1M;

    # Défense en profondeur : un config.php ne devrait jamais se trouver ici,
    # puisqu'il vit dans DATA_DIR. Cette règle protège une installation
    # manuelle qui n'aurait pas suivi cette convention.
    location = /config.php  { deny all; return 404; }
    location = /config.example.php { deny all; return 404; }

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_V}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        # Indique à souscription.php où trouver sa configuration, hors du
        # clone : voir le README de la vitrine, « Où vit la configuration ».
        fastcgi_param VITRINE_CONFIG ${DATA_DIR}/config.php;
        include fastcgi_params;
    }

    location ~ /\. { deny all; }
    # Outils en ligne de commande : jamais atteignables par le web.
    location ^~ /bin/ { deny all; return 404; }
}
NGINX

ln -sf /etc/nginx/sites-available/${SITE_NAME} /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t || err "Configuration nginx invalide — rien n'a été rechargé."
systemctl reload nginx

# --- HTTPS --------------------------------------------------------------------
log "Activation HTTPS…"
if ! certbot --nginx -d "$BASE_DOMAIN" -d "www.${BASE_DOMAIN}" \
        --non-interactive --agree-tos --email "$ADMIN_EMAIL" --redirect 2>/dev/null; then
    warn "Certificat pour $BASE_DOMAIN + www refusé."
    warn "Cause la plus fréquente : l'enregistrement DNS de www.${BASE_DOMAIN} n'existe pas."
    log  "Seconde tentative sur le domaine nu…"
    certbot --nginx -d "$BASE_DOMAIN" \
        --non-interactive --agree-tos --email "$ADMIN_EMAIL" --redirect || {
        warn "Certbot a échoué. Vérifie que le DNS de $BASE_DOMAIN pointe vers ce serveur."
        warn "Relance ensuite : certbot --nginx -d $BASE_DOMAIN"
    }
fi

# --- Purge des demandes de plus de douze mois ---------------------------------
# Les mentions légales annoncent cette durée : sans automatisation, l'engagement
# ne serait pas tenu. Réécrit à chaque passage, comme les autres tâches.
PURGE_CRON="/etc/cron.daily/${APP_NAME}-vitrine-purge"
cat > "$PURGE_CRON" <<CRON
#!/bin/bash
set -euo pipefail

# Les chemins sont inscrits à la génération : les passer au moment de
# l'exécution obligerait à les transmettre à php, source d'erreur inutile.
J="${DATA_DIR}/souscriptions.jsonl"
[ -f "\$J" ] || exit 0

php -f "${VITRINE_DIR}/bin/purge-demandes.php" -- "\$J"
CRON
chmod 750 "$PURGE_CRON"

# --- Résumé -------------------------------------------------------------------
echo ""
log "============================================"
log " VITRINE EN PLACE"
log " URL        : https://${BASE_DOMAIN}"
log " Dossier    : $VITRINE_DIR"
log " Config     : ${DATA_DIR}/config.php"
log " Demandes   : ${DATA_DIR}/souscriptions.jsonl"
log " Notifiées à: $ADMIN_EMAIL"
log "============================================"
echo ""
warn "Avant d'annoncer l'adresse :"
warn "  1. Compléter ${VITRINE_DIR}/mentions-legales.html — les passages entre"
warn "     crochets sont des trous. Publier sans identifier l'éditeur"
warn "     contrevient à l'article 6 III de la LCEN."
warn "  2. Vérifier qu'une demande de test arrive bien par courriel."
warn "  3. Poser le nom commercial (deux emplacements marqués dans index.php)."
echo ""
log "Pour publier une modification de la vitrine : sudo $0"
