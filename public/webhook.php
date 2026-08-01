<?php
/**
 * Webhook GitHub — Déploiement automatique sur push
 *
 * GitHub → Settings → Webhooks → Add webhook :
 *   Payload URL : https://gestion-recettes.bougerolle.ovh/webhook.php
 *   Content type : application/json
 *   Secret : celui de WEBHOOK_SECRET dans .env.local
 *
 * Sudoers :
 *   sudo visudo -f /etc/sudoers.d/gestion-recettes
 *   www-data ALL=(ALL) NOPASSWD: /var/www/gestion-recettes/deploy.sh
 */

$appDir = dirname(__DIR__);
$logFile = $appDir . '/var/log/webhook.log';
$branch = 'master';

// Lecture de .env.local : le secret du webhook, et l'origine git attendue.
// REPO_URL est transmis à deploy.sh, qui répare tout seul un remote resté en
// HTTPS anonyme — le cas d'une instance clonée avant le passage du dépôt en
// privé, que ce fichier ne peut pas corriger rétroactivement lui-même.
$secret  = null;
$repoUrl = null;
$envFile = $appDir . '/.env.local';
if (file_exists($envFile)) {
    foreach (file($envFile) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'WEBHOOK_SECRET=')) {
            $secret = trim(substr($line, strlen('WEBHOOK_SECRET=')), '"\'');
        }
        if (str_starts_with($line, 'REPO_URL=')) {
            $repoUrl = trim(substr($line, strlen('REPO_URL=')), '"\'');
        }
    }
}

function wlog(string $msg, string $f): void { @file_put_contents($f, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND); }
function respond(int $code, string $msg, string $f): never { wlog("HTTP $code — $msg", $f); http_response_code($code); header('Content-Type: application/json'); echo json_encode(['status' => $code, 'message' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, 'Method Not Allowed', $logFile);

$payload = file_get_contents('php://input');
if (!$payload) respond(400, 'Empty payload', $logFile);

if ($secret) {
    $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $sig)) respond(403, 'Invalid signature', $logFile);
}

$data = json_decode($payload, true);
if (!$data) respond(400, 'Invalid JSON', $logFile);

$ref = $data['ref'] ?? '';
if ($ref !== "refs/heads/$branch") respond(200, "Ignored: push to $ref", $logFile);

wlog("Push sur $branch par " . ($data['pusher']['name'] ?? '?') . " — " . ($data['head_commit']['message'] ?? ''), $logFile);

// REPO_URL='...' bash deploy.sh — affectation de variable en tête de
// commande, syntaxe shell standard, valable même si $repoUrl est vide (la
// variable existe alors vide côté deploy.sh, qui ignore le correctif).
exec(sprintf(
    'cd %s && REPO_URL=%s bash deploy.sh >> %s 2>&1 &',
    escapeshellarg($appDir),
    escapeshellarg((string) $repoUrl),
    escapeshellarg($logFile)
));

respond(200, 'Deployment triggered', $logFile);
