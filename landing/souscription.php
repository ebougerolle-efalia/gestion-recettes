<?php
/**
 * Réception d'une demande d'accès.
 *
 * Seul morceau exécutable de la vitrine, et seul point exposé à l'extérieur :
 * il est donc écrit en defensive. Aucune donnée reçue n'est réaffichée, rien
 * n'est écrit dans la racine web, et le fichier de demandes reste hors de
 * portée du serveur HTTP.
 *
 * Fonctionne sans JavaScript : envoi classique, puis redirection vers la page
 * avec un état en paramètre (motif « POST-redirect-GET »), ce qui évite le
 * renvoi du formulaire à chaque rafraîchissement.
 */

declare(strict_types=1);

// ─── Configuration ───────────────────────────────────────────────────────────

$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : [];

$destinataire = $config['destinataire'] ?? '';
$expediteur   = $config['expediteur']   ?? '';
// Hors de la racine web : un fichier de demandes servi en clair par nginx
// serait une fuite de données personnelles.
$journal      = $config['journal']      ?? \dirname(__DIR__) . '/var/souscriptions.jsonl';
$maxParHeure  = (int) ($config['max_par_heure'] ?? 5);

// ─── Garde-fous d'entrée ─────────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    repartir('erreur');
}

/** Piège à robot : un humain ne peut pas remplir ce champ, il est hors écran. */
if (trim((string) ($_POST['site_web'] ?? '')) !== '') {
    // Écarté en silence : signaler le rejet apprendrait au robot à contourner.
    repartir('ok');
}

// Empreinte de l'origine : jamais l'adresse elle-même. Elle suffit à compter
// les envois d'un même visiteur sans conserver de donnée identifiante en clair.
$empreinte = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', (string) ($config['sel'] ?? 'vitrine'));

if (!limiteRespectee($journal, $maxParHeure, $empreinte)) {
    repartir('trop');
}

// ─── Validation ──────────────────────────────────────────────────────────────

$nom      = propre($_POST['nom']           ?? '', 100);
$courriel = propre($_POST['courriel']      ?? '', 180);
$etab     = propre($_POST['etablissement'] ?? '', 150);
$tel      = propre($_POST['telephone']     ?? '', 30);
$message  = propre($_POST['message']       ?? '', 2000);

$metiers = ['charcutier-traiteur', 'boulanger', 'patissier', 'boucher', 'autre'];
$offres  = ['starter', 'pro', 'indecis'];

$metier = (string) ($_POST['metier'] ?? '');
$offre  = (string) ($_POST['offre']  ?? '');

$valide = $nom !== ''
    && filter_var($courriel, FILTER_VALIDATE_EMAIL) !== false
    && in_array($metier, $metiers, true)
    && in_array($offre, $offres, true);

if (!$valide) {
    repartir('invalide');
}

// ─── Enregistrement ──────────────────────────────────────────────────────────

$demande = [
    'recu_le'       => date('c'),
    'nom'           => $nom,
    'courriel'      => $courriel,
    'etablissement' => $etab,
    'telephone'     => $tel,
    'metier'        => $metier,
    'offre'         => $offre,
    'message'       => $message,
    // Empreinte, pas adresse : sert uniquement à limiter le débit, et disparaît
    // avec la demande lors de la purge.
    'ip'            => $empreinte,
];

if (!enregistrer($journal, $demande)) {
    // Le disque a refusé : mieux vaut le dire que de laisser croire à un envoi.
    error_log('Vitrine : impossible d\'écrire la demande dans ' . $journal);
    repartir('erreur');
}

notifier($destinataire, $expediteur, $demande);

repartir('ok');

// ─── Fonctions ───────────────────────────────────────────────────────────────

/**
 * Nettoie une valeur reçue : type forcé, sauts de ligne normalisés, longueur
 * bornée, caractères de contrôle retirés.
 */
function propre(mixed $valeur, int $longueur): string
{
    $texte = is_string($valeur) ? $valeur : '';
    $texte = str_replace(["\r\n", "\r"], "\n", $texte);
    // Tout caractère de contrôle sauf le saut de ligne. En-têtes de courriel
    // compris : c'est ce qui empêche une injection dans le message envoyé.
    $texte = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $texte) ?? '';

    return mb_substr(trim($texte), 0, $longueur);
}

/**
 * Une ligne JSON par demande.
 *
 * Format choisi pour rester lisible et réparable à la main : un fichier
 * tronqué ne perd que sa dernière ligne, là où un JSON global serait
 * intégralement illisible.
 */
function enregistrer(string $chemin, array $demande): bool
{
    $dossier = \dirname($chemin);

    if (!is_dir($dossier) && !@mkdir($dossier, 0770, true) && !is_dir($dossier)) {
        return false;
    }

    $ligne = json_encode($demande, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($ligne === false) {
        return false;
    }

    // LOCK_EX : deux demandes simultanées ne doivent pas s'entrelacer.
    $ecrit = @file_put_contents($chemin, $ligne . "\n", FILE_APPEND | LOCK_EX);

    if ($ecrit === false) {
        return false;
    }

    @chmod($chemin, 0660);

    return true;
}

/**
 * Limite le nombre de demandes par heure et par origine.
 *
 * Par origine et non globalement : un compteur commun se laisserait épuiser par
 * un seul robot, qui fermerait alors le formulaire à tous les autres — la
 * protection deviendrait l'attaque. La comparaison porte sur l'empreinte, pas
 * sur l'adresse : rien d'identifiant n'a besoin d'être conservé en clair.
 */
function limiteRespectee(string $chemin, int $max, string $empreinte): bool
{
    if ($max <= 0 || !is_file($chemin)) {
        return true;
    }

    $contenu = @file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($contenu === false) {
        return true;
    }

    $limite = time() - 3600;
    $recent = 0;

    // Les demandes récentes sont en fin de fichier : on remonte, et on s'arrête
    // dès qu'on sort de l'heure écoulée.
    for ($i = count($contenu) - 1; $i >= 0; $i--) {
        $ligne = json_decode($contenu[$i], true);

        if (!is_array($ligne) || empty($ligne['recu_le'])) {
            continue;
        }

        if (strtotime((string) $ligne['recu_le']) < $limite) {
            break;
        }

        if (($ligne['ip'] ?? '') === $empreinte && ++$recent >= $max) {
            return false;
        }
    }

    return true;
}

/** Prévient par courriel, sans jamais faire échouer la demande déjà enregistrée. */
function notifier(string $destinataire, string $expediteur, array $demande): void
{
    if ($destinataire === '' || !filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $corps = sprintf(
        "Nouvelle demande d'accès.\n\n"
        . "Nom           : %s\n"
        . "Établissement : %s\n"
        . "Courriel      : %s\n"
        . "Téléphone     : %s\n"
        . "Métier        : %s\n"
        . "Offre         : %s\n\n"
        . "Message :\n%s\n",
        $demande['nom'],
        $demande['etablissement'] !== '' ? $demande['etablissement'] : '—',
        $demande['courriel'],
        $demande['telephone'] !== '' ? $demande['telephone'] : '—',
        $demande['metier'],
        $demande['offre'],
        $demande['message'] !== '' ? $demande['message'] : '—'
    );

    $entetes = ['Content-Type: text/plain; charset=UTF-8'];

    if ($expediteur !== '' && filter_var($expediteur, FILTER_VALIDATE_EMAIL)) {
        $entetes[] = 'From: ' . $expediteur;
    }

    // Répondre au message doit écrire au demandeur : l'adresse a été validée
    // plus haut, elle ne peut donc pas injecter d'en-tête.
    $entetes[] = 'Reply-To: ' . $demande['courriel'];

    @mail($destinataire, 'Demande d\'accès — ' . $demande['nom'], $corps, implode("\r\n", $entetes));
}

/** Renvoie vers la page avec un état, sans jamais réafficher ce qui a été saisi. */
function repartir(string $etat): never
{
    header('Location: index.php?etat=' . rawurlencode($etat) . '#acces', true, 303);
    exit;
}
