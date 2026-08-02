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

**L'application ne contacte aucun serveur tiers.** Police, icônes, graphiques et
feuille de style sont servis par l'application elle-même. Elles étaient
auparavant appelées chez Google et Cloudflare : l'adresse IP de chaque
utilisateur partait vers deux entreprises américaines à chaque page, et un
script tiers s'exécutait avec accès complet au DOM d'une session affichant
marges et prix fournisseurs.

Un test échoue si une adresse externe réapparaît dans une page rendue —
`tests/Functional/RessourcesExternesTest.php`, dont la liste de tolérance est
vide. C'est le seul garde-fou : recoller un lien de CDN tient en une ligne,
marche immédiatement, et ne se voit pas.

### Compiler la feuille de style

Tailwind est compilé localement, plus chargé depuis un CDN.

```bash
npm install          # une fois
npm run build:css    # après toute modification de classe dans un template
npm run watch:css    # ou en continu pendant le développement
```

Le CSS produit (`public/assets/app.css`) est **versionné** : le déploiement n'a
besoin ni de Node ni de npm. La contrepartie est qu'une classe ajoutée dans un
template n'existe pas tant qu'on n'a pas recompilé — l'oubli ne casse rien de
visible côté serveur, la page répond 200 et les tests passent, seul un humain
devant son navigateur voit l'élément sans mise en forme.
`tests/Unit/FeuilleDeStyleCompileeTest.php` est le garde-fou : il échoue en
nommant les classes manquantes et la commande à lancer.

Tailwind **3** et non 4, délibérément : la v4 fait passer la couleur de bordure
par défaut de `gray-200` à `currentColor`, ce qui modifierait silencieusement
des dizaines de bordures. La migration se fera une fois la refonte stabilisée.

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

`deploy.sh` reste **ici**, parce qu'il est déployé avec chaque instance et non
exécuté depuis un poste d'administration : Composer, migrations, cache,
appelé par `setup-server.sh` au premier déploiement.

**Pas de déploiement automatique.** Aucun webhook n'est configuré : un
`git push` sur `master` ne met à jour aucune instance toute seule — trop tôt
dans le projet pour qu'une mise en production échappe à toute revue. Mettre à
jour une instance est un geste explicite, en SSH :

```bash
cd /srv/gestion-recettes/<slug> && sudo ./deploy.sh
```

### Contrôle des ressources servies

En fin de déploiement, `deploy.sh` interroge **le vhost réel** et vérifie que
chaque ressource statique référencée par les templates répond 200 — polices,
icônes, Chart.js, favicon.

Ce contrôle comble un angle mort : les tests fonctionnels traversent le noyau
Symfony, jamais nginx. Une règle de vhost qui bloque une feuille de style les
laisse tous au vert. C'est arrivé — une expression `location ~ /vendor/` non
ancrée refusait `/assets/vendor/inter/inter.css` en 403 : plus de mise en
forme, plus de graphiques, et 45 tests satisfaits. Seul un humain devant son
navigateur l'a vu.

Les chemins sont **extraits des templates**, pas recopiés : une ressource
ajoutée demain est contrôlée sans que personne n'y pense. L'interrogation
passe par `--resolve` vers `127.0.0.1`, donc sans dépendre du DNS public ni
d'une sortie internet.

Un échec ici ne signifie pas que la mise à jour a raté — le code est déployé —
mais que le serveur web ne sert pas ce qu'il devrait. Le message le dit, et
pointe le vhost concerné. Au tout premier déploiement le contrôle se tait :
`setup-server.sh` écrit le vhost *après* avoir appelé `deploy.sh`.

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

**Propriétaires.** `root` possède le code (`src/`, `vendor/`, `.git/`), que
l'application lit pour s'exécuter sans pouvoir le réécrire. `www-data` ne
possède que ce dans quoi l'application écrit vraiment : `var/` et
`public/uploads/`. `deploy.sh` rétablit ce partage à chaque passage.

## Fichiers de référence

- `deploy.sh` — mise à jour d'une instance, lancée manuellement en SSH
- `.env` — valeurs par défaut (dev) ; **ne jamais mettre de secret ici**
- `.env.local` — secrets de production (généré par `setup-server.sh`, jamais committé)

Provisionnement, `setup.conf` et clés de déploiement : voir le dépôt
`gestion-recettes-ops`.