<?php
namespace App\Command;

use App\Entity\PurchaseInvoice;
use App\Service\InvoiceInbox;
use App\Service\MailboxReader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Relève la boîte de réception des factures.
 *
 * Destinée à une tâche planifiée, sans personne devant l'écran : elle ne pose
 * jamais de question, ne bloque jamais, et ne renvoie un échec que lorsque la
 * boîte elle-même est inaccessible. Une facture illisible n'est pas un échec de
 * la relève — elle est reçue et mise en attente de saisie.
 *
 * Silencieuse quand tout va bien : un cron qui écrit à chaque passage finit
 * dans un fichier que personne ne lit. Ajouter -v pour le détail.
 */
#[AsCommand(name: 'app:invoice-fetch', description: 'Relève la boîte de réception et enregistre les factures reçues')]
class FetchInvoiceMailCommand extends Command
{
    public function __construct(
        private MailboxReader $mailbox,
        private InvoiceInbox $inbox,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de messages traités en une passe', '50')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste ce qui serait reçu sans rien enregistrer ni marquer lu');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $limit   = max(1, (int) $input->getOption('limit'));
        $verbose = $output->isVerbose();

        if (!$this->mailbox->isConfigured()) {
            // Ni erreur ni silence : sur un déploiement où le canal n'est pas
            // ouvert, la commande doit pouvoir tourner sans alerter.
            $io->writeln('Canal courriel inactif : INVOICE_MAILBOX_DSN n\'est pas renseigné.');
            return Command::SUCCESS;
        }

        if ($verbose) {
            $io->writeln(sprintf('Boîte : %s', $this->mailbox->describe()));
        }

        $received = [];

        try {
            $stats = $this->mailbox->fetch(
                function (array $attachment, array $meta) use (&$received, $verbose, $io): bool {
                    $result = $this->inbox->receive(
                        $attachment['content'],
                        $attachment['mime'],
                        PurchaseInvoice::SOURCE_EMAIL,
                        [
                            'from'     => $meta['from'],
                            'subject'  => $meta['subject'],
                            'date'     => $meta['date'],
                            'filename' => $attachment['name'],
                        ]
                    );

                    if ($result['error']) {
                        if ($verbose) {
                            $io->writeln(sprintf('  <error>%s</error> : %s', $attachment['name'], $result['error']));
                        }
                        return false;
                    }

                    $received[] = [
                        'file'      => $attachment['name'],
                        'from'      => $meta['from'],
                        'invoice'   => $result['invoice'],
                        'duplicate' => $result['duplicate'],
                        'captured'  => $result['captured'],
                    ];

                    // Le doublon compte comme traité : le message a bien été
                    // examiné, le laisser non lu le ferait relire indéfiniment.
                    return true;
                },
                $limit,
                $dryRun
            );
        } catch (\Throwable $e) {
            // Seul cas d'échec : la boîte est injoignable. Le code de retour
            // fait remonter l'incident à la supervision.
            $this->logger->error('Relève des factures impossible', ['exception' => $e]);
            $io->error('Relève impossible : ' . $e->getMessage());

            return Command::FAILURE;
        }

        return $this->report($io, $stats, $received, $dryRun, $verbose);
    }

    /**
     * @param array{messages:int, attachments:int, handled:int, errors:string[]} $stats
     * @param list<array{file:string, from:string, invoice:PurchaseInvoice, duplicate:bool, captured:bool}> $received
     */
    private function report(SymfonyStyle $io, array $stats, array $received, bool $dryRun, bool $verbose): int
    {
        if ($stats['messages'] === 0) {
            if ($verbose) {
                $io->writeln('Aucun message non lu.');
            }
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->writeln(sprintf(
                '%d message(s), %d pièce(s) jointe(s) exploitable(s). Rien enregistré (--dry-run).',
                $stats['messages'],
                $stats['attachments']
            ));
            return Command::SUCCESS;
        }

        $new       = array_filter($received, fn (array $r) => !$r['duplicate']);
        $captured  = array_filter($new, fn (array $r) => $r['captured']);
        $duplicate = count($received) - count($new);

        if ($new || $verbose) {
            $rows = [];
            foreach ($received as $r) {
                $rows[] = [
                    $r['file'],
                    $r['from'] ?: '—',
                    $r['duplicate'] ? 'déjà reçue'
                        : ($r['captured'] ? 'à saisir' : sprintf('%d ligne(s)', count($r['invoice']->getLines()))),
                    $r['invoice']->getSupplier()?->getName() ?? 'à rattacher',
                ];
            }
            $io->table(['Fichier', 'Expéditeur', 'État', 'Fournisseur'], $rows);
        }

        $io->writeln(sprintf(
            '%d facture(s) reçue(s), dont %d en attente de saisie · %d doublon(s) ignoré(s).',
            count($new),
            count($captured),
            $duplicate
        ));

        foreach ($stats['errors'] as $error) {
            $io->warning($error);
        }

        // Des messages illisibles n'invalident pas la relève : les autres sont
        // passés. Le journal en garde trace pour qui veut comprendre.
        return Command::SUCCESS;
    }
}
