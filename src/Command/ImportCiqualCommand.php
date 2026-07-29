<?php
namespace App\Command;

use App\Entity\CiqualFood;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputArgument};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-ciqual',
    description: 'Importe la table Ciqual (CSV) dans ciqual_foods. Source : Anses, table Ciqual.'
)]
class ImportCiqualCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'Chemin du CSV Ciqual', 'data/ciqual_2025.csv');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $path = $input->getArgument('file');

        if (!is_file($path)) {
            // Chemin relatif au projet si besoin
            $alt = \dirname(__DIR__, 2) . '/' . ltrim($path, '/');
            if (is_file($alt)) {
                $path = $alt;
            } else {
                $io->error("Fichier introuvable : $path");
                return Command::FAILURE;
            }
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            $io->error("Impossible d'ouvrir : $path");
            return Command::FAILURE;
        }

        $header = fgetcsv($fh);
        if ($header === false) {
            $io->error('CSV vide.');
            fclose($fh);
            return Command::FAILURE;
        }
        $idx = array_flip($header); // nom de colonne => position

        $required = ['code','nom','energie_kj','energie_kcal','proteines','glucides','lipides','sucres','ag_satures','sel'];
        foreach ($required as $col) {
            if (!isset($idx[$col])) {
                $io->error("Colonne manquante dans le CSV : $col");
                fclose($fh);
                return Command::FAILURE;
            }
        }

        $repo  = $this->em->getRepository(CiqualFood::class);
        $num   = fn($v) => ($v === null || trim((string)$v) === '') ? null : (float) $v;
        $count = 0;

        $io->writeln('Import en cours…');
        while (($row = fgetcsv($fh)) !== false) {
            $code = trim((string) ($row[$idx['code']] ?? ''));
            if ($code === '') {
                continue;
            }

            $food = $repo->find($code) ?? (new CiqualFood())->setCode($code);
            $food->setNom((string) ($row[$idx['nom']] ?? ''));
            $food->setGroupe(isset($idx['groupe']) ? (($row[$idx['groupe']] ?? '') ?: null) : null);
            $food->setEnergieKj($num($row[$idx['energie_kj']] ?? null));
            $food->setEnergieKcal($num($row[$idx['energie_kcal']] ?? null));
            $food->setProteines($num($row[$idx['proteines']] ?? null));
            $food->setGlucides($num($row[$idx['glucides']] ?? null));
            $food->setLipides($num($row[$idx['lipides']] ?? null));
            $food->setSucres($num($row[$idx['sucres']] ?? null));
            $food->setAgSatures($num($row[$idx['ag_satures']] ?? null));
            $food->setSel($num($row[$idx['sel']] ?? null));

            $this->em->persist($food);
            if (++$count % 500 === 0) {
                $this->em->flush();
                $this->em->clear();
                $repo = $this->em->getRepository(CiqualFood::class);
            }
        }
        $this->em->flush();
        fclose($fh);

        // setNom() calcule nom_norm : rien à faire de plus ici.
        $io->success("$count aliments Ciqual importés. (Source : Anses, table Ciqual)");
        return Command::SUCCESS;
    }
}
