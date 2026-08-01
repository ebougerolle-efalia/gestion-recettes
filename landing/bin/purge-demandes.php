<?php
/**
 * Purge des demandes d'accès de plus de douze mois.
 *
 * Les mentions légales annoncent cette durée : sans exécution automatique,
 * l'engagement ne serait pas tenu. Appelé chaque jour par une tâche planifiée
 * installée par setup-vitrine.sh.
 *
 * Usage : php bin/purge-demandes.php <chemin-du-journal> [--mois=12]
 */

declare(strict_types=1);

// En ligne de commande uniquement. Le fichier vit dans l'arborescence de la
// vitrine : sans ce garde-fou, une requête HTTP suffirait à le déclencher.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$journal = $argv[1] ?? '';
$mois    = 12;

foreach (array_slice($argv, 2) as $option) {
    if (preg_match('/^--mois=(\d+)$/', $option, $m)) {
        $mois = max(1, (int) $m[1]);
    }
}

if ($journal === '') {
    fwrite(STDERR, "Usage : php bin/purge-demandes.php <chemin-du-journal> [--mois=12]\n");
    exit(1);
}

if (!is_file($journal)) {
    // Aucune demande reçue : rien à purger, et ce n'est pas une erreur.
    exit(0);
}

$lignes = file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if ($lignes === false) {
    fwrite(STDERR, "Lecture impossible : $journal\n");
    exit(1);
}

$limite  = strtotime("-$mois months");
$gardees = [];
$purgees = 0;

foreach ($lignes as $ligne) {
    $demande = json_decode($ligne, true);

    // Une ligne illisible est conservée : mieux vaut un résidu à examiner
    // qu'une donnée supprimée sur un malentendu.
    if (!is_array($demande) || empty($demande['recu_le'])) {
        $gardees[] = $ligne;
        continue;
    }

    if (strtotime((string) $demande['recu_le']) >= $limite) {
        $gardees[] = $ligne;
    } else {
        $purgees++;
    }
}

if ($purgees === 0) {
    exit(0);
}

// Écriture dans un fichier temporaire puis remplacement atomique : une coupure
// en pleine réécriture ne doit pas laisser un journal tronqué, ni faire perdre
// des demandes encore dans leur durée de conservation.
$temporaire = $journal . '.tmp';
$contenu    = $gardees ? implode("\n", $gardees) . "\n" : '';

if (file_put_contents($temporaire, $contenu, LOCK_EX) === false || !rename($temporaire, $journal)) {
    @unlink($temporaire);
    fwrite(STDERR, "Écriture impossible : $journal\n");
    exit(1);
}

@chmod($journal, 0660);

printf("%d demande(s) de plus de %d mois purgée(s), %d conservée(s).\n", $purgees, $mois, count($gardees));
