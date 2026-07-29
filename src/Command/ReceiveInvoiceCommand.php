<?php
namespace App\Command;

use App\Entity\PurchaseInvoice;
use App\Service\InvoiceInbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dépose une facture dans la file d'attente depuis un fichier local.
 *
 * Sert au développement et aux reprises manuelles, mais surtout : c'est le même
 * point d'entrée que celui qu'utilisera la relève d'une boîte de réception ou
 * d'une plateforme agréée. Si cette commande fonctionne sans utilisateur, le
 * canal automatique fonctionnera aussi.
 */
#[AsCommand(name: 'app:invoice-receive', description: 'Reçoit une facture Factur-X (PDF ou XML) dans la file d\'attente')]
class ReceiveInvoiceCommand extends Command
{
    public function __construct(private InvoiceInbox $inbox)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Chemin d\'un PDF Factur-X, d\'un XML CII, ou d\'un dossier en contenant')
            ->addOption('source', null, InputOption::VALUE_OPTIONAL, 'manual | email | platform', PurchaseInvoice::SOURCE_MANUAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('file');

        if (!file_exists($path)) {
            $alt = \dirname(__DIR__, 2) . '/' . ltrim($path, '/');
            if (!file_exists($alt)) {
                $io->error("Chemin introuvable : $path");
                return Command::FAILURE;
            }
            $path = $alt;
        }

        $source = (string) $input->getOption('source');
        if (!in_array($source, [PurchaseInvoice::SOURCE_MANUAL, PurchaseInvoice::SOURCE_EMAIL, PurchaseInvoice::SOURCE_PLATFORM], true)) {
            $io->error("Source inconnue « $source » (attendu : manual, email, platform).");
            return Command::FAILURE;
        }

        // Un dossier est traité fichier par fichier : c'est déjà la forme que
        // prendra une relève de boîte de réception.
        if (is_dir($path)) {
            $files = array_merge(
                glob(rtrim($path, '/\\') . '/*.xml') ?: [],
                glob(rtrim($path, '/\\') . '/*.pdf') ?: []
            );
            sort($files);

            if (!$files) {
                $io->warning("Aucun fichier .xml ou .pdf dans $path");
                return Command::SUCCESS;
            }

            $failed = 0;
            foreach ($files as $file) {
                $io->section(basename($file));
                if ($this->receiveOne($file, $source, $io) !== Command::SUCCESS) {
                    $failed++;
                }
            }

            $io->writeln(sprintf('%d fichier(s) traité(s), %d en échec.', count($files), $failed));

            return $failed === count($files) ? Command::FAILURE : Command::SUCCESS;
        }

        return $this->receiveOne($path, $source, $io);
    }

    private function receiveOne(string $path, string $source, SymfonyStyle $io): int
    {
        $mime = str_ends_with(strtolower($path), '.pdf') ? 'application/pdf' : 'application/xml';
        $result = $this->inbox->receive((string) file_get_contents($path), $mime, $source);

        if ($result['error']) {
            $io->error($result['error']);
            return Command::FAILURE;
        }

        $invoice = $result['invoice'];

        if ($result['duplicate']) {
            $io->warning(sprintf(
                'Facture %s de %s déjà reçue le %s (statut : %s). Rien créé.',
                $invoice->getInvoiceId(),
                $invoice->getSupplier()->getName(),
                $invoice->getImportedAt()->format('d/m/Y H:i'),
                $invoice->getStatus()
            ));
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Facture %s de %s reçue : %d ligne(s), en attente de validation (#%d).',
            $invoice->getInvoiceId(),
            $invoice->getSupplier()->getName(),
            count($invoice->getLines()),
            $invoice->getId()
        ));

        $rows = [];
        foreach ($invoice->getLines() as $line) {
            $rows[] = [
                $line->getDescription(),
                number_format($line->getQty(), 3, ',', ' ') . ' ' . $line->getUnitCode(),
                number_format($line->getPriceHt(), 4, ',', ' ') . ' €',
                $line->getIngredient()?->getName() ?? '—',
                match ($line->getMatchSource()) {
                    'mapping' => 'mémorisé',
                    'fuzzy'   => $line->getMatchScore() . ' %',
                    default   => 'non reconnu',
                },
            ];
        }
        $io->table(['Libellé facture', 'Quantité', 'Prix HT', 'Ingrédient proposé', 'Rapprochement'], $rows);

        return Command::SUCCESS;
    }
}
