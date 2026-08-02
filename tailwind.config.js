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
                /* Fond sombre et couleur de texte principale. 16,0:1 sur craie. */
                ardoise: {
                    DEFAULT: '#16202B',
                    clair: '#243447',
                },

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
                    /* Le laiton quand il doit porter du TEXTE sur fond clair.
                       6,0:1 sur craie. C'est ce jeton qui règle le défaut
                       d'accessibilité de la marque sans renoncer à sa couleur —
                       l'actuel #E3B558 plafonne à 1,91:1 sur blanc. */
                    patine: '#7E5B1E',
                },

                /* Hausse de prix, marge sous l'objectif. 6,4:1 sur craie. */
                sang: '#A8342A',

                /* Marge saine. 5,9:1 sur craie. */
                pousse: '#3F6B52',
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
