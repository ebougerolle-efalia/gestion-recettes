<?php
namespace App\Command;

use App\Service\CiqualMatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rattrapage de la colonne `nom_norm` sur une table Ciqual déjà importée.
 * Un réimport complet la remplit aussi, mais coûte plus cher.
 */
#[AsCommand(name: 'app:ciqual-normalize', description: 'Calcule les noms normalisés Ciqual (nom_norm) manquants')]
class CiqualNormalizeCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Recalcule tout, y compris les noms déjà normalisés');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $conn = $this->em->getConnection();

        $rows = $conn->fetchAllAssociative(
            $input->getOption('all')
                ? 'SELECT code, nom FROM ciqual_foods'
                : 'SELECT code, nom FROM ciqual_foods WHERE nom_norm IS NULL'
        );

        if (!$rows) {
            $io->success('Rien à normaliser.');
            return Command::SUCCESS;
        }

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare('UPDATE ciqual_foods SET nom_norm = ? WHERE code = ?');
            foreach ($rows as $row) {
                $stmt->executeStatement([CiqualMatcher::normalize((string) $row['nom']), $row['code']]);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $io->error('Échec : ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(count($rows) . ' aliment(s) normalisé(s).');
        return Command::SUCCESS;
    }
}
