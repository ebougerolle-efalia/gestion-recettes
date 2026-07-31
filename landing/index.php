<?php
/**
 * Vitrine publique.
 *
 * En PHP et non en HTML statique pour une seule raison : afficher le résultat
 * d'une demande sans dépendre de JavaScript. Le formulaire envoie, redirige,
 * et l'état revient ici en clair.
 */
declare(strict_types=1);

$etats = [
    'ok'       => ['succes', 'Demande reçue. On vous répond sous deux jours ouvrés, à l\'adresse que vous avez indiquée.'],
    'invalide' => ['erreur', 'Il manque quelque chose : le nom, un courriel valide, le métier et l\'offre sont nécessaires.'],
    'trop'     => ['erreur', 'Trop de demandes envoyées depuis peu. Réessayez dans une heure, ou écrivez-nous directement.'],
    'erreur'   => ['erreur', 'La demande n\'a pas pu être enregistrée. Réessayez, ou écrivez-nous directement.'],
];

$etat    = $_GET['etat'] ?? '';
$retour  = is_string($etat) && isset($etats[$etat]) ? $etats[$etat] : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<!--
    Vitrine publique.

    Le nom commercial n'est pas arrêté : la page n'en porte donc aucun, comme le
    logo lui-même, dessiné sans mot-symbole pour cette raison. Les emplacements
    où il viendra sont signalés par « NOM À DÉFINIR » dans les commentaires.
    Voir README.md pour la procédure d'insertion.

    Aucune ressource externe n'est chargée : ni police, ni framework, ni icône
    distante. Voir l'en-tête de assets/site.css pour le motif.
-->

<title>Vos prix d'achat bougent. Vos fiches techniques suivent.</title>
<meta name="description" content="Fiches techniques, coûts de revient et marges pour charcutiers-traiteurs, boulangers et pâtissiers. Les factures fournisseurs entrent seules, les recettes se recalculent.">
<meta name="robots" content="index, follow">

<meta property="og:type" content="website">
<meta property="og:title" content="Vos prix d'achat bougent. Vos fiches techniques suivent.">
<meta property="og:description" content="Les factures fournisseurs entrent seules. Les coûts de revient se recalculent. Vous voyez le jour où une recette passe sous votre marge.">
<meta property="og:locale" content="fr_FR">

<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/site.css">
</head>
<body>

<header class="entete">
    <div class="enveloppe">
        <!-- NOM À DÉFINIR : le mot-symbole viendra dans le <span> qui suit le SVG. -->
        <a class="marque" href="#haut" aria-label="Accueil">
            <svg viewBox="0 0 32 32" width="26" height="26" aria-hidden="true" focusable="false"
                 fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <path d="M7 11h18"/><path d="M16 11v11"/><path d="M11.5 24h9"/>
                <path d="M9.5 11v1.5"/><path d="M22.5 11v1.5"/>
                <path d="M6 13h7l-3.5 4.5z"/><path d="M19 13h7l-3.5 4.5z"/>
                <circle cx="16" cy="9.4" r="1.5" fill="currentColor" stroke="none"/>
            </svg>
            <span class="jeton-alpha">Version Alpha</span>
        </a>

        <nav>
            <a href="#fonctionnement">Comment ça marche</a>
            <a href="#offres">Offres</a>
            <a class="bouton" href="#acces">Demander un accès</a>
        </nav>
    </div>
</header>

<main id="haut">

    <!-- ─── Ouverture ─────────────────────────────────────────────────────── -->
    <section class="ouverture">
        <div class="enveloppe">
            <div>
                <h1>Vos prix d'achat bougent. Vos prix de vente, <em>non</em>.</h1>

                <p class="chapeau">
                    Entre deux hausses fournisseurs, une recette peut perdre six points de
                    marque sans que rien ne le signale. Ici, les factures entrent seules,
                    les fiches techniques se recalculent, et l'écart se voit le jour où il
                    apparaît.
                </p>

                <div class="actions">
                    <a class="bouton" href="#acces">Demander un accès</a>
                    <a class="bouton bouton-discret" href="#fonctionnement">Voir comment ça marche</a>
                </div>

                <p class="mention-actions">
                    Conçu pour les métiers qui fabriquent au poids — charcuterie-traiteur,
                    boulangerie, pâtisserie.
                </p>
            </div>

            <!--
                L'élément signature : la marque est une balance à deux plateaux, on lui
                fait faire son travail plutôt que de la poser en décoration. Le plateau
                du coût s'alourdit quand la facture arrive, la marque chute avec lui.
            -->
            <div class="balance-cadre">
                <svg class="balance" id="balance" viewBox="0 0 400 232" role="img"
                     aria-label="Balance à deux plateaux : le plateau du coût matière s'alourdit à l'arrivée d'une facture, la marque tombe de 42 % à 36 %.">

                    <g class="fleau" fill="none" stroke="#e3b558" stroke-width="3.4"
                       stroke-linecap="round" stroke-linejoin="round">
                        <path d="M80 70h240"/>
                        <path d="M95 70v13"/>
                        <path d="M305 70v13"/>
                    </g>

                    <g fill="none" stroke="#e3b558" stroke-width="3.4"
                       stroke-linecap="round" stroke-linejoin="round">
                        <path d="M200 70v135"/>
                        <path d="M158 205h84"/>
                    </g>
                    <circle cx="200" cy="63" r="6" fill="#e3b558"/>

                    <!-- Plateau du coût matière -->
                    <g class="plateau-gauche">
                        <path d="M57 84h76l-38 46z" fill="rgba(227,181,88,0.14)" stroke="#e3b558"
                              stroke-width="3.4" stroke-linejoin="round"/>
                        <g class="facture">
                            <rect x="76" y="60" width="38" height="26" rx="3"
                                  fill="#f0eee8" stroke="#b8892e" stroke-width="1.6"/>
                            <path d="M82 68h26M82 73h26M82 78h16" stroke="#8d94a1" stroke-width="1.6" stroke-linecap="round"/>
                        </g>
                        <text x="95" y="152" text-anchor="middle" fill="#98a3b5"
                              font-family="ui-monospace, Menlo, Consolas, monospace" font-size="12"
                              letter-spacing="1.4">COÛT MATIÈRE</text>
                    </g>

                    <!-- Plateau du prix de vente -->
                    <g class="plateau-droit">
                        <path d="M267 84h76l-38 46z" fill="rgba(227,181,88,0.14)" stroke="#e3b558"
                              stroke-width="3.4" stroke-linejoin="round"/>
                        <text x="305" y="152" text-anchor="middle" fill="#98a3b5"
                              font-family="ui-monospace, Menlo, Consolas, monospace" font-size="12"
                              letter-spacing="1.4">PRIX DE VENTE</text>
                    </g>
                </svg>

                <div class="releve">
                    <span class="releve-libelle">Marque</span>
                    <span class="releve-valeur"><span id="marque">42</span>&nbsp;%</span>
                    <span class="releve-delta" id="delta" hidden>−6 pts</span>
                    <p class="releve-note">
                        Épaule de porc&nbsp;: <span class="somme">7,40&nbsp;€</span> →
                        <span class="somme">8,10&nbsp;€</span> le kilo. Six semaines sans que rien ne le dise.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── Les trois temps ───────────────────────────────────────────────── -->
    <section class="bandeau" id="fonctionnement">
        <div class="enveloppe">
            <p class="eyebrow">Ce qui se passe sans vous</p>
            <h2>Trois temps, dont deux se passent de vous.</h2>

            <!-- Numérotés parce que l'ordre porte l'information : rien ne se
                 recalcule avant que les prix ne soient entrés, et les prix
                 n'entrent pas avant que la facture n'arrive. -->
            <ol class="temps">
                <li>
                    <h3>La facture arrive</h3>
                    <p>
                        Vous donnez une adresse dédiée à vos fournisseurs. Ce qui y arrive est
                        relevé toutes les quinze minutes, sans que personne n'ouvre quoi que ce
                        soit. Un PDF ordinaire ou un scan est conservé et mis en attente de
                        saisie&nbsp;: rien ne se perd, rien n'est deviné.
                    </p>
                </li>
                <li>
                    <h3>Les prix entrent</h3>
                    <p>
                        Chaque ligne est rapprochée de votre catalogue. Une correspondance
                        tranchée une fois est reconnue les fois suivantes. Vous arbitrez ce qui
                        reste, en voyant l'écart avec le dernier prix connu — c'est là qu'on
                        repère un changement de conditionnement avant qu'il ne fausse tout.
                    </p>
                </li>
                <li>
                    <h3>Les fiches suivent</h3>
                    <p>
                        Le recalcul remonte toute la chaîne, sous-recettes comprises. Une hausse
                        sur le beurre touche la pâte, la pâte touche la tourte&nbsp;: les trois
                        sont à jour dans la seconde, et le tableau de bord nomme les recettes
                        passées sous votre objectif.
                    </p>
                </li>
            </ol>
        </div>
    </section>

    <!-- ─── Ce qu'on a à l'écran ──────────────────────────────────────────── -->
    <section class="bandeau bandeau-papier">
        <div class="enveloppe">
            <p class="eyebrow">À l'écran</p>
            <h2>Le calcul juste, d'abord.</h2>

            <div class="grille-capacites">
                <article class="capacite">
                    <h3>Fabrication au poids</h3>
                    <p>
                        Pertes au parage et rendements de cuisson pris pour ce qu'ils sont&nbsp;:
                        le coût porte sur la matière achetée, pas sur ce qui reste après. C'est la
                        différence entre une marge affichée et une marge réelle.
                    </p>
                </article>
                <article class="capacite">
                    <h3>Sous-recettes imbriquées</h3>
                    <p>
                        Une farce entre dans un pâté, qui entre dans un plateau. Autant de niveaux
                        que nécessaire, avec détection des boucles — et un seul endroit à corriger
                        quand la farce change.
                    </p>
                </article>
                <article class="capacite">
                    <h3>Prix conseillé, prix pratiqué</h3>
                    <p>
                        Le prix que votre coefficient appelle, et celui que vous affichez vraiment.
                        L'écart entre les deux est la seule mesure honnête de ce que vous gagnez.
                    </p>
                </article>
                <article class="capacite">
                    <h3>Fiches techniques imprimables</h3>
                    <p>
                        Composition, quantités, coûts, allergènes et marge sur une page tenable au
                        poste de travail. Éditées en PDF, avec le nom et le logo de votre maison.
                    </p>
                </article>
                <article class="capacite">
                    <h3>Mercuriale datée</h3>
                    <p>
                        Chaque prix garde sa date et son fournisseur. Une fiche indique donc
                        toujours d'où vient le chiffre qu'elle affiche — ce que demande un contrôle.
                    </p>
                </article>
                <article class="capacite">
                    <h3>Dérive des coûts sur 30 jours</h3>
                    <p>
                        Les recettes dont le coût de revient a le plus monté depuis un mois, en
                        rejouant les prix à leur date. L'écran qu'aucun outil ne peut afficher sans
                        recevoir les factures.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <!-- ─── Le périmètre, dit franchement ─────────────────────────────────── -->
    <section class="bandeau bandeau-sombre sombre">
        <div class="enveloppe-etroite">
            <p class="eyebrow">Le périmètre</p>
            <h2>Ce que l'outil ne fait pas.</h2>
            <p class="chapeau">
                Un logiciel de restaurant vous demanderait de tenir un stock, une caisse et
                une facturation client pour obtenir un coût de revient. Celui-ci ne fait
                qu'une chose, et la fait entièrement.
            </p>
            <ul class="exclusions">
                <li>Pas de gestion de stock</li>
                <li>Pas de caisse</li>
                <li>Pas de facturation client</li>
                <li>Pas de planning</li>
                <li>Pas de commandes fournisseurs</li>
                <li>Pas de relevé de ventes à saisir</li>
            </ul>
        </div>
    </section>

    <!-- ─── Offres ────────────────────────────────────────────────────────── -->
    <section class="bandeau" id="offres">
        <div class="enveloppe">
            <p class="eyebrow">Offres</p>
            <h2>Deux formules, une différence&nbsp;: qui saisit les prix.</h2>

            <div class="offres">
                <article class="offre">
                    <p class="offre-nom">Starter</p>
                    <p class="offre-promesse">Le calcul juste, les prix saisis par vous.</p>
                    <p class="offre-prix somme">19&nbsp;€ <small>/ mois</small></p>
                    <p class="offre-base">par établissement, HT, sans engagement</p>
                    <ul>
                        <li><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg> Recettes, sous-recettes, pertes et rendements</li>
                        <li><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg> Prix conseillé, prix pratiqué, marge réelle</li>
                        <li><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg> Fiches techniques PDF à votre en-tête</li>
                        <li><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg> Mercuriale datée et recalcul en cascade</li>
                        <li><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg> Import d'une facture Factur-X que vous déposez</li>
                        <li><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg> Un utilisateur</li>
                    </ul>
                    <a class="bouton bouton-large" href="#acces">Demander un accès Starter</a>
                </article>

                <article class="offre offre-mise-en-avant">
                    <span class="offre-etiquette">Les prix entrent seuls</span>
                    <p class="offre-nom">Pro</p>
                    <p class="offre-promesse">Vos fournisseurs alimentent l'outil à votre place.</p>
                    <p class="offre-prix somme">29&nbsp;€ <small>/ mois</small></p>
                    <p class="offre-base">par établissement, HT, sans engagement</p>
                    <ul>
                        <li><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg> Tout ce que contient Starter</li>
                        <li><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg> <strong>Adresse de réception dédiée</strong>, relevée toutes les 15 minutes</li>
                        <li><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg> <strong>Dérive des coûts sur 30 jours</strong></li>
                        <li><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg> Valeurs nutritionnelles Ciqual rattachées aux ingrédients</li>
                        <li><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg> Mercuriale imprimable pour la tournée d'achats</li>
                        <li><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg> Plusieurs utilisateurs, en lecture ou en saisie</li>
                    </ul>
                    <a class="bouton bouton-large" href="#acces">Demander un accès Pro</a>
                </article>
            </div>

            <p class="offre-note">
                Chaque établissement dispose de sa propre base, isolée des autres.
                Sauvegarde quotidienne, hébergement en France.
            </p>
        </div>
    </section>

    <!-- ─── Demande d'accès ───────────────────────────────────────────────── -->
    <section class="bandeau bandeau-papier" id="acces">
        <div class="enveloppe-etroite">
            <p class="eyebrow">Demander un accès</p>
            <h2>L'ouverture se fait au cas par cas.</h2>
            <p class="chapeau texte-doux" style="color: var(--texte-doux);">
                L'outil est en version Alpha&nbsp;: chaque établissement est installé et
                mis en route avec vous, catalogue de départ compris. Dites-nous votre
                métier, on vous répond sous deux jours ouvrés.
            </p>

<?php if ($retour !== null): ?>
            <p class="message message-<?= $retour[0] === 'succes' ? 'succes' : 'erreur' ?>"
               role="status" style="margin-top: 1.8rem; margin-bottom: 0;">
                <?= htmlspecialchars($retour[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </p>
<?php endif; ?>

            <!--
                Le formulaire fonctionne sans JavaScript : envoi classique, puis
                redirection vers cette page avec un état. C'est aussi ce qui le rend
                testable sans navigateur.
            -->
            <form class="formulaire" action="souscription.php" method="post">
                <div class="duo">
                    <div class="champ">
                        <label for="nom">Votre nom</label>
                        <input type="text" id="nom" name="nom" required maxlength="100" autocomplete="name">
                    </div>
                    <div class="champ">
                        <label for="etablissement">Votre établissement</label>
                        <input type="text" id="etablissement" name="etablissement" maxlength="150" autocomplete="organization">
                    </div>
                </div>

                <div class="duo">
                    <div class="champ">
                        <label for="courriel">Courriel</label>
                        <input type="email" id="courriel" name="courriel" required maxlength="180" autocomplete="email">
                    </div>
                    <div class="champ">
                        <label for="telephone">Téléphone <span style="text-transform: none; letter-spacing: 0; font-weight: 500;">(facultatif)</span></label>
                        <input type="tel" id="telephone" name="telephone" maxlength="30" autocomplete="tel">
                    </div>
                </div>

                <div class="duo">
                    <div class="champ">
                        <label for="metier">Métier</label>
                        <select id="metier" name="metier" required>
                            <option value="">Choisir…</option>
                            <option value="charcutier-traiteur">Charcutier-traiteur</option>
                            <option value="boulanger">Boulanger</option>
                            <option value="patissier">Pâtissier</option>
                            <option value="boucher">Boucher</option>
                            <option value="autre">Autre métier de bouche</option>
                        </select>
                    </div>
                    <div class="champ">
                        <label for="offre">Offre envisagée</label>
                        <select id="offre" name="offre" required>
                            <option value="">Choisir…</option>
                            <option value="starter">Starter — 19 € / mois</option>
                            <option value="pro">Pro — 29 € / mois</option>
                            <option value="indecis">Je ne sais pas encore</option>
                        </select>
                    </div>
                </div>

                <div class="champ">
                    <label for="message">Ce que vous cherchez <span style="text-transform: none; letter-spacing: 0; font-weight: 500;">(facultatif)</span></label>
                    <textarea id="message" name="message" maxlength="2000"
                              placeholder="Combien de fiches, quels fournisseurs, ce qui vous coince aujourd'hui…"></textarea>
                </div>

                <!-- Piège à robot : invisible, hors du parcours clavier, ignoré des
                     lecteurs d'écran. Rempli, la demande est écartée en silence. -->
                <div class="piege" aria-hidden="true">
                    <label for="site_web">Ne pas remplir</label>
                    <input type="text" id="site_web" name="site_web" tabindex="-1" autocomplete="off">
                </div>

                <p class="mention-donnees">
                    Ces informations servent uniquement à répondre à votre demande et à
                    préparer votre installation. Elles ne sont ni revendues ni transmises à
                    un tiers, et sont effacées au bout de douze mois sans suite. Vous pouvez
                    demander leur consultation ou leur effacement à tout moment&nbsp;:
                    voir les <a href="mentions-legales.html">mentions légales</a>.
                </p>

                <div>
                    <button class="bouton" type="submit">Envoyer la demande</button>
                </div>
            </form>
        </div>
    </section>

</main>

<footer class="pied">
    <div class="enveloppe">
        <!-- NOM À DÉFINIR : le mot-symbole viendra à côté de la balance. -->
        <svg viewBox="0 0 32 32" width="24" height="24" aria-hidden="true" focusable="false"
             fill="none" stroke="#e3b558" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
            <path d="M7 11h18"/><path d="M16 11v11"/><path d="M11.5 24h9"/>
            <path d="M9.5 11v1.5"/><path d="M22.5 11v1.5"/>
            <path d="M6 13h7l-3.5 4.5z"/><path d="M19 13h7l-3.5 4.5z"/>
            <circle cx="16" cy="9.4" r="1.5" fill="#e3b558" stroke="none"/>
        </svg>
        <span>Version Alpha — ouverture progressive</span>
        <span class="droite">
            <a href="mentions-legales.html">Mentions légales</a>
            <a href="mentions-legales.html#donnees">Données personnelles</a>
            <a href="#acces">Demander un accès</a>
        </span>
    </div>
</footer>

<script src="assets/site.js" defer></script>
</body>
</html>
