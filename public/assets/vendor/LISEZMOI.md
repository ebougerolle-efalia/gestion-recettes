# Ressources hébergées localement

Ces fichiers étaient auparavant appelés sur des CDN. Ils sont désormais servis
par l'application elle-même.

## Pourquoi

**Confidentialité.** Chaque page appelait `fonts.googleapis.com`,
`fonts.gstatic.com` et `cdnjs.cloudflare.com` : l'adresse IP et l'agent
utilisateur de chaque salarié de chaque client partaient vers deux entreprises
américaines, à chaque chargement. Derrière une authentification, ce n'est pas le
cas jugé à Munich en janvier 2022 — l'utilisateur s'est connecté volontairement,
et un contrat existe. Mais l'éditeur est **sous-traitant** de ses clients au sens
de l'article 28 du RGPD : Google et Cloudflare figuraient de fait parmi les
sous-traitants sans avoir jamais été déclarés. La page de connexion, elle, est
publique — c'était le cas le plus proche de la jurisprudence.

**Chaîne d'approvisionnement.** Chart.js est un **script**, exécuté avec accès
complet au DOM d'une session qui affiche marges, prix fournisseurs et factures.
Une compromission du CDN, c'est du code arbitraire dans le back-office de tous
les clients.

**Disponibilité.** Un atelier avec une connexion faible, ou un réseau qui filtre
les CDN, affichait l'application sans mise en forme.

## Ce qui reste au CDN

**Tailwind**, et lui seul. Les templates emploient une quarantaine de valeurs
arbitraires — `text-[11px]` (85 fois), `text-[#1c2434]` (75 fois),
`grid-cols-[minmax(0,1fr)_70px_90px_92px_36px]` — que le moteur du CDN fabrique
à la volée en lisant le DOM. **Aucun fichier Tailwind prégénéré ne les
contient** : vendorer un CSS complet casserait silencieusement la typographie et
la couleur d'encre du produit. S'en passer impose donc une étape de compilation
avec l'outil Tailwind, donc Node en développement et le CSS produit versionné.
Décision d'outillage laissée ouverte.

## Contenu

| Dossier | Version | Licence |
|---|---|---|
| `inter/` | Inter v20, police variable 300–800 | SIL OFL 1.1 — voir `inter/OFL.txt` |
| `fontawesome/` | Font Awesome Free 6.5.1, **solid uniquement** | Icônes CC BY 4.0, polices SIL OFL 1.1, code MIT — voir `fontawesome/LICENSE.txt` |
| `chartjs/` | Chart.js 4.4.1 | MIT |

Seule la famille *solid* de Font Awesome est embarquée : c'est la seule employée
par les templates (144 usages, aucune autre). Les familles *regular*, *brands* et
*light* auraient ajouté près d'un mégaoctet pour rien.

Inter est une police variable : un seul fichier par sous-ensemble couvre toutes
les graisses. Deux sous-ensembles suffisent — `latin` porte les accents français
et le œ, `latin-ext` les noms de fournisseurs étrangers, et n'est téléchargé que
si un tel caractère apparaît.

## Mettre à jour

Rien n'est automatisé, et c'est délibéré : une mise à jour doit être un geste
conscient, vérifié à l'écran. Retélécharger depuis la même source, en changeant
le numéro de version, puis contrôler que les icônes et la police s'affichent
toujours.

```bash
B=https://cdnjs.cloudflare.com/ajax/libs
curl -sS -o chartjs/chart.umd.min.js "$B/Chart.js/<version>/chart.umd.min.js"
curl -sS -o fontawesome/css/fontawesome.min.css "$B/font-awesome/<version>/css/fontawesome.min.css"
curl -sS -o fontawesome/css/solid.min.css "$B/font-awesome/<version>/css/solid.min.css"
curl -sS -o fontawesome/webfonts/fa-solid-900.woff2 "$B/font-awesome/<version>/webfonts/fa-solid-900.woff2"
```

`solid.min.css` référence à l'origine un `.ttf` que nous n'embarquons pas : la
référence est retirée après téléchargement, sinon un navigateur sans woff2
déclencherait une requête en 404.
