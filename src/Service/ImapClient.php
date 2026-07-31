<?php
namespace App\Service;

/**
 * Client IMAP minimal, sur socket.
 *
 * L'extension ext-imap est sortie du cœur de PHP en 8.4 : s'y appuyer aurait
 * fait dépendre chaque déploiement d'un paquet PECL. Les bibliothèques
 * disponibles, elles, tirent tout un framework applicatif pour six commandes de
 * protocole. On les écrit donc ici.
 *
 * Couvre strictement ce dont la relève de factures a besoin : se connecter,
 * lister les messages non lus, en récupérer la source brute, les marquer lus et
 * éventuellement les ranger. Le décodage MIME n'est pas de son ressort.
 */
class ImapClient
{
    /** Secondes avant qu'une lecture sans réponse soit considérée perdue. */
    private const TIMEOUT = 30;

    /** @var resource|null */
    private $stream = null;

    private int $tagCounter = 0;

    public function __construct(
        private string $host,
        private int $port = 993,
        private string $username = '',
        private string $password = '',
        /** ssl | tls | none — « tls » signifie STARTTLS sur le port en clair. */
        private string $encryption = 'ssl',
        private bool $validateCert = true,
    ) {}

    public function connect(): void
    {
        $transport = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';

        $context = stream_context_create(['ssl' => [
            'verify_peer'      => $this->validateCert,
            'verify_peer_name' => $this->validateCert,
            // Un certificat auto-signé reste refusé par défaut : c'est au
            // déploiement de dire explicitement qu'il l'accepte.
            'allow_self_signed' => !$this->validateCert,
        ]]);

        $stream = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$stream) {
            throw new \RuntimeException(sprintf(
                'Connexion IMAP impossible vers %s:%d — %s',
                $this->host,
                $this->port,
                $errstr ?: 'erreur inconnue'
            ));
        }

        $this->stream = $stream;
        stream_set_timeout($this->stream, self::TIMEOUT);

        // Bannière de bienvenue.
        $this->readLine();

        if ($this->encryption === 'tls') {
            $this->command('STARTTLS');
            if (!stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('Passage en TLS refusé par le serveur.');
            }
        }

        // Le mot de passe est encadré par des guillemets : les caractères
        // spéciaux y sont fréquents et casseraient la commande sans échappement.
        $this->command(sprintf(
            'LOGIN "%s" "%s"',
            $this->escape($this->username),
            $this->escape($this->password)
        ));
    }

    /** @return int Nombre de messages dans le dossier. */
    public function select(string $folder = 'INBOX'): int
    {
        $lines = $this->command(sprintf('SELECT "%s"', $this->escape($folder)));

        foreach ($lines as $line) {
            if (preg_match('/^\* (\d+) EXISTS/', $line, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * Identifiants des messages non lus.
     *
     * On raisonne en UID et non en numéro de séquence : un UID reste stable
     * quand un autre message est supprimé entre deux relèves.
     *
     * @return int[]
     */
    public function searchUnseen(): array
    {
        return $this->searchUids('UNSEEN');
    }

    /** @return int[] */
    public function searchAll(): array
    {
        return $this->searchUids('ALL');
    }

    /** @return int[] */
    private function searchUids(string $criteria): array
    {
        $lines = $this->command('UID SEARCH ' . $criteria);
        $uids  = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($line, 8))) ?: [] as $uid) {
                    if ($uid !== '' && ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }

        sort($uids);

        return $uids;
    }

    /**
     * Source brute d'un message, en-têtes compris.
     *
     * BODY.PEEK et non BODY : lire un message ne doit pas le marquer lu. Le
     * drapeau n'est posé qu'une fois la facture réellement enregistrée, sans
     * quoi une panne en cours de traitement ferait disparaître le message de la
     * relève suivante.
     */
    public function fetchRaw(int $uid): ?string
    {
        $tag = $this->nextTag();
        $this->write(sprintf('%s UID FETCH %d BODY.PEEK[]', $tag, $uid));

        $body = null;

        while (true) {
            $line = $this->readLine();

            if ($line === null) {
                throw new \RuntimeException('Connexion IMAP interrompue pendant la lecture du message.');
            }

            // Réponse à littéral : « ... {12345} » puis exactement 12345 octets.
            if (preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
                $body = $this->readBytes((int) $m[1]);
                continue;
            }

            if (str_starts_with($line, $tag . ' ')) {
                $this->assertOk($line, 'UID FETCH');
                break;
            }
        }

        return $body;
    }

    public function markSeen(int $uid): void
    {
        $this->command(sprintf('UID STORE %d +FLAGS (\\Seen)', $uid));
    }

    /**
     * Range le message dans un autre dossier.
     *
     * Optionnel et jamais bloquant : tous les serveurs n'implémentent pas MOVE,
     * et un message qui reste dans la boîte de réception après avoir été traité
     * n'est pas une erreur — il est marqué lu, donc ignoré à la relève suivante.
     */
    public function move(int $uid, string $folder): bool
    {
        try {
            $this->command(sprintf('UID MOVE %d "%s"', $uid, $this->escape($folder)));
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function createFolder(string $folder): bool
    {
        try {
            $this->command(sprintf('CREATE "%s"', $this->escape($folder)));
            return true;
        } catch (\RuntimeException) {
            // Existe déjà, le plus souvent.
            return false;
        }
    }

    public function close(): void
    {
        if (!$this->stream) {
            return;
        }

        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
            // Fermeture au mieux : une déconnexion brutale ne mérite pas d'échec.
        }

        fclose($this->stream);
        $this->stream = null;
    }

    // ─── Protocole ───────────────────────────────────────────────────────────

    /**
     * Envoie une commande et renvoie les lignes non taguées de la réponse.
     *
     * @return string[]
     */
    private function command(string $command): array
    {
        $tag = $this->nextTag();
        $this->write($tag . ' ' . $command);

        $lines = [];

        while (true) {
            $line = $this->readLine();

            if ($line === null) {
                throw new \RuntimeException(sprintf('Connexion IMAP interrompue après « %s ».', $this->redact($command)));
            }

            if (str_starts_with($line, $tag . ' ')) {
                $this->assertOk($line, $command);
                break;
            }

            // Un littéral au milieu d'une réponse doit être consommé, sinon les
            // octets suivants seraient lus comme des lignes de protocole.
            if (preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
                $this->readBytes((int) $m[1]);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function assertOk(string $taggedLine, string $command): void
    {
        // « A003 OK ... » / « A003 NO ... » / « A003 BAD ... »
        $parts  = explode(' ', $taggedLine, 3);
        $status = $parts[1] ?? '';

        if ($status !== 'OK') {
            throw new \RuntimeException(sprintf(
                'Le serveur IMAP a refusé « %s » : %s',
                $this->redact($command),
                trim($parts[2] ?? $status)
            ));
        }
    }

    private function write(string $line): void
    {
        if (!$this->stream) {
            throw new \RuntimeException('Client IMAP non connecté.');
        }

        if (@fwrite($this->stream, $line . "\r\n") === false) {
            throw new \RuntimeException('Écriture impossible sur la connexion IMAP.');
        }
    }

    private function readLine(): ?string
    {
        if (!$this->stream) {
            throw new \RuntimeException('Client IMAP non connecté.');
        }

        $line = fgets($this->stream);

        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);
            if ($meta['timed_out'] ?? false) {
                throw new \RuntimeException(sprintf('Le serveur IMAP n\'a pas répondu en %d s.', self::TIMEOUT));
            }
            return null;
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        if (!$this->stream) {
            throw new \RuntimeException('Client IMAP non connecté.');
        }

        $data = '';

        // fread s'arrête à la taille du tampon réseau : il faut boucler jusqu'au
        // compte annoncé, faute de quoi le reste du message serait interprété
        // comme des lignes de protocole.
        while (strlen($data) < $length) {
            $chunk = fread($this->stream, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException(sprintf(
                    'Message tronqué : %d octets reçus sur %d annoncés.',
                    strlen($data),
                    $length
                ));
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function nextTag(): string
    {
        return sprintf('A%03d', ++$this->tagCounter);
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /** Ne jamais laisser un mot de passe filtrer dans un message d'erreur. */
    private function redact(string $command): string
    {
        return str_starts_with($command, 'LOGIN') ? 'LOGIN' : $command;
    }
}
