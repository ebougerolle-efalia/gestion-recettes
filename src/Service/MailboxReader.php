<?php
namespace App\Service;

use Psr\Log\LoggerInterface;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;

/**
 * Relève d'une boîte de réception dédiée aux factures.
 *
 * C'est le canal qui fait entrer une facture sans qu'aucun geste ne soit fait :
 * le client donne l'adresse à ses fournisseurs, la relève tourne toutes les
 * quinze minutes, et la file d'attente se remplit seule.
 *
 * Ce service ne connaît ni les factures ni les ingrédients : il ouvre des
 * messages et en sort des pièces jointes. Ce qu'on en fait ensuite est le
 * travail de la boîte de réception métier.
 */
class MailboxReader
{
    /** Extensions retenues : tout le reste (images de signature, etc.) est ignoré. */
    private const ACCEPTED_EXTENSIONS = ['pdf', 'xml'];

    /** Au-delà, on refuse de charger la pièce jointe en mémoire. */
    private const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private string $dsn,
        private LoggerInterface $logger,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->dsn) !== '';
    }

    /**
     * Description lisible de la boîte configurée, sans le mot de passe.
     */
    public function describe(): string
    {
        if (!$this->isConfigured()) {
            return 'aucune boîte configurée';
        }

        $c = $this->config();

        return sprintf('%s@%s:%d (%s), dossier %s', $c['user'], $c['host'], $c['port'], $c['encryption'], $c['folder']);
    }

    /**
     * Parcourt les messages non lus et passe chaque pièce jointe exploitable au
     * gestionnaire fourni.
     *
     * Le gestionnaire renvoie true si la pièce a été prise en charge (y compris
     * quand elle s'avère être un doublon : le message a bien été traité). Un
     * message dont aucune pièce n'a été prise en charge est laissé non lu, pour
     * qu'une correction du code lui donne une seconde chance.
     *
     * @param callable(array, array): bool $handler  (pièce jointe, métadonnées du message)
     *
     * @return array{messages: int, attachments: int, handled: int, errors: string[]}
     */
    public function fetch(callable $handler, int $limit = 50, bool $dryRun = false): array
    {
        $stats = ['messages' => 0, 'attachments' => 0, 'handled' => 0, 'errors' => []];

        if (!$this->isConfigured()) {
            $stats['errors'][] = 'Aucune boîte de réception configurée (INVOICE_MAILBOX_DSN).';
            return $stats;
        }

        $c      = $this->config();
        $client = new ImapClient($c['host'], $c['port'], $c['user'], $c['pass'], $c['encryption'], $c['validate_cert']);

        $client->connect();

        try {
            $client->select($c['folder']);
            $uids = array_slice($client->searchUnseen(), 0, $limit);

            foreach ($uids as $uid) {
                $stats['messages']++;

                try {
                    $handled = $this->handleMessage($client, $uid, $c, $handler, $dryRun, $stats);
                } catch (\Throwable $e) {
                    // Un message illisible ne doit pas arrêter la relève : les
                    // suivants portent peut-être des factures.
                    $handled = false;
                    $stats['errors'][] = sprintf('Message %d : %s', $uid, $e->getMessage());
                    $this->logger->error('Relève de facture : message {uid} illisible', ['uid' => $uid, 'exception' => $e]);
                }

                if ($handled && !$dryRun) {
                    $client->markSeen($uid);

                    if ($c['archive'] !== '') {
                        $client->move($uid, $c['archive']);
                    }
                }
            }
        } finally {
            $client->close();
        }

        return $stats;
    }

    /**
     * @param callable(array, array): bool $handler
     * @param array{messages:int, attachments:int, handled:int, errors:string[]} $stats
     */
    private function handleMessage(
        ImapClient $client,
        int $uid,
        array $config,
        callable $handler,
        bool $dryRun,
        array &$stats,
    ): bool {
        $raw = $client->fetchRaw($uid);

        if ($raw === null || $raw === '') {
            throw new \RuntimeException('Message vide.');
        }

        $message = (new MailMimeParser())->parse($raw, false);
        $meta    = $this->metadata($message, $uid);
        $handled = false;

        foreach ($this->attachments($message) as $attachment) {
            $stats['attachments']++;

            if ($dryRun) {
                $handled = true;
                continue;
            }

            if ($handler($attachment, $meta)) {
                $stats['handled']++;
                $handled = true;
            }
        }

        return $handled;
    }

    /**
     * @return array{from: string, from_name: string, subject: string, date: ?\DateTimeImmutable, message_id: string, uid: int}
     */
    private function metadata(Message $message, int $uid): array
    {
        $from = $message->getHeader('From');
        $date = $message->getHeaderValue('Date');

        $sent = null;
        if ($date) {
            try {
                $sent = new \DateTimeImmutable($date);
            } catch (\Throwable) {
                $sent = null;
            }
        }

        return [
            'uid'        => $uid,
            'from'       => strtolower(trim((string) ($from?->getAddresses()[0]?->getEmail() ?? ''))),
            'from_name'  => trim((string) ($from?->getAddresses()[0]?->getName() ?? '')),
            'subject'    => trim((string) ($message->getHeaderValue('Subject') ?? '')),
            'message_id' => trim((string) ($message->getHeaderValue('Message-ID') ?? '')),
            'date'       => $sent,
        ];
    }

    /**
     * Pièces jointes exploitables d'un message.
     *
     * @return list<array{name: string, mime: string, content: string}>
     */
    private function attachments(Message $message): array
    {
        $found = [];

        foreach ($message->getAllAttachmentParts() as $part) {
            $name = (string) ($part->getFilename() ?? '');
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($ext, self::ACCEPTED_EXTENSIONS, true)) {
                continue;
            }

            $content = $part->getContent();

            if ($content === '' ) {
                continue;
            }

            if (strlen($content) > self::MAX_ATTACHMENT_BYTES) {
                $this->logger->warning('Pièce jointe ignorée, trop volumineuse', ['name' => $name, 'bytes' => strlen($content)]);
                continue;
            }

            $found[] = [
                'name'    => $name,
                'mime'    => $ext === 'pdf' ? 'application/pdf' : 'application/xml',
                'content' => $content,
            ];
        }

        return $found;
    }

    /**
     * @return array{host: string, port: int, user: string, pass: string, encryption: string, folder: string, archive: string, validate_cert: bool}
     */
    private function config(): array
    {
        $parts = parse_url(trim($this->dsn));

        if ($parts === false || empty($parts['host'])) {
            throw new \RuntimeException('INVOICE_MAILBOX_DSN illisible. Forme attendue : imap://utilisateur:motdepasse@serveur:993?encryption=ssl&folder=INBOX');
        }

        parse_str($parts['query'] ?? '', $query);

        $encryption = strtolower((string) ($query['encryption'] ?? 'ssl'));
        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            throw new \RuntimeException(sprintf('Chiffrement « %s » inconnu (attendu : ssl, tls ou none).', $encryption));
        }

        return [
            'host'       => $parts['host'],
            'port'       => (int) ($parts['port'] ?? ($encryption === 'ssl' ? 993 : 143)),
            'user'       => rawurldecode((string) ($parts['user'] ?? '')),
            'pass'       => rawurldecode((string) ($parts['pass'] ?? '')),
            'encryption' => $encryption,
            'folder'     => (string) ($query['folder'] ?? 'INBOX'),
            // Vide : on laisse le message dans la boîte, simplement marqué lu.
            'archive'    => (string) ($query['archive'] ?? ''),
            'validate_cert' => ($query['validate_cert'] ?? '1') !== '0',
        ];
    }
}
