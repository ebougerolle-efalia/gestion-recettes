# Gestion des Recettes

Application SaaS de **calcul de coût de revient et de prix de vente** pour les
métiers de bouche (charcutiers, traiteurs, boulangers, restaurateurs).

Chaque client dispose de sa propre instance isolée, accessible via un
sous-domaine : `client.<BASE_DOMAIN>`.

## Technologies

- **Backend** : PHP 8.2+ / Symfony 7 / Doctrine ORM 3
- **Base de données** : PostgreSQL (un rôle et une base isolés par client),
  schéma géré par migrations versionnées
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
- **Mercuriale alimentée par les factures Factur-X** : dépôt d'un PDF ou d'un
  XML CII, rapprochement automatique des lignes avec le catalogue, file
  d'attente de validation, création des prix et recalcul en cascade
- **Réception des factures par courriel** : une boîte dédiée relevée toutes les
  15 minutes, sans aucun geste. Les pièces sans Factur-X — le cas majoritaire
  jusqu'en 2027 — sont conservées et mises en attente de saisie plutôt que
  refusées
- **Prix de vente pratiqué** par recette : marge réelle, écart au prix conseillé,
  alerte sur les recettes vendues sous l'objectif
- **Valeurs nutritionnelles Ciqual** avec rattachement assisté des ingrédients
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

## Réception des factures par courriel

Le client crée une adresse dédiée — `factures@son-domaine.fr` — et la donne à
ses fournisseurs. Le serveur la relève toutes les quinze minutes et les factures
entrent seules dans la file d'attente.

Le DSN vit dans le `.env.local` du client, ou dans `setup.conf` à l'installation :

```
INVOICE_MAILBOX_DSN="imap://factures%40client.fr:motdepasse@imap.hebergeur.fr:993?encryption=ssl&folder=INBOX&archive=Traitees"
```

`encryption` vaut `ssl` (port 993, le cas courant) ou `tls` (STARTTLS, port 143).
`archive` est facultatif : sans lui les messages restent dans la boîte, marqués
lus. Les caractères `@`, `:` et `/` présents dans l'identifiant ou le mot de
passe doivent être encodés (`%40`, `%3A`, `%2F`).

Vérifier la configuration sans rien enregistrer ni marquer lu :

```bash
php bin/console app:invoice-fetch --dry-run -v
```

Le cron est installé par `setup-server.sh` **même quand aucune boîte n'est
configurée** : la commande le détecte et ne fait rien. Ouvrir le canal plus tard
ne demande donc que d'éditer le `.env.local`.

**Ce qui arrive à une pièce jointe.** Le fichier est conservé sous
`var/invoices/`, son empreinte SHA-256 sert de clé de déduplication — une boîte
relevée deux fois ou un fournisseur qui renvoie son message ne créent pas de
doublon. Puis :

| Contenu de la pièce | Résultat |
|---|---|
| PDF Factur-X ou XML CII | Lignes rapprochées, facture **à valider** |
| PDF ordinaire, scan | Facture **à saisir** : fichier conservé, lignes à taper |
| Lu, mais sans ligne exploitable | **À saisir**, en-tête déjà rempli |

Le fournisseur est reconnu à l'adresse d'expédition — d'abord l'adresse exacte,
puis le domaine. Les domaines grand public (gmail, orange, free…) n'identifient
personne et sont écartés. Quand un humain rattache une première facture, l'adresse
est mémorisée : les suivantes se rattachent seules.

**Sauvegarde.** La base ne contient que les données extraites d'une facture ; le
fichier reçu, lui, est une pièce comptable dont `var/invoices/` détient l'unique
exemplaire. La sauvegarde quotidienne en fait donc un miroir sous
`var/backups/invoices/`, par liens physiques : le nom d'un fichier étant son
empreinte SHA-256, son contenu ne change jamais, le lien ne coûte aucun espace
disque et survit à la suppression de l'original.

Deux régimes de rétention, à ne pas confondre : les dumps de base sont des
instantanés redondants, purgés à 14 jours ; les factures ne le sont **jamais** —
purger ce miroir détruirait les seules copies. Emporter `var/backups/` suffit à
restaurer un client complet.

---

## Ressources externes

Police, icônes et bibliothèque de graphiques sont **hébergées par l'application**
(`public/assets/vendor/`, voir son `LISEZMOI.md`). Elles étaient auparavant
appelées chez Google et Cloudflare : l'adresse IP de chaque utilisateur partait
vers deux entreprises américaines à chaque page, et un script tiers s'exécutait
avec accès complet au DOM d'une session affichant marges et prix fournisseurs.

Un test échoue si une adresse externe réapparaît dans une page rendue —
`tests/Functional/RessourcesExternesTest.php`. C'est le seul garde-fou : recoller
un lien de CDN tient en une ligne, marche immédiatement, et ne se voit pas.

**Reste au CDN : Tailwind, et lui seul.** Les templates emploient une quarantaine
de valeurs arbitraires (`text-[11px]` 85 fois, `text-[#1c2434]` 75 fois) que son
moteur fabrique à la volée en lisant le DOM ; aucun fichier Tailwind prégénéré ne
les contient. S'en passer impose une étape de compilation, donc Node en
développement et le CSS produit versionné. Le jour où c'est fait, vider
`RessourcesExternesTest::TOLERES`.

---

## Vitrine publique

`landing/` contient la page de présentation et de demande d'accès, destinée au
**domaine racine** — les instances clients occupant les sous-domaines. Elle est
autonome : aucune dépendance au code applicatif, aucune ressource externe, et
son propre `README`. Le bouton n'encaisse rien, il enregistre une demande ; le
provisionnement reste manuel via `setup-server.sh`, ce qui correspond à la
phase Alpha.

Mise en place, sur un serveur vierge comme sur un serveur déjà peuplé :

```bash
sudo ./setup-vitrine.sh
```

Elle vit dans son propre clone (`$INSTALL_ROOT/vitrine`) : une correction de
texte ne touche aucune instance, et la panne d'un client ne fait pas tomber la
page publique. Le script est idempotent — le relancer publie les modifications.
Détail des prérequis DNS dans `landing/README.md`.

---

## Tests

Deux suites, séparées parce qu'elles n'ont pas les mêmes exigences.

**`unit`** — moteur de conversion d'unités et rapprochement de libellés. Aucune
base, aucun réseau : exécutable partout, y compris en intégration continue.

```bash
php vendor/bin/phpunit --testsuite unit
```

**`fonctionnel`** — parcours de toutes les pages en tant qu'éditeur. Vérifie
qu'aucune ne renvoie 500. C'est le filet qui manquait : deux requêtes SQLite
oubliées lors du passage à PostgreSQL (`julianday()` et `date('now', '-12 months')`)
avaient mis le tableau de bord et la page fournisseurs en erreur en production,
sans que rien ne le signale avant qu'un humain ne clique.

Elle demande une base de test **distincte de celle de développement**, peuplée
une fois pour toutes :

```bash
docker compose exec database createdb -U recettes recettes_test
APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=test php bin/console app:seed
APP_ENV=test php bin/console app:demo-data
php vendor/bin/phpunit
```

Sans cette base, les tests fonctionnels se marquent *skipped* au lieu d'échouer :
l'absence d'environnement n'est pas une régression.

---

## Déploiement d'un client en production

Toute la configuration vit dans **`setup.conf`**, seul fichier à personnaliser.
Il n'est pas versionné : on part du modèle `setup.conf.example`.

Les commandes ci-dessous sont à lancer **en root** (le script refuse de démarrer
autrement) ; inutile de les préfixer de `sudo` si vous êtes déjà root.

### 1. Récupérer les scripts sur le serveur

```bash
apt update && apt install -y git
git clone <REPO_URL> /root/gr-setup && chmod 700 /root/gr-setup
```

`/root` plutôt que `/tmp` : le script se relance à chaque nouveau client, et
`setup.conf` ne doit pas disparaître au redémarrage ni être lisible par tous.

### 2. Renseigner `setup.conf`

```bash
cd /root/gr-setup && cp setup.conf.example setup.conf && nano setup.conf
```

Seuls `BASE_DOMAIN` et `ADMIN_EMAIL` sont à remplir, le reste a des valeurs
saines. Vérification avant de lancer quoi que ce soit :

```bash
bash -c 'source setup.conf && echo "$BASE_DOMAIN | $INSTALL_ROOT | $GOTENBERG_PORT"'
```

> ⚠️ Si vous éditez ce fichier depuis Windows, enregistrez-le en **fins de ligne
> Unix (LF)**. Un fichier CRLF colle un retour chariot invisible à chaque valeur
> et fabrique un domaine `demo.exemple.fr\r` : vhost nginx cassé et certbot en
> échec. Le script s'arrête désormais avec un message explicite dans ce cas.

### 3. Pointer le DNS

Créez un enregistrement `A` (ou un wildcard `*.<BASE_DOMAIN>`) vers l'IP du
serveur, **avant** de lancer le script : certbot valide le domaine en HTTP et
échoue si la propagation n'est pas faite.

### 4. Lancer le déploiement

```bash
cd /root/gr-setup && ./setup-server.sh dupont master
```

Ce qui se passe :

- **Au premier lancement seulement** : installation de nginx, PHP (+ `php-pgsql`),
  PostgreSQL et `postgresql-contrib`, Docker + Gotenberg, Composer et Certbot.
  Les versions de PHP et PostgreSQL sont détectées et mémorisées dans
  `/etc/gestion-recettes-bootstrap.done`
- Clonage de l'instance dans `/srv/gestion-recettes/dupont`
- Création d'un **rôle et d'une base PostgreSQL dédiés** au client
- Génération d'un `.env.local` (mot de passe de base aléatoire, `root:www-data`,
  non lisible par tous)
- Appel de `deploy.sh` : Composer, migrations versionnées, seed initial si la
  base est vide, reconstruction du cache
- Vhost nginx, certificat HTTPS, webhook de déploiement
- **Sauvegarde `pg_dump` quotidienne** dans `var/backups`, rétention 14 jours

Résultat : l'instance est en ligne sur **https://dupont.<BASE_DOMAIN>**
(admin / admin123 — **à changer immédiatement**, il apparaît en clair dans la
sortie du script).

### 5. Ajouter d'autres clients

```bash
cd /root/gr-setup && git pull && ./setup-server.sh martin production
```

Le `git pull` garantit de provisionner avec la version à jour des scripts.
L'étape système est ignorée, seule la nouvelle instance est créée : dossier,
base, rôle, certificat et secrets lui sont propres.

---

## Structure d'un déploiement

```
/srv/gestion-recettes/
├── dupont/
│   ├── .env.local              # secrets + URL de base propres à dupont
│   ├── public/uploads/boutique # logo de dupont
│   └── var/backups/            # dumps PostgreSQL (avant déploiement + quotidiens)
├── martin/
│   └── ...
└── boulangerie-durand/
    └── ...
```

Les données vivent dans PostgreSQL, une base par client (`recettes_dupont`), et
non plus dans un fichier de l'arborescence. **Sauvegarder revient donc à faire un
`pg_dump`** : copier `/srv` à chaud ne suffit plus.

## Fichiers de référence

- `setup.conf.example` — modèle versionné, à copier en `setup.conf`
- `setup.conf` — configuration du serveur (jamais versionnée)
- `setup-server.sh` — provisionnement d'une instance client
- `deploy.sh` — mise à jour d'une instance (appelé aussi par le webhook)
- `.env` — valeurs par défaut (dev) ; **ne jamais mettre de secret ici**
- `.env.local` — secrets de production (généré, jamais committé)