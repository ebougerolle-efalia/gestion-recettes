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

Page de présentation et de demande d'accès, destinée au **domaine racine** —
les instances clients occupant les sous-domaines. Elle vit dans **son propre
dépôt Git**, distinct de celui-ci et privé : historique de commits différent
(tarifs envisagés, formulations d'offre) et rythme de publication différent —
une correction de texte n'a pas à passer par la moindre revue de code
applicatif. Elle est autonome : aucune dépendance au code de ce dépôt, aucune
ressource externe.

Son installation (`setup-vitrine.sh`) vit dans un **troisième dépôt**,
`gestion-recettes-ops` — les outils d'exploitation eux-mêmes séparés du code
applicatif, pour la même raison : historique et cadence propres à la
topologie serveur, sans rapport avec les fonctionnalités du produit. Voir son
README pour le mode opératoire complet.

Le bouton de la vitrine n'encaisse rien, il enregistre une demande ; le
provisionnement d'une instance reste manuel (`setup-server.sh`, dans
`gestion-recettes-ops`), ce qui correspond à la phase Alpha.

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

## Provisionnement et déploiement

Les scripts d'exploitation (`setup-server.sh`, `setup-vitrine.sh`,
`setup.conf`) vivent dans un dépôt séparé, **`gestion-recettes-ops`** — même
raisonnement que pour la vitrine : historique et cadence propres à la
topologie serveur (domaines, slugs clients), sans rapport avec les
fonctionnalités du produit. Son README est le mode opératoire complet, du
serveur vierge à l'instance en ligne — clés de déploiement SSH comprises, les
trois dépôts (`gestion-recettes`, `gestion-recettes-vitrine`,
`gestion-recettes-ops`) étant privés.

Reste **ici**, parce qu'ils sont déployés avec chaque instance et non exécutés
depuis un poste d'administration :

- `deploy.sh` — met à jour une instance (Composer, migrations, cache) ; appelé
  par le webhook à chaque push, et par `setup-server.sh` au premier
  déploiement
- `public/webhook.php` — point d'entrée du déploiement automatique

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

**Origine git auto-réparée.** Une instance clonée avant le passage des dépôts
en privé garde un `origin` HTTPS anonyme dans son `.git/config` : renseigner
`REPO_URL` dans `setup.conf` ne la corrige pas rétroactivement, et le premier
`git fetch` suivant échouerait. `deploy.sh` compare l'origine réelle à
`REPO_URL` (transmis par `webhook.php`, lu dans `.env.local`) à chaque
déploiement, et la corrige elle-même quand elle est encore en HTTPS — même
principe que la réparation des droits de `.env.local` juste après. Le premier
`git pull` fait par un client provisionné avant ce changement écrit lui-même
`REPO_URL` dans son `.env.local` (voir `gestion-recettes-ops/setup-server.sh`),
sans intervention.

## Fichiers de référence

- `deploy.sh` — mise à jour d'une instance (appelé aussi par le webhook)
- `.env` — valeurs par défaut (dev) ; **ne jamais mettre de secret ici**
- `.env.local` — secrets de production (généré par `setup-server.sh`, jamais committé)

Provisionnement, `setup.conf` et clés de déploiement : voir le dépôt
`gestion-recettes-ops`.