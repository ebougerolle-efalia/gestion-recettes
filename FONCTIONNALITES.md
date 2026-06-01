# Récapitulatif fonctionnalités & positionnement marché
*Application de gestion de recettes & coûts pour métiers de fabrication au poids (charcutier-traiteur en premier client). État au juin 2026. Nom commercial à arrêter.*

---

## 1. En une phrase

Un outil **simple et précis** de fiches techniques et de pilotage de la rentabilité, **spécialisé pour les métiers qui fabriquent au poids** (charcuterie-traiteur, transformation), **sans la lourdeur** des suites de gestion de restaurant (pas de stock, pas de caisse, pas de facturation) — et conçu pour brancher demain la **mercuriale automatique via les factures électroniques (Factur-X)**, dont la réception devient obligatoire pour toutes les entreprises au 1ᵉʳ septembre 2026.

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

### Impression / documents
- **Fiche technique complète** (avec coûts, marges, traçabilité prix) — réservée aux profils éditeurs.
- **Fiche labo** (process, quantités nettes, allergènes, nutrition — **sans aucune donnée financière**) — accessible aussi aux lecteurs.

### Multi-utilisateurs & rôles
- Rôles **éditeur** / **lecteur**. Le lecteur consulte mais ne modifie rien et **ne peut imprimer que la fiche labo** (protégé côté serveur, pas seulement masqué).

### Mercuriale & traçabilité des prix
- Prix d'achat **datés** par fournisseur, historisés ; chaque ligne de recette tracée (date + fournisseur du prix appliqué) sur les fiches.

### Déploiement / exploitation (SaaS multi-instances)
- **Une instance isolée par client** (base de données séparée), déployée par script en une commande, avec sous-domaine, **HTTPS automatique** et **mise à jour par webhook** (git push → déploiement).
- **Instance de démo/dev sur la branche `master`**, **instances clients sur la branche `production`** : on développe sans risque, on ne livre aux clients que ce qui est validé.

---

## 3. Le différenciateur central

Tous les bons outils savent **recalculer** les marges quand un prix change. Le verrou, c'est **d'où vient le nouveau prix** : aujourd'hui, partout, il est ressaisi **à la main** (mercuriale manuelle).

L'axe unique de l'application est de **faire entrer les prix tout seuls** à partir des **factures électroniques fournisseurs (Factur-X)**, puis de laisser le recalcul en cascade faire le reste. Les marges sont alors toujours à jour **sans saisie**.

**Vent réglementaire favorable** : la réforme française de la facturation électronique rend la **réception** de factures électroniques **obligatoire pour toutes les entreprises au 1ᵉʳ septembre 2026** (émission étendue aux TPE/PME au 1ᵉʳ septembre 2027). Autrement dit, dès la rentrée 2026, les charcutiers-traiteurs **recevront leurs factures fournisseurs en Factur-X** — exactement l'intrant dont la mercuriale automatique a besoin. (Statut : recalcul en cascade **livré** ; alimentation auto par Factur-X = **chantier prioritaire**.)

---

## 4. Comparatif marché (juin 2026)

Légende : ✅ livré / solide · ◐ partiel · ⭕ prévu (roadmap) · ❌ absent ou hors cible

| Critère | **Notre app** | Ratatool | RestoPilot | ogmah | Craftybase |
|---|---|---|---|---|---|
| Cible principale | Charcutier-traiteur, fabrication au poids | Restaurant, traiteur, boulanger | Restaurant | Restaurant (+ stock) | Makers / artisans (US, anglais) |
| Langue / marché | 🇫🇷 | 🇫🇷 | 🇫🇷 | 🇫🇷 | 🇬🇧 US |
| Tarif d'entrée annoncé | À fixer | ~29 €/mois | Bêta gratuite | n.c. | Abonnement $ |
| Fiches techniques | ✅ | ✅ | ✅ | ✅ | ◐ |
| Sous-recettes imbriquées | ✅ | ◐ | ◐ | ◐ | ◐ |
| Fabrication au poids (pertes/rendements) | ✅ | ◐ | ◐ | ◐ | ◐ |
| Marque / marge / coef + food cost | ✅ (les 4) | ◐ | ◐ | ◐ | ◐ |
| Recalcul auto en cascade sur prix | ✅ | ❌ (statique) | ✅ | ◐ | ◐ |
| **Mercuriale alimentée par Factur-X** | ⭕ (axe unique) | ❌ | ❌ | ❌ | ❌ |
| Garde-fous / alertes de fiabilité | ✅ | ❌ | ◐ | ◐ | ◐ |
| Allergènes (14 INCO) | ✅ | ✅ | ◐ | ◐ | ◐ |
| Valeurs nutritionnelles (Ciqual) | ✅ | ✅ | ◐ | ❌ | ◐ |
| Fiche labo séparée (sans coûts) | ✅ | ❌ | ❌ | ❌ | ❌ |
| Rôles + impression restreinte | ✅ | ◐ | ◐ | ◐ | ◐ |
| Gestion de stock | ❌ (choix) | ✅ | ◐ | ✅ | ✅ |
| Facturation / caisse | ❌ (choix) | ◐ | ❌ | ◐ | ❌ |

**Lecture rapide.** Ratatool est l'option de référence française accessible (~29 €/mois) et fait bien les fiches techniques, mais reste **statique** : il ne recalcule pas automatiquement l'ensemble des marges quand un prix d'achat bouge — un point que des comparateurs (y compris un concurrent) soulignent eux-mêmes. RestoPilot apporte le recalcul en cascade mais à partir d'une **mercuriale ressaisie à la main**, et vise le restaurant. ogmah est une suite restaurant orientée stock. Craftybase est anglophone et pensé pour les makers. **Personne ne fait entrer les prix automatiquement depuis les factures électroniques** : c'est l'espace à occuper.

---

## 5. Choix assumés (ce que l'app ne fait pas — volontairement)

- **Pas de gestion de stock, pas de facturation, pas de caisse** : on reste sur recette & coût, là où les artisans souffrent vraiment, sans la complexité d'un ERP.
- Nutrition : limite connue sur l'égouttage des graisses à la cuisson (légère surestimation) — correction manuelle prévue en v2.
- Alimentation Factur-X : différenciateur **pas encore livré** ; c'est la prochaine grande brique.

---

## 6. Pistes (roadmap)

1. **Import Factur-X → mercuriale automatique** (le cœur du positionnement, calé sur l'échéance de septembre 2026).
2. **Rentabilité par produit / gamme** (l'équivalent charcutier du *menu engineering*) : classer les produits par marge et ratio matière, puis enrichir avec un volume de ventes léger (saisie périodique ou import caisse) — sans devenir un outil de gestion des ventes.
3. Surcharge manuelle des valeurs nutritionnelles (cuisson) ; étiquettes produit prêtes pour la vente préemballée et les appels d'offres B2B.

---

*Sources marché (consultées en juin 2026) : pages éditeur et comparateurs Ratatool (tarif d'entrée ~29 €/mois) ; comparatif RestoPilot sur les logiciels de fiches techniques ; sources officielles et cabinets comptables pour le calendrier de la facturation électronique (réception obligatoire 1ᵉʳ sept. 2026, émission TPE/PME 1ᵉʳ sept. 2027). Les positions concurrentes sont des synthèses ; à revérifier avant tout usage commercial.*
