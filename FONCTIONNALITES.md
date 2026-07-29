# Récapitulatif fonctionnalités & positionnement marché
*Application de gestion de recettes & coûts pour métiers de fabrication au poids (charcutier-traiteur en premier client). État au 29 juillet 2026. Nom commercial à arrêter.*

---

## 1. En une phrase

Un outil **simple et précis** de fiches techniques et de pilotage de la rentabilité, **spécialisé pour les métiers qui fabriquent au poids** (charcuterie-traiteur, transformation), **sans la lourdeur** des suites de gestion de restaurant (pas de stock, pas de caisse, pas de facturation) — avec une **mercuriale alimentée par les factures électroniques fournisseurs (Factur-X)**, au moment où leur réception devient obligatoire pour toutes les entreprises (1ᵉʳ septembre 2026).

---

## 2. Fonctionnalités livrées

### Recettes & fiches techniques
- Fiches techniques détaillées, recettes et **sous-recettes** (une préparation peut entrer dans une autre, valorisée à son coût réel).
- **Fabrication au poids** : quantité brute, **% de perte** et **% de rendement** par ligne et au niveau recette, sortie en poids ou en portions.
- Mise à l'échelle des quantités à l'impression (production différente de la base).

### Calcul des coûts & marges (rigueur métier)
- Coût matière, **main d'œuvre en direct** (minutes × taux horaire courant), emballage.
- **Trois lectures simultanées** souvent confondues ailleurs : **taux de marque** (marge / PV), **taux de marge** (marge / coût), **coefficient** (PV / coût HT), plus le **ratio matière / food cost**.
- Prix conseillé HT **et** TTC, marge par unité, gestion fine HT/TVA.
- **Recalcul automatique en cascade** : un changement de prix d'achat ou de taux de main d'œuvre répercute immédiatement toutes les recettes et sous-recettes concernées.

### Garde-fous / fiabilité (rare sur le marché)
Avertissements explicites quand un chiffre n'est pas fiable, au lieu d'afficher un coût faussement précis :
- ligne **sans prix** → comptée à 0 € et signalée ;
- **unité incompatible** avec l'ingrédient → signalée ;
- conversion litre↔kg **approximative** (densité 1) → signalée ;
- **sortie > entrées** (incohérence de rendement) et **marque > 100 %** → signalés.

### Pièce ↔ grammes (nouveau)
- Champ **poids unitaire (g/pièce)** sur l'ingrédient : permet de saisir un ingrédient tarifé à la pièce (œuf…) tout en obtenant **à la fois** le coût correct **et** les valeurs nutritionnelles.

### Allergènes
- Gestion des **14 allergènes réglementaires** (INCO) + traces, **agrégation automatique** sur toute la recette (sous-recettes incluses), affichage et report sur les PDF.

### Valeurs nutritionnelles
- Calcul des **7 valeurs réglementaires pour 100 g** (énergie kJ/kcal, matières grasses dont AG saturés, glucides dont sucres, protéines, sel) à partir de la table officielle **Ciqual / Anses**.
- Valeurs moyennes **calculées** (acceptées par la réglementation INCO) → évite des analyses labo à ~80–150 € par recette.
- Gestion de la concentration par perte d'eau à la cuisson ; signalement honnête quand un ingrédient n'a pas de donnée (calcul « incomplet, sous-estimé »).
- **Rattachement Ciqual assisté** : l'application propose l'aliment correspondant au nom de l'ingrédient (« Épaule de porc désossée » → « Porc, épaule crue »), en une suggestion classée par score. Rattachement en masse possible en ligne de commande (`app:match-ciqual`).
- Tout rattachement automatique reste marqué **« à vérifier »** tant qu'un utilisateur ne l'a pas confirmé : l'état cru/cuit change fortement les valeurs, et elles finissent en déclaration INCO.

### Impression / documents
- **Fiche technique complète** (avec coûts, marges, traçabilité prix) — réservée aux profils éditeurs.
- **Fiche labo** (process, quantités nettes, allergènes, nutrition — **sans aucune donnée financière**) — accessible aussi aux lecteurs.

### Multi-utilisateurs & rôles
- Rôles **éditeur** / **lecteur**. Le lecteur consulte mais ne modifie rien et **ne peut imprimer que la fiche labo** (protégé côté serveur, pas seulement masqué).

### Mercuriale & traçabilité des prix
- Prix d'achat **datés** par fournisseur, historisés ; chaque ligne de recette tracée (date + fournisseur du prix appliqué) sur les fiches.
- **Import de facture électronique** : dépôt d'un PDF Factur-X ou d'un XML CII/EN 16931, extraction du fournisseur (SIRET) et des lignes, **rapprochement automatique** avec les ingrédients (correspondances mémorisées d'un import sur l'autre, puis similarité de libellé), écran de validation, puis création des prix datés et **recalcul en cascade** des recettes concernées.
- **File d'attente des factures** : une facture est enregistrée dès sa réception, avec ses correspondances proposées, et attend l'arbitrage humain — elle peut donc arriver sans personne devant l'écran, et une validation interrompue se reprend intacte. Doublons écartés d'office (même fournisseur, même numéro).
- **Écart de prix affiché à la validation** : chaque ligne montre son écart avec le dernier prix connu, signalé au-delà de 15 %. C'est ce qui permet de repérer un changement de conditionnement — un lot de 5 kg facturé à la pièce — avant qu'il ne contamine vingt fiches techniques.

### Déploiement / exploitation (SaaS multi-instances)
- **Une instance isolée par client** (base de données séparée), déployée par script en une commande, avec sous-domaine, **HTTPS automatique** et **mise à jour par webhook** (git push → déploiement).
- **Instance de démo/dev sur la branche `master`**, **instances clients sur la branche `production`** : on développe sans risque, on ne livre aux clients que ce qui est validé.

---

## 3. Le différenciateur central

Tous les bons outils savent **recalculer** les marges quand un prix change. Le verrou, c'est **d'où vient le nouveau prix** : aujourd'hui, partout, il est ressaisi **à la main** (mercuriale manuelle).

L'axe unique de l'application est de **faire entrer les prix tout seuls** à partir des **factures électroniques fournisseurs (Factur-X)**, puis de laisser le recalcul en cascade faire le reste. Les marges sont alors toujours à jour **sans saisie**.

**Vent réglementaire favorable, et imminent** : la réforme française de la facturation électronique rend la **réception** de factures électroniques **obligatoire pour toutes les entreprises au 1ᵉʳ septembre 2026** (émission étendue aux TPE/PME au 1ᵉʳ septembre 2027) — soit dans **cinq semaines**. Dès la rentrée, les charcutiers-traiteurs **recevront leurs factures fournisseurs en Factur-X** : exactement l'intrant dont la mercuriale automatique a besoin.

**Statut : livré.** Recalcul en cascade et import Factur-X (parsing, rapprochement des lignes, création des prix datés) sont en production. Restent à consolider : le volume de formats fournisseurs réellement rencontrés, et la récupération des factures **sans dépôt manuel**.

**Point à instruire** : le flux ne passera pas par un fichier que l'artisan télécharge puis dépose. Le portail public (PPF) a été recentré en octobre 2024 sur l'annuaire et l'e-reporting ; **tout l'échange de factures passe par des plateformes agréées** (ex-PDP), dont **137 sont déjà immatriculées**. Chaque client aura donc la sienne — le plus souvent celle de son expert-comptable (Pennylane, Tiime, Evoliz sont immatriculées et exposent des API).

Devenir plateforme agréée n'est pas la bonne réponse : immatriculation lourde, 137 acteurs en place, et notre valeur est **en aval** du transport de facture. Ce qu'il faut, c'est récupérer les factures sans geste manuel — adresse de réception dédiée d'abord (pont universel, indépendant de toute API), connecteur vers une plateforme agréée ensuite, choisie d'après celles réellement utilisées par les premiers clients. *Calendrier à revérifier : ce dispositif a déjà été décalé et remanié plusieurs fois.*

---

## 4. Comparatif marché (juin 2026)

Légende : ✅ livré / solide · ◐ partiel · ⭕ prévu (roadmap) · ❌ absent ou hors cible

> **Statut des colonnes concurrentes** (revu le 29 juillet 2026) : établies à partir de pages éditeurs, de comparateurs et du blog d'un concurrent — **aucun produit testé en main**. Notre colonne, elle, est vérifiable dans le code.
>
> Deux réserves à garder en tête. D'abord, l'affirmation « Ratatool est statique » remonte, de proche en proche, au **blog de RestoPilot**, éditeur concurrent qui se positionne précisément contre lui ; les comparateurs qui la reprennent ne l'ont pas retestée. Ratatool offrant un essai gratuit de deux semaines, cette vérification coûte deux heures et vaut mieux que dix citations. Ensuite, la publicité comparative nommant un concurrent doit être objective, vérifiable et non trompeuse (art. L122-1 et suivants du code de la consommation) : les ❌ sur un produit nommé, repris d'un concurrent, sont le point le plus exposé du tableau.

| Critère | **Notre app** | Ratatool | RestoPilot | ogmah | Craftybase |
|---|---|---|---|---|---|
| Cible principale | Charcutier-traiteur, fabrication au poids | Restaurant, traiteur, boulanger | Restaurant | Restaurant (+ stock) | Makers / artisans (US, anglais) |
| Langue / marché | 🇫🇷 | 🇫🇷 | 🇫🇷 | 🇫🇷 | 🇬🇧 US |
| Tarif d'entrée annoncé | À fixer | 29 € HT/mois (jusqu'à ~49 € selon formule), essai 2 semaines | Gratuit en bêta, puis 29 € HT/mois annoncé (39 € ailleurs sur leur site) | n.c. | Abonnement en $ |
| Fiches techniques | ✅ | ✅ | ✅ | ✅ | ◐ |
| Sous-recettes imbriquées | ✅ | ✅ | ✅ (multi-niveaux annoncé) | ◐ | ◐ |
| Fabrication au poids (pertes/rendements) | ✅ | ◐ | ◐ | ◐ | ◐ |
| Marque / marge / coef + food cost | ✅ (les 4) | ◐ | ◐ | ◐ | ◐ |
| Recalcul auto en cascade sur prix | ✅ | ❌ (statique) — source concurrente, non vérifié | ✅ (annoncé) | ◐ | ◐ |
| **Mercuriale alimentée par Factur-X** | ✅ (axe unique) | ❌ | ❌ | ❌ | ❌ |
| Réception des factures sans dépôt manuel | ⭕ | ❌ | ❌ | ❌ | ❌ |
| Garde-fous / alertes de fiabilité | ✅ | ❌ | ◐ | ◐ | ◐ |
| Allergènes (14 INCO) **+ traces distinctes** | ✅ | ✅ | ◐ | ◐ | ◐ |
| Valeurs nutritionnelles | ✅ (Ciqual / Anses) | ◐ (base **USDA** ; sorties INCO annoncées) | ◐ | ❌ | ◐ |
| Ingénierie de menu / arbitrage par marge | ⭕ (roadmap) | ❌ | ✅ (méthode Omnès, annoncé) | ◐ | ◐ |
| Rattachement Ciqual assisté (suggestion auto) | ✅ | n.c. | n.c. | n.c. | n.c. |
| Fiche labo séparée (sans coûts) | ✅ | ❌ | ❌ | ❌ | ❌ |
| Rôles + impression restreinte | ✅ | ◐ | ◐ | ◐ | ◐ |
| Gestion de stock | ❌ (choix) | ✅ | ◐ | ✅ | ✅ |
| Facturation / caisse | ❌ (choix) | ◐ | ❌ | ◐ | ❌ |

**Lecture rapide.** Ratatool est l'option de référence française accessible (29 € HT/mois) et fait bien les fiches techniques, avec un angle mort à confirmer : sa base nutritionnelle est **américaine (USDA)**, ce qui laisse Ciqual / Anses comme avantage réel côté français. Il lui est reproché de rester **statique** — pas de recalcul global des marges quand un prix d'achat bouge — mais ce reproche vient d'un concurrent, à vérifier soi-même.

RestoPilot est le concurrent le plus proche de nous, et plus proche que ce tableau ne le laissait croire : il annonce le recalcul en cascade, des sous-recettes multi-niveaux illimitées, et l'**ingénierie de menu** (méthode Omnès) — soit, déjà livré chez lui, l'équivalent du point 2 de notre roadmap. Il vise le restaurant, il est gratuit en bêta, et sa mercuriale reste **ressaisie à la main**. ogmah est une suite restaurant orientée stock. Craftybase est anglophone et pensé pour les makers.

**Aucun de ces outils n'annonce l'entrée automatique des prix depuis les factures électroniques** : c'est l'espace occupé — et il l'est avec du code livré, pas une promesse de roadmap. C'est le seul avantage qui tient encore si RestoPilot exécute bien son plan ; le reste (cascade, sous-recettes, arbitrage par marge) est rattrapable, voire déjà rattrapé.

**Ce que ce tableau ne dit pas.** Les concurrents cumulent les ◐, ce qui est commode mais peu informatif : un ◐ signifie ici « fait, mais pas de la même façon », pas « fait à moitié ». Deux lignes seraient plus convaincantes qu'une colonne de symboles, parce qu'elles sont démontrables en trente secondes devant un artisan : le **contrôle de cohérence** (une recette qui sort plus de poids qu'elle n'en reçoit est signalée, au lieu d'afficher un coût faussement précis) et la **fiche labo sans données financières**, imprimable par un apprenti sans lui montrer les marges.

---

## 5. Choix assumés (ce que l'app ne fait pas — volontairement)

- **Pas de gestion de stock, pas de facturation, pas de caisse** : on reste sur recette & coût, là où les artisans souffrent vraiment, sans la complexité d'un ERP.
- Nutrition : limite connue sur l'égouttage des graisses à la cuisson (légère surestimation) — correction manuelle prévue en v2.
- Rattachement Ciqual : les suggestions automatiques restent **à confirmer par un humain**. L'état cru/cuit change fortement les valeurs, et elles finissent en déclaration INCO : on préfère laisser un ingrédient non rattaché qu'associer un aliment faux.

---

## 6. Pistes (roadmap)

1. **Réception des factures sans geste manuel** : se brancher sur une PDP, ou offrir une adresse de réception dédiée, pour que la mercuriale se mette à jour sans dépôt de fichier. L'import Factur-X étant livré, c'est là que se joue la suite du différenciateur — et l'échéance du 1ᵉʳ septembre 2026 en fait le bon moment.
2. **Rentabilité par produit / gamme** (l'équivalent charcutier du *menu engineering*) : classer les produits par marge et ratio matière, puis enrichir avec un volume de ventes léger (saisie périodique ou import caisse) — sans devenir un outil de gestion des ventes.
3. Surcharge manuelle des valeurs nutritionnelles (cuisson) ; étiquettes produit prêtes pour la vente préemballée et les appels d'offres B2B.

---

### Sources

Consultées le 29 juillet 2026 :

- [restopilot.ai/blog/alternative-a-ratatool](https://restopilot.ai/blog/alternative-a-ratatool/) — **blog d'un concurrent** : origine des reproches faits à Ratatool (recalcul statique, base USDA), et source de ses propres annonces (bêta gratuite puis 29 € HT/mois, méthode Omnès, sous-recettes multi-niveaux).
- [logiciels.pro/ratatool](https://www.logiciels.pro/ratatool/) et [comparatif-logiciels.fr/logiciel/avis-ratatool](https://www.comparatif-logiciels.fr/logiciel/avis-ratatool/) — tarif 29 € HT/mois, essai 2 semaines, étiquetage nutritionnel sur base USDA avec sorties au format INCO.
- [bonjouridee.com/ratatool](https://www.bonjouridee.com/ratatool/) et [digitechnologie.com/ratatool-productivite-rentabilite](https://digitechnologie.com/ratatool-productivite-rentabilite/) — description fonctionnelle (fiches, coûts, bons de commande).
- Calendrier de la facturation électronique : sources officielles et cabinets comptables (réception obligatoire 1ᵉʳ sept. 2026, émission TPE/PME 1ᵉʳ sept. 2027) — **à revérifier**, le dispositif a déjà été remanié.

**Ce qui reste à faire pour que ce tableau soit opposable** : ouvrir l'essai gratuit de Ratatool et vérifier soi-même le comportement au changement de prix ; créer un compte RestoPilot en bêta et mesurer l'écart réel ; combler les cellules « n.c. » d'ogmah et Craftybase. Tant que ce n'est pas fait, ce document est un outil de réflexion interne, pas un argumentaire client.
