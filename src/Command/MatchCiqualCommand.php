<?php
namespace App\Command;

use App\Repository\CiqualFoodRepository;
use App\Repository\IngredientRepository;
use App\Service\CiqualMatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rattache automatiquement les ingrédients à un aliment Ciqual.
 *
 * Les rattachements posés ici sont marqués « auto » : ils restent à confirmer
 * dans la fiche ingrédient avant d'être considérés comme fiables.
 */
#[AsCommand(name: 'app:match-ciqual', description: 'Propose ou applique un rattachement Ciqual sur les ingrédients')]
class MatchCiqualCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private IngredientRepository $ingredients,
        private CiqualFoodRepository $ciqual,
        private CiqualMatcher $matcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'N\'écrit rien, affiche seulement les correspondances')
            ->addOption('min-score', null, InputOption::VALUE_OPTIONAL, 'Score minimal pour rattacher (0 à 1)', (string) CiqualMatcher::MIN_AUTO)
            ->addOption('all', null, InputOption::VALUE_NONE, 'Traite aussi les ingrédients déjà rattachés');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $minScore = (float) str_replace(',', '.', (string) $input->getOption('min-score'));
        $all      = (bool) $input->getOption('all');

        if ($minScore <= 0 || $minScore > 1) {
            $io->error('--min-score doit être compris entre 0 et 1.');
            return Command::FAILURE;
        }

        if (!$this->ciqual->count([])) {
            $io->error('Table Ciqual vide. Lancez d\'abord « app:import-ciqual ».');
            return Command::FAILURE;
        }
        if ($missing = $this->ciqual->countMissingNorm()) {
            $io->warning("$missing aliment(s) sans nom normalisé. Lancez « app:ciqual-normalize » pour un appariement complet.");
        }

        $matched = [];
        $weak    = [];
        $none    = [];

        foreach ($this->ingredients->findBy([], ['name' => 'ASC']) as $ing) {
            if (!$all && $ing->getCiqualCode()) {
                continue;
            }

            $best = $this->matcher->suggestForIngredient($ing, 1)[0] ?? null;

            if (!$best) {
                $none[] = $ing->getName();
                continue;
            }

            $row = [$ing->getName(), $best['food']->getNom(), number_format($best['score'] * 100, 0) . ' %'];

            if ($best['score'] >= $minScore) {
                $matched[] = $row;
                if (!$dryRun) {
                    $ing->setCiqualCode($best['food']->getCode());
                    $ing->setCiqualAuto(true);
                }
            } else {
                $weak[] = $row;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        if ($matched) {
            $io->section($dryRun ? 'Rattachements proposés' : 'Rattachements appliqués (à confirmer)');
            $io->table(['Ingrédient', 'Aliment Ciqual', 'Score'], $matched);
        }
        if ($weak) {
            $io->section('Score insuffisant — à traiter à la main');
            $io->table(['Ingrédient', 'Meilleur candidat', 'Score'], $weak);
        }
        if ($none) {
            $io->section('Aucun candidat');
            $io->listing($none);
        }

        $io->success(sprintf(
            '%d rattaché(s)%s, %d sous le seuil, %d sans candidat.',
            count($matched),
            $dryRun ? ' (simulation)' : '',
            count($weak),
            count($none)
        ));

        if (!$dryRun && $matched) {
            $io->note('Ces rattachements sont marqués « à vérifier » : confirmez-les depuis la fiche ingrédient.');
        }

        return Command::SUCCESS;
    }
}
