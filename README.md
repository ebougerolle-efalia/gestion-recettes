# Gestion des Recettes — Pierrick Bougerolle

Application de gestion des recettes et calcul de coûts pour **Pierrick Bougerolle, Charcutier-Traiteur**.

- **Dépôt** : https://github.com/ebougerolle-efalia/gestion-recettes
- **Branche** : `master`
- **Production** : https://gestion-recettes.bougerolle.ovh

## Technologies

- **Backend** : PHP 8.1+ / Symfony 7 / Doctrine ORM
- **Base de données** : SQLite
- **Frontend** : Twig + Tailwind CSS v4 (CDN) + JavaScript vanilla
- **Design** : style TailAdmin — sidebar sombre, cards arrondies, DataTables
- **Auth** : Symfony Security — form login, rôles (reader/editor/admin), bcrypt
- **PDF** : GotenbergBundle (SensioLabs) — génération PDF via Chromium headless
- **Pas de npm, pas de jQuery** — tout en CDN

## Prérequis

- PHP 8.1+ avec extensions sqlite3, xml, intl, mbstring, curl
- Composer
- **Docker** (pour Gotenberg — génération PDF)

## Installation locale

```bash
git clone https://github.com/ebougerolle-efalia/gestion-recettes.git
cd gestion-recettes
composer install

# Lancer Gotenberg (génération PDF)
docker run -d --name gotenberg --restart unless-stopped -p 3000:3000 gotenberg/gotenberg:8

# Créer le dossier data et le schéma
mkdir -p var/data
php bin/console doctrine:schema:create
php bin/console app:seed                  # Crée admin/admin123 + catégories + familles
php -S localhost:8080 -t public/
```

Se connecter avec **admin** / **admin123**.

## Fonctionnalités

### Recettes

- CRUD complet avec liste DataTable (pagination, recherche, per-page)
- **Moteur de calcul de coûts** récursif :
  - Coût matière = somme des lignes (ingrédient × prix unitaire × quantité convertie)
  - Conversion d'unités automatique (kg ↔ g)
  - Perte et rendement par ligne ET global sur la recette
  - Main d'œuvre + emballage ajoutés au coût total
  - Prix de vente conseillé via coefficient OU marge cible
  - TVA configurable, calcul HT et TTC
  - Cache des résultats dans `recipe_cost_cache`
- **Sous-recettes** : une recette peut inclure une autre recette comme ingrédient (récursif, avec détection de cycles)
- **Duplication** complète (paramètres + toutes les lignes)
- **PDF** (via Gotenberg) :
  - **Fiche technique complète** : composition, coûts, prix de vente, marge — PDF A4 propre avec KPI visuels
  - **Fiche labo** : composition seule, sans infos financières — pour affichage en atelier
  - Rendu Chromium identique au navigateur, téléchargement direct en PDF
- KPI : coût matière, coût total, coût/unité, PV HT/TTC, marge € et %

### Ingrédients

- CRUD avec liste DataTable, catégorisation
- **Historique de prix** : chaque ajout de prix est daté, le dernier en vigueur est utilisé pour les calculs
- Ajout d'un nouveau prix → recalcul automatique de toutes les recettes utilisant cet ingrédient
- Unités de base : kg, g, pièce, litre
- TVA et fournisseur par défaut

### Administration (rôle admin)

- **Catégories d'ingrédients** : Viande, Épices, Emballage, Autres (CRUD)
- **Familles de recettes** : Terrine, Pâté, Saucisse, etc. (CRUD)
- **Utilisateurs** : créer, modifier rôle/statut, supprimer
- **Tableau de bord** : compteurs (users, ingrédients, recettes, catégories, familles)
- **Seed** : bouton pour initialiser les données par défaut

### Rôles

| Rôle | Voir | Créer/Modifier | Admin |
|------|------|----------------|-------|
| reader | ✓ | — | — |
| editor | ✓ | ✓ | — |
| admin | ✓ | ✓ | ✓ |

## Structure du projet

```
gestion-recettes/
├── bin/console
├── deploy.sh                    # Déploiement (install + update)
├── setup-server.sh              # Config serveur Debian/Ubuntu + HTTPS
├── .env.local.example
├── config/
│   └── packages/
│       ├── doctrine.yaml        # SQLite
│       ├── framework.yaml
│       ├── security.yaml        # Form login + rôles
│       └── twig.yaml
├── public/
│   ├── index.php
│   └── webhook.php              # Webhook GitHub
├── src/
│   ├── Command/
│   │   └── SeedCommand.php      # php bin/console app:seed
│   ├── Controller/
│   │   ├── SecurityController   # Login/logout
│   │   ├── RecipeController     # CRUD recettes + lignes + dupliquer + PDF Gotenberg
│   │   ├── IngredientController # CRUD ingrédients + historique prix
│   │   └── AdminController      # Catégories, familles, users, stats
│   ├── Entity/
│   │   ├── User                 # UserInterface, rôles Symfony
│   │   ├── IngredientCategory
│   │   ├── RecipeFamily
│   │   ├── Ingredient           # + relation prices
│   │   ├── IngredientPrice      # Historique prix datés
│   │   ├── Recipe               # Tous les champs coût/pricing
│   │   ├── RecipeLine           # Ingrédient OU sous-recette
│   │   └── RecipeCostCache      # Cache calculs
│   ├── Repository/ (7 repos)
│   └── Service/
│       └── CostCalculator.php   # Moteur de calcul récursif
└── templates/
    ├── base.html.twig           # Layout TailAdmin
    ├── security/login.html.twig
    ├── recipe/
    │   ├── index.html.twig      # Liste DataTable
    │   ├── show.html.twig       # Détail + KPI + params + lignes
    │   ├── print.html.twig      # Template PDF fiche technique (Gotenberg)
    │   └── print_lab.html.twig  # Template PDF fiche labo (Gotenberg)
    ├── ingredient/
    │   ├── index.html.twig      # Liste DataTable
    │   └── show.html.twig       # Détail + historique prix
    └── admin/
        ├── index.html.twig      # Dashboard
        ├── categories.html.twig
        ├── families.html.twig
        └── users.html.twig
```

## API internes (AJAX)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/recettes/recalculer` | POST | Recalculer le cache de coûts (tout ou par ingrédient/recette) |

## Déploiement

### Premier déploiement

```bash
ssh user@serveur
sudo git clone https://github.com/ebougerolle-efalia/gestion-recettes.git /var/www/gestion-recettes
cd /var/www/gestion-recettes
sudo ./setup-server.sh
```

Le script installe tout (PHP, Nginx, Composer, HTTPS), crée le schéma, seed les données initiales.
Application accessible sur **https://gestion-recettes.bougerolle.ovh**.

### Mise à jour

```bash
cd /var/www/gestion-recettes && ./deploy.sh
```

### Déploiement automatique (webhook GitHub)

Sur GitHub → Settings → Webhooks :
- Payload URL : `https://gestion-recettes.bougerolle.ovh/webhook.php`
- Content type : `application/json`
- Secret : celui de `WEBHOOK_SECRET` dans `.env.local`
- Events : push uniquement

### Workflow

```bash
git add . && git commit -m "Ajout recette" && git push origin master
# → Déploiement automatique
```

## Notes techniques

- **Moteur de calcul** : le `CostCalculator` résout récursivement les sous-recettes avec détection de cycles (protection contre les boucles infinies)
- **Historique de prix** : le prix utilisé pour le calcul est toujours le dernier en date (`effective_date DESC`)
- **Gotenberg** : conteneur Docker (`gotenberg/gotenberg:8`) sur le port 3000, utilisé via `sensiolabs/gotenberg-bundle` pour convertir les templates Twig en PDF via Chromium headless. Si Gotenberg ne tourne pas, les routes `/pdf` et `/pdf-labo` retourneront une erreur
- **Cache** : vider avec `php bin/console cache:clear` ou supprimer `var/cache/`
- **SQLite** : fichier unique `var/data/recettes.db`
- **Seed** : `php bin/console app:seed` crée l'admin et les données par défaut (idempotent)
- **Migration PostgreSQL → SQLite** : l'app originale utilisait PostgreSQL sur Railway ; cette version utilise SQLite pour simplifier le déploiement

## Licence

Privé — Pierrick Bougerolle
