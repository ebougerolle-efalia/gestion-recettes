/**
 * Tailwind — configuration de l'application.
 *
 * Tailwind 3 et non 4, délibérément : la v4 fait passer la couleur de bordure
 * par défaut de gray-200 à currentColor, ce qui modifierait silencieusement des
 * dizaines de bordures. La migration v4 se fera une fois la refonte stabilisée.
 *
 * PHASE 1 — aucun changement visuel voulu.
 *
 * Ce fichier ne redéfinit donc NI l'échelle typographique, NI les couleurs
 * existantes : il se contente de remplacer le CDN par une compilation locale.
 * Les jetons ajoutés plus bas sont purement additifs — ils n'existent nulle part
 * dans les templates aujourd'hui et ne peuvent donc rien modifier tant que la
 * phase 2 ne les emploie pas.
 *
 * Les ~370 valeurs arbitraires des templates (text-[11px], bg-[#1c2434]…) ne
 * sont PAS converties ici : le compilateur Tailwind les génère nativement en
 * lisant les fichiers déclarés dans « content ». C'est un CSS prégénéré qui ne
 * les contient pas, pas une compilation. La conversion en jetons nommés relève
 * de la phase 2, quand l'échelle typographique change de toute façon.
 */
module.exports = {
    content: [
        './templates/**/*.html.twig',
        './src/**/*.php',
    ],

    theme: {
        extend: {
            /* ── Jetons de la refonte — additifs, inutilisés en phase 1 ──────
             *
             * Nommés d'après l'atelier plutôt que d'après une palette générique.
             * Tous les rapports de contraste indiqués ont été calculés, pas
             * supposés.
             */
            colors: {
                /* ── Textes ─────────────────────────────────────────────────
                 *
                 * Nommés par RÔLE et non par numéro, délibérément : il n'existe
                 * aucun ton de texte qui échoue au seuil AA, donc aucun moyen
                 * d'en employer un par accident. L'ancienne échelle en offrait
                 * trois (gray-200/300/400, 233 usages à 2,5:1 ou moins).
                 *
                 * Les quatre tons passent 4,5:1 sur craie ET sur inox.
                 */
                ardoise: {
                    DEFAULT: '#16202B', /* texte principal — 16,0:1 / 13,7:1 */
                    fort:    '#33434E', /* titres secondaires — 9,9:1 / 8,5:1 */
                    doux:    '#475761', /* texte courant secondaire — 7,3:1 / 6,2:1 */
                    faible:  '#5B6B73', /* mentions, légendes — 5,4:1 / 4,6:1 */
                    clair:   '#243447', /* survol sur fond sombre */
                },

                /* ── Structure ──────────────────────────────────────────────
                 * Jamais de texte : ces tons ne passent aucun seuil, et c'est
                 * normal — ce sont des traits et des fonds.
                 */
                trait:  { DEFAULT: '#CBD2CE', fin: '#DFE4E1' },
                voile:  '#EFF2F0',

                /* Surface de l'application : gris légèrement VERT, comme l'inox
                   brossé d'un laboratoire — et non le gris bleuté de Tailwind
                   (#f1f5f9) qui ne vient de nulle part. */
                inox: '#E8EBE9',

                /* Surface des cartes. */
                craie: '#FBFCFB',

                laiton: {
                    /* Ornement SEUL : la balance, les aplats, les bordures sur
                       fond sombre. 6,3:1 sur ardoise, mais 2,6:1 sur clair —
                       ne jamais l'employer pour du texte sur fond clair. */
                    DEFAULT: '#C9973F',
                    /* Le laiton quand il doit porter du TEXTE sur fond clair,
                       et l'anneau de focus : 6,0:1 sur craie. Un focus doit
                       atteindre 3:1 (WCAG 1.4.11), que le laiton ornemental
                       n'atteint pas. */
                    patine: '#7E5B1E',
                    /* Fond d'alerte douce, remplace bg-amber-50. */
                    voile:  '#F6EEDF',
                },

                /* Hausse de prix, marge sous l'objectif. 6,4:1 sur craie.
                   « clair » est la même alerte posée sur fond SOMBRE : le sang
                   ordinaire n'y atteint que 2,5:1, illisible. 5,9:1 sur ardoise. */
                sang:   { DEFAULT: '#A8342A', clair: '#E08074', voile: '#F7EAE8' },

                /* Marge saine. 5,9:1 sur craie. */
                pousse: { DEFAULT: '#3F6B52', voile: '#E9F0EC' },
            },

            fontFamily: {
                /* Inter est auto-hébergée en variable 300–800, voir
                   public/assets/vendor/inter/. Identique à la règle CSS que
                   base.html.twig posait à la main : aucun changement.

                   Aucune police d'affichage n'est ajoutée, et c'est délibéré :
                   sur un outil ouvert à 5 h du matin, une seconde fonte est
                   l'accessoire à retirer. Les trois rôles — corps, affichage,
                   données — sont tenus par la graisse et l'échelle. */
                sans: ['Inter', 'system-ui', 'sans-serif'],
            },

            /* ── Échelle typographique ──────────────────────────────────────
             *
             * L'ancienne interface descendait à 8 px et employait 281 fois une
             * taille de TEXTE inférieure à 13 px. Pour un artisan de 50 ans,
             * écran à bout de bras dans un laboratoire, ce n'est pas une
             * question de goût.
             *
             * Les noms de Tailwind sont conservés mais leurs valeurs relevées :
             * c'est ce qui déplace les 443 usages de text-sm et text-xs d'un
             * seul geste, sans toucher aux templates.
             *
             * Les icônes ne suivent pas cette échelle — un glyphe posé à côté
             * d'un libellé n'a pas les mêmes exigences qu'une phrase. Elles
             * gardent des valeurs arbitraires, relevées à 11–12 px.
             */
            fontSize: {
                /* Seul le BAS de l'échelle est relevé. Le haut revient aux
                 * valeurs d'origine : personne ne trouvait les titres trop
                 * petits, et les avoir agrandis avait porté le tableau de bord
                 * à 1 640 px de contenu pour 900 px de fenêtre — presque deux
                 * écrans à faire défiler pour lire quatre indicateurs.
                 *
                 * Lisibilité n'est pas synonyme de gros : c'est un plancher,
                 * pas un facteur d'agrandissement.
                 */
                '2xs': ['0.8125rem', { lineHeight: '1.1rem' }],   /* 13 px — plancher absolu du texte */
                xs:    ['0.875rem',  { lineHeight: '1.2rem' }],   /* 14 px — était 12 */
                sm:    ['0.9375rem', { lineHeight: '1.3rem' }],   /* 15 px — était 14, base de lecture */
                base:  ['1rem',      { lineHeight: '1.5rem' }],   /* 16 px — inchangé */
                lg:    ['1.125rem',  { lineHeight: '1.6rem' }],   /* 18 px — inchangé */
                xl:    ['1.25rem',   { lineHeight: '1.65rem' }],  /* 20 px — inchangé */
                '2xl': ['1.5rem',    { lineHeight: '1.85rem' }],  /* 24 px — inchangé */
                '3xl': ['1.75rem',   { lineHeight: '2rem' }],     /* 28 px — resserré, c'est le chiffre des indicateurs */
            },

            minHeight: {
                /* Cible tactile minimale, employée en phase 2. */
                cible: '2.25rem',
            },
            minWidth: {
                cible: '2.25rem',
            },
        },
    },

    plugins: [
        /* Le CDN était chargé avec « ?plugins=forms » : le conserver est
           indispensable, sans quoi tous les champs de formulaire changeraient
           d'aspect — exactement ce que la phase 1 s'interdit. */
        require('@tailwindcss/forms'),
    ],
};
