# Gestion des Recettes

Application SaaS de **calcul de coût de revient et de prix de vente** pour les
métiers de bouche (charcutiers, traiteurs, boulangers, restaurateurs).

Chaque client dispose de sa propre instance isolée, accessible via un
sous-domaine : `client.<BASE_DOMAIN>`.

## Technologies

- **Backend** : PHP 8.2+ / Symfony 7 / Doctrine ORM 3
- **Base de données** : SQLite (une base isolée par client)
- **Frontend** : Twig + Tailwind CSS (CDN) + JavaScript vanilla
- **Design** : style TailAdmin — sidebar sombre, cards arrondies, DataTables
- **Auth** : Symfony Security — form login, rôles (reader/editor/admin), bcrypt
- **PDF** : GotenbergBundle — génération via Chromium headless (Docker)
- **Pas de npm, pas de jQuery** — tout en CDN

## Fonctionnalités principales

- **Moteur de calcul de coûts** récursif : coût matière, conversion d'unités,
  perte et rendement, main d'œuvre, emballage, TVA, prix conseillé par
  coefficient ou marge cible, cache des résultats
- **Sous-recettes** : une recette peut inclure une autre recette (récursif,
  avec détection de cycles)
- **Historique de prix daté** des ingrédients (suivi de l'inflation)
- **Fiches techniques PDF** (composition, coûts, marge)
- **Paramètres de l'établissement** : nom, coordonnées, logo, valeurs par défaut
  — l'application est entièrement dépersonnalisée et se configure par client

---

## Installation locale (développement)

Pré-requis : PHP 8.2+ (extensions `pgsql`, `xml`, `intl`, `mbstring`, `curl`),
Composer, et Docker pour la base PostgreSQL de développement (ainsi que pour
Gotenberg si tu veux tester la génération PDF).

```bash
git clone <REPO_URL> gestion-recettes
cd gestion-recettes
composer install

# Base PostgreSQL de dev (port hôte 5433, pour ne pas heurter un PostgreSQL local)
docker compose up -d

# (optionnel) Gotenberg pour les PDF
docker run -d --name gotenberg --restart unless-stopped -p 3000:3000 gotenberg/gotenberg:8

# Schéma + données initiales
mkdir -p public/uploads/boutique
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:seed          # crée admin/admin123 + catégories + familles

# (optionnel) Valeurs nutritionnelles + jeu de démonstration
php bin/console app:import-ciqual
php bin/console app:demo-data     # 3 métiers, 54 ingrédients, 24 recettes

# Serveur de dev
symfony serve
#   ou : php -S localhost:8080 -t public/
```

Connexion : **admin** / **admin123** → puis **Administration → Paramètres**
pour renseigner le nom de l'établissement.

---

## Déploiement d'un client en production

Toute la configuration vit dans **`setup.conf`** (le seul fichier à personnaliser).

### 1. Renseigner `setup.conf`

```bash
BASE_DOMAIN="<BASE_DOMAIN>"            # ex: tarify.app
ADMIN_EMAIL="contact@<BASE_DOMAIN>"    # pour Let's Encrypt
REPO_URL="<REPO_URL>"
GIT_BRANCH="master"
APP_NAME="gestion-recettes"
INSTALL_ROOT="/var/www/clients"
GOTENBERG_PORT="3000"
```

### 2. Pointer le DNS

Crée un enregistrement `A` (ou un wildcard `*.<BASE_DOMAIN>`) pointant vers l'IP
du serveur. Pour un client précis : `dupont.<BASE_DOMAIN>` → IP du VPS.

> ⚠️ Le TLD `.app` impose le **HTTPS** sur tous les sous-domaines (liste HSTS
> preload). Certbot s'en charge automatiquement à l'étape suivante.

### 3. Lancer le déploiement

```bash
sudo ./setup-server.sh dupont
```

Ce qui se passe :

- **Au premier lancement seulement** : installation de nginx, PHP, Docker +
  Gotenberg, Composer et Certbot (mémorisé via `/etc/gestion-recettes-bootstrap.done`)
- Clonage de l'instance dans `/var/www/clients/dupont`
- Génération d'un `.env.local` avec une base SQLite **isolée** et des secrets uniques
- Création du vhost nginx pour `dupont.<BASE_DOMAIN>`
- Obtention du certificat HTTPS (Let's Encrypt)
- Configuration du webhook de déploiement automatique

Résultat : l'instance est en ligne sur **https://dupont.<BASE_DOMAIN>**
(admin / admin123 — **à changer immédiatement**).

### 4. Ajouter d'autres clients

```bash
sudo ./setup-server.sh martin
sudo ./setup-server.sh boulangerie-durand
```

L'étape système est ignorée, seule la nouvelle instance est créée. Chaque client
est totalement isolé (dossier, base, certificat, secrets).

---

## Structure d'un déploiement

```
/var/www/clients/
├── dupont/
│   ├── .env.local              # secrets + base propres à dupont
│   ├── public/uploads/boutique # logo de dupont
│   └── var/data/recettes.db    # base SQLite isolée
├── martin/
│   └── ...
└── boulangerie-durand/
    └── ...
```

## Fichiers de référence

- `setup.conf` — configuration centralisée (à personnaliser)
- `setup-server.sh` — déploiement d'une instance client
- `deploy.sh` — mise à jour d'une instance (voir **MISE-A-JOUR.md**)
- `.env` — valeurs par défaut (dev) ; **ne jamais mettre de secret ici**
- `.env.local` — secrets de production (généré, jamais committé)

> Pense à exclure du dépôt : `var/`, `.env.local`, `public/uploads/`, et
> `setup.conf` s'il contient des valeurs sensibles.