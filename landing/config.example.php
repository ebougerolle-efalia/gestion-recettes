<?php
/**
 * Configuration de la vitrine.
 *
 * À recopier en « config.php », qui n'est pas versionné : il contient une
 * adresse de contact et un sel. Sans ce fichier, le formulaire enregistre
 * quand même les demandes — il n'envoie simplement aucune notification.
 */

return [
    // Où arrivent les demandes d'accès.
    'destinataire' => 'contact@exemple.fr',

    // Expéditeur de la notification. Doit appartenir au domaine du serveur,
    // sinon les messageries la classeront en indésirable — voire la refuseront.
    'expediteur' => 'no-reply@exemple.fr',

    // Fichier des demandes. Hors de la racine web par défaut : servi en clair,
    // il exposerait des données personnelles.
    'journal' => \dirname(__DIR__) . '/var/souscriptions.jsonl',

    // Demandes acceptées par heure et par origine.
    'max_par_heure' => 5,

    // Sel de l'empreinte d'origine. À remplacer par une valeur tirée au hasard :
    //     php -r "echo bin2hex(random_bytes(16));"
    // Le changer invalide les compteurs en cours, sans autre conséquence.
    'sel' => 'a-remplacer',
];
