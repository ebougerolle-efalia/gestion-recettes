/*
  Vitrine — le seul script de la page.

  Il ne sert qu'à l'élément signature : la balance qui penche quand la facture
  arrive. Le formulaire, lui, fonctionne sans JavaScript — envoi classique puis
  redirection. Rien d'essentiel ne dépend de ce fichier.
*/
(function () {
    'use strict';

    var balance = document.getElementById('balance');
    var marque  = document.getElementById('marque');
    var delta   = document.getElementById('delta');

    if (!balance || !marque || !delta) {
        return;
    }

    var DEPART  = 42;
    var ARRIVEE = 36;

    var mouvementReduit = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function afficherEtatFinal() {
        balance.classList.add('penchee');
        marque.textContent = String(ARRIVEE);
        delta.hidden = false;
    }

    // Sans animation, la démonstration disparaîtrait : on montre directement le
    // résultat plutôt que de laisser une balance à l'équilibre qui ne dit rien.
    if (mouvementReduit) {
        afficherEtatFinal();
        return;
    }

    function compter() {
        var valeur = DEPART;

        var minuteur = setInterval(function () {
            valeur -= 1;
            marque.textContent = String(valeur);

            if (valeur <= ARRIVEE) {
                clearInterval(minuteur);
                delta.hidden = false;
            }
        }, 130);
    }

    function jouer() {
        balance.classList.add('penchee');
        // La marque suit le plateau, elle ne le précède pas.
        setTimeout(compter, 550);
    }

    // Jouée une seule fois, à l'entrée dans le champ de vision. Rejouer à
    // chaque passage transformerait une démonstration en clignotement.
    if (!('IntersectionObserver' in window)) {
        jouer();
        return;
    }

    var joue = false;

    function jouerUneFois() {
        if (joue) {
            return;
        }
        joue = true;
        jouer();
    }

    var observateur = new IntersectionObserver(function (entrees) {
        entrees.forEach(function (entree) {
            if (entree.isIntersecting) {
                observateur.disconnect();
                jouerUneFois();
            }
        });
    }, { threshold: 0.45 });

    observateur.observe(balance);

    // Filet de sécurité : la balance est au-dessus de la ligne de flottaison,
    // l'observateur devrait se déclencher aussitôt. S'il ne le fait pas — onglet
    // ouvert en arrière-plan, page qui ne compose pas —, la démonstration doit
    // quand même avoir eu lieu quand le visiteur regarde.
    setTimeout(jouerUneFois, 1200);
})();
