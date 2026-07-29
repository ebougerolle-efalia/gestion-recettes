<?php
namespace App\Command;

use App\Entity\{Ingredient, IngredientCategory, IngredientPrice, Recipe, RecipeFamily, RecipeLine, Supplier};
use App\Repository\CiqualFoodRepository;
use App\Repository\ConfigBoutiqueRepository;
use App\Service\CiqualMatcher;
use App\Service\CostCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Jeu de données de démonstration pour trois métiers : charcutier-traiteur,
 * boulanger et pâtissier.
 *
 * Crée les catégories, familles, fournisseurs, ingrédients (avec historique de
 * prix et allergènes), puis les recettes et sous-recettes, et déclenche le
 * calcul des coûts / marges via CostCalculator.
 *
 * Idempotent : tout est retrouvé par nom, rien n'est dupliqué à la seconde
 * exécution. Les prix déjà présents à une date donnée ne sont pas réinsérés.
 */
#[AsCommand(name: 'app:demo-data', description: 'Génère des données de démo réalistes (charcutier, boulanger, pâtissier)')]
class DemoDataCommand extends Command
{
    /** @var array<string,IngredientCategory> */
    private array $cats = [];
    /** @var array<string,Supplier> */
    private array $suppliers = [];
    /** @var array<string,Ingredient> */
    private array $ings = [];
    /** @var array<string,Recipe> */
    private array $recipes = [];

    public function __construct(
        private EntityManagerInterface $em,
        private CostCalculator $calc,
        private CiqualFoodRepository $ciqualRepo,
        private CiqualMatcher $matcher,
        private ConfigBoutiqueRepository $configRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('metier', null, InputOption::VALUE_OPTIONAL, 'all | charcutier | boulanger | patissier', 'all')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Supprime recettes, ingrédients, prix et fournisseurs avant génération')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirme le purge en mode non interactif');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $profile = (string) $input->getOption('metier');

        if (!in_array($profile, ['all', 'charcutier', 'boulanger', 'patissier'], true)) {
            $io->error("Métier inconnu « $profile » (attendu : all, charcutier, boulanger, patissier).");
            return Command::FAILURE;
        }

        if ($input->getOption('purge')) {
            $confirmed = $input->getOption('force')
                || ($input->isInteractive() && $io->confirm('Supprimer TOUTES les recettes, ingrédients, prix et fournisseurs ?', false));

            if (!$confirmed) {
                $io->warning('Purge annulée (utilisez --force en mode non interactif). Arrêt.');
                return Command::FAILURE;
            }
            $this->purge($io);
        }

        $io->title('Données de démonstration — profil : ' . $profile);

        $this->loadCategories($io);
        $this->loadFamilies($io);
        $this->loadSuppliers($io);
        $this->loadIngredients($io);
        $this->em->flush();

        $this->loadRecipes($profile, $io);
        $this->em->flush();

        $count = $this->calc->recalculateAll();
        $io->success("Terminé : {$count} recette(s) recalculée(s).");

        $this->printMarginTable($io);

        return Command::SUCCESS;
    }

    // ── Purge ────────────────────────────────────────────────────────────────

    private function purge(SymfonyStyle $io): void
    {
        $conn = $this->em->getConnection();
        // Ordre imposé par les clés étrangères.
        foreach ([
            'recipe_lines',
            'recipe_cost_cache',
            'recipes',
            'ingredient_prices',
            'invoice_ingredient_mappings',
            'ingredients',
        ] as $table) {
            try { $conn->executeStatement('DELETE FROM ' . $table); }
            catch (\Throwable $e) { $io->warning("Purge $table : " . $e->getMessage()); }
        }
        // Les fournisseurs liés à des factures importées sont conservés.
        try {
            $conn->executeStatement(
                'DELETE FROM suppliers WHERE id NOT IN (SELECT DISTINCT supplier_id FROM purchase_invoices WHERE supplier_id IS NOT NULL)'
            );
        } catch (\Throwable $e) { $io->warning('Purge suppliers : ' . $e->getMessage()); }

        $this->em->clear();
        $io->writeln('  Base nettoyée.');
    }

    // ── Référentiels ─────────────────────────────────────────────────────────

    private function loadCategories(SymfonyStyle $io): void
    {
        $repo = $this->em->getRepository(IngredientCategory::class);
        $defs = [
            'Viande'              => 1,
            'Volaille'            => 2,
            'Produits laitiers'   => 3,
            'Œufs'                => 4,
            'Farines & céréales'  => 5,
            'Sucres & chocolat'   => 6,
            'Fruits & légumes'    => 7,
            'Epices'              => 8,
            'Alcools'             => 9,
            'Additifs & ferments' => 10,
            'Emballage'           => 11,
            'Autres'              => 99,
        ];

        $new = 0;
        foreach ($defs as $name => $order) {
            $c = $repo->findOneBy(['name' => $name]);
            if (!$c) {
                $c = (new IngredientCategory())->setName($name)->setSortOrder($order);
                $this->em->persist($c);
                $new++;
            }
            $this->cats[$name] = $c;
        }
        $this->em->flush();
        $io->writeln("  Catégories : $new créée(s), " . count($defs) . ' au total.');
    }

    private function loadFamilies(SymfonyStyle $io): void
    {
        $repo = $this->em->getRepository(RecipeFamily::class);
        $defs = [
            'Sous-recette'     => 1,
            'Terrine'          => 2,
            'Pâté'             => 3,
            'Saucisse'         => 4,
            'Rillettes'        => 5,
            'Traiteur'         => 6,
            'Pain'             => 10,
            'Viennoiserie'     => 11,
            'Base pâtissière'  => 20,
            'Tarte'            => 21,
            'Entremets'        => 22,
            'Petit four'       => 23,
            'Gâteau de voyage' => 24,
        ];

        $new = 0;
        foreach ($defs as $name => $order) {
            if (!$repo->findOneBy(['name' => $name])) {
                $this->em->persist((new RecipeFamily())->setName($name)->setSortOrder($order));
                $new++;
            }
        }
        $this->em->flush();
        $io->writeln("  Familles : $new créée(s).");
    }

    private function loadSuppliers(SymfonyStyle $io): void
    {
        $repo = $this->em->getRepository(Supplier::class);
        $defs = [
            'berthaud' => ['Maison Berthaud — Viandes & Salaisons', '41238765400028', 'FR41412387654', '12 rue des Abattoirs', '35000', 'Rennes'],
            'moulins'  => ['Moulins de Brasseuil', '38907654200015', 'FR38389076542', 'Route de la Meunerie', '78730', 'Brasseuil'],
            'valdeloire' => ['Laiterie du Val de Loire', '52014789300033', 'FR52520147893', 'ZA du Pré Neuf', '37210', 'Vouvray'],
            'epices'   => ['Comptoir des Épices Kerlan', '49876123500017', 'FR49498761235', '8 quai Duguay-Trouin', '35400', 'Saint-Malo'],
            'chocolat' => ['Chocolaterie Guérin Professionnels', '31245678900041', 'FR31312456789', '45 avenue du Cacao', '69007', 'Lyon'],
            'metro'    => ['Metro Cash & Carry Rennes', '39912345600123', 'FR39399123456', 'Rue de la Rigourdière', '35510', 'Cesson-Sévigné'],
            'embal'    => ['Emballages Pro Ouest', '44398712600029', 'FR44443987126', '3 rue de l\'Industrie', '44800', 'Saint-Herblain'],
        ];

        $new = 0;
        foreach ($defs as $key => [$name, $siret, $vat, $addr, $cp, $ville]) {
            $s = $repo->findOneBy(['siret' => $siret]) ?? $repo->findOneBy(['name' => $name]);
            if (!$s) {
                $s = (new Supplier())
                    ->setName($name)
                    ->setSiret($siret)
                    ->setVatNumber($vat)
                    ->setAddressLine($addr)
                    ->setPostcode($cp)
                    ->setCity($ville)
                    ->setCountry('FR');
                $this->em->persist($s);
                $new++;
            }
            $this->suppliers[$key] = $s;
        }
        $this->em->flush();
        $io->writeln("  Fournisseurs : $new créé(s), " . count($defs) . ' au total.');
    }

    // ── Ingrédients ──────────────────────────────────────────────────────────

    /**
     * Définition d'un ingrédient :
     *   [nom, catégorie, unité de base, TVA, fournisseur, allergènes, traces,
     *    poids unitaire (g, si unité = piece), recherche Ciqual, prix [date => € HT]]
     */
    private function ingredientDefs(): array
    {
        return [
            // ---- Viandes (charcutier) -------------------------------------
            'gorge_porc'     => ['Gorge de porc', 'Viande', 'kg', 5.5, 'berthaud', [], [], null, 'Porc, gorge', ['2025-09-01' => 3.60, '2026-01-15' => 3.85, '2026-06-01' => 4.05]],
            'epaule_porc'    => ['Épaule de porc désossée', 'Viande', 'kg', 5.5, 'berthaud', [], [], null, 'Porc, épaule', ['2025-09-01' => 6.20, '2026-01-15' => 6.60, '2026-06-01' => 6.85]],
            'poitrine_porc'  => ['Poitrine de porc', 'Viande', 'kg', 5.5, 'berthaud', [], [], null, 'Porc, poitrine', ['2025-09-01' => 5.40, '2026-01-15' => 5.80, '2026-06-01' => 6.10]],
            'foie_porc'      => ['Foie de porc', 'Viande', 'kg', 5.5, 'berthaud', [], [], null, 'Porc, foie', ['2025-09-01' => 3.90, '2026-02-01' => 4.25]],
            'saindoux'       => ['Saindoux', 'Viande', 'kg', 5.5, 'berthaud', [], [], null, 'Saindoux', ['2025-09-01' => 3.10, '2026-02-01' => 3.35]],
            'filet_volaille' => ['Filet de poulet fermier', 'Volaille', 'kg', 5.5, 'berthaud', [], [], null, 'Poulet, filet', ['2025-09-01' => 8.90, '2026-01-15' => 9.40, '2026-06-01' => 9.80]],

            // ---- Produits laitiers / œufs ---------------------------------
            'creme35'        => ['Crème liquide 35% MG', 'Produits laitiers', 'litre', 5.5, 'valdeloire', ['lait'], [], null, 'Crème fraîche', ['2025-09-01' => 4.05, '2026-01-15' => 4.35, '2026-06-01' => 4.60]],
            'lait_entier'    => ['Lait entier', 'Produits laitiers', 'litre', 5.5, 'valdeloire', ['lait'], [], null, 'Lait entier', ['2025-09-01' => 0.98, '2026-01-15' => 1.05]],
            'beurre_doux'    => ['Beurre doux 82% MG', 'Produits laitiers', 'kg', 5.5, 'valdeloire', ['lait'], [], null, 'Beurre', ['2025-09-01' => 7.20, '2026-01-15' => 7.80, '2026-06-01' => 8.15]],
            'beurre_tourage' => ['Beurre de tourage AOP 84% MG', 'Produits laitiers', 'kg', 5.5, 'valdeloire', ['lait'], [], null, 'Beurre', ['2025-09-01' => 8.40, '2026-01-15' => 9.10, '2026-06-01' => 9.60]],
            'mascarpone'     => ['Mascarpone', 'Produits laitiers', 'kg', 5.5, 'valdeloire', ['lait'], [], null, 'Mascarpone', ['2025-09-01' => 6.80, '2026-02-01' => 7.10]],
            'oeuf'           => ['Œuf plein air calibre M', 'Œufs', 'piece', 5.5, 'metro', ['oeufs'], [], 55.0, 'Œuf, cru', ['2025-09-01' => 0.26, '2026-01-15' => 0.29, '2026-06-01' => 0.32]],

            // ---- Farines & céréales ---------------------------------------
            'farine_t45'     => ['Farine de gruau T45', 'Farines & céréales', 'kg', 5.5, 'moulins', ['gluten'], ['soja'], null, 'Farine de blé T45', ['2025-09-01' => 0.84, '2026-02-01' => 0.88]],
            'farine_t55'     => ['Farine de blé T55', 'Farines & céréales', 'kg', 5.5, 'moulins', ['gluten'], ['soja'], null, 'Farine de blé T55', ['2025-09-01' => 0.62, '2026-01-15' => 0.66, '2026-06-01' => 0.69]],
            'farine_t65'     => ['Farine de tradition T65', 'Farines & céréales', 'kg', 5.5, 'moulins', ['gluten'], ['soja'], null, 'Farine de blé T65', ['2025-09-01' => 0.74, '2026-01-15' => 0.79, '2026-06-01' => 0.83]],
            'farine_t80'     => ['Farine de meule T80', 'Farines & céréales', 'kg', 5.5, 'moulins', ['gluten'], ['soja'], null, 'Farine de blé T80', ['2025-09-01' => 0.90, '2026-02-01' => 0.94]],
            'farine_seigle'  => ['Farine de seigle T130', 'Farines & céréales', 'kg', 5.5, 'moulins', ['gluten'], [], null, 'Farine de seigle', ['2025-09-01' => 1.02, '2026-02-01' => 1.08]],
            'poudre_creme'   => ['Poudre à crème', 'Farines & céréales', 'kg', 5.5, 'metro', [], [], null, 'Amidon de maïs', ['2025-09-01' => 3.20, '2026-02-01' => 3.40]],
            'chapelure'      => ['Chapelure blanche', 'Farines & céréales', 'kg', 5.5, 'metro', ['gluten'], ['sesame'], null, 'Chapelure', ['2025-09-01' => 1.85, '2026-02-01' => 1.98]],

            // ---- Sucres & chocolat ----------------------------------------
            'sucre'          => ['Sucre semoule', 'Sucres & chocolat', 'kg', 5.5, 'metro', [], [], null, 'Sucre blanc', ['2025-09-01' => 0.96, '2026-01-15' => 1.02, '2026-06-01' => 1.08]],
            'sucre_glace'    => ['Sucre glace', 'Sucres & chocolat', 'kg', 5.5, 'metro', [], [], null, 'Sucre glace', ['2025-09-01' => 1.48, '2026-02-01' => 1.55]],
            'glucose'        => ['Sirop de glucose', 'Sucres & chocolat', 'kg', 5.5, 'chocolat', [], [], null, 'Sirop de glucose', ['2025-09-01' => 2.95, '2026-02-01' => 3.10]],
            'choco_noir70'   => ['Couverture noire 70%', 'Sucres & chocolat', 'kg', 5.5, 'chocolat', ['soja'], ['lait', 'fruits_a_coque'], null, 'Chocolat noir', ['2025-09-01' => 10.90, '2026-01-15' => 12.60, '2026-06-01' => 13.90]],
            'choco_lait40'   => ['Couverture lait 40%', 'Sucres & chocolat', 'kg', 5.5, 'chocolat', ['lait', 'soja'], ['fruits_a_coque'], null, 'Chocolat au lait', ['2025-09-01' => 9.80, '2026-01-15' => 11.20, '2026-06-01' => 12.30]],
            'pepites_choco'  => ['Pépites de chocolat', 'Sucres & chocolat', 'kg', 5.5, 'chocolat', ['soja'], ['lait'], null, 'Chocolat noir', ['2025-09-01' => 8.40, '2026-02-01' => 8.90]],
            'poudre_amande'  => ['Poudre d\'amande', 'Sucres & chocolat', 'kg', 5.5, 'metro', ['fruits_a_coque'], [], null, 'Amande', ['2025-09-01' => 12.40, '2026-01-15' => 13.80, '2026-06-01' => 14.60]],
            'pistache'       => ['Pistache émondée', 'Sucres & chocolat', 'kg', 5.5, 'metro', ['fruits_a_coque'], [], null, 'Pistache', ['2025-09-01' => 23.50, '2026-02-01' => 24.90]],

            // ---- Fruits & légumes -----------------------------------------
            'oignon'         => ['Oignon jaune', 'Fruits & légumes', 'kg', 5.5, 'metro', [], [], null, 'Oignon, cru', ['2025-09-01' => 1.20, '2026-02-01' => 1.35]],
            'persil'         => ['Persil plat frais', 'Fruits & légumes', 'kg', 5.5, 'metro', [], [], null, 'Persil, frais', ['2025-09-01' => 8.90, '2026-02-01' => 9.50]],
            'citron'         => ['Citron jaune', 'Fruits & légumes', 'piece', 5.5, 'metro', [], [], 110.0, 'Citron, pulpe', ['2025-09-01' => 0.34, '2026-02-01' => 0.38]],
            'fraise'         => ['Fraise Gariguette', 'Fruits & légumes', 'kg', 5.5, 'metro', [], [], null, 'Fraise, crue', ['2025-09-01' => 8.60, '2026-04-01' => 7.40]],
            'puree_framboise' => ['Purée de framboise surgelée', 'Fruits & légumes', 'kg', 5.5, 'metro', [], [], null, 'Framboise', ['2025-09-01' => 7.80, '2026-02-01' => 8.20]],
            'raisin_sec'     => ['Raisin sec sultanine', 'Fruits & légumes', 'kg', 5.5, 'metro', [], ['sulfites'], null, 'Raisin sec', ['2025-09-01' => 4.35, '2026-02-01' => 4.60]],
            'ail_semoule'    => ['Ail semoule', 'Fruits & légumes', 'kg', 5.5, 'epices', [], [], null, 'Ail', ['2025-09-01' => 8.80, '2026-02-01' => 9.20]],

            // ---- Épices ---------------------------------------------------
            'sel_nitrite'    => ['Sel nitrité 0,6%', 'Epices', 'kg', 5.5, 'epices', [], [], null, 'Sel', ['2025-09-01' => 2.10, '2026-02-01' => 2.35]],
            'sel_fin'        => ['Sel fin de mer', 'Epices', 'kg', 5.5, 'epices', [], [], null, 'Sel', ['2025-09-01' => 0.88, '2026-02-01' => 0.95]],
            'poivre_noir'    => ['Poivre noir moulu', 'Epices', 'kg', 5.5, 'epices', [], [], null, 'Poivre noir', ['2025-09-01' => 17.50, '2026-01-15' => 19.80, '2026-06-01' => 21.40]],
            'muscade'        => ['Noix de muscade moulue', 'Epices', 'kg', 5.5, 'epices', [], [], null, 'Muscade', ['2025-09-01' => 31.00, '2026-02-01' => 34.00]],
            'quatre_epices'  => ['Mélange 4 épices', 'Epices', 'kg', 5.5, 'epices', [], ['moutarde', 'celeri'], null, null, ['2025-09-01' => 23.00, '2026-02-01' => 24.50]],
            'vanille'        => ['Gousse de vanille Bourbon', 'Epices', 'piece', 5.5, 'epices', [], [], 3.0, 'Vanille', ['2025-09-01' => 3.60, '2026-02-01' => 3.90]],

            // ---- Alcools (TVA 20%) ----------------------------------------
            'cognac'         => ['Cognac VS', 'Alcools', 'litre', 20.0, 'metro', [], ['sulfites'], null, null, ['2025-09-01' => 25.40, '2026-02-01' => 26.50]],
            'vin_blanc'      => ['Vin blanc sec de cuisine', 'Alcools', 'litre', 20.0, 'metro', ['sulfites'], [], null, null, ['2025-09-01' => 2.80, '2026-02-01' => 2.95]],
            'porto'          => ['Porto rouge', 'Alcools', 'litre', 20.0, 'metro', ['sulfites'], [], null, null, ['2025-09-01' => 11.20, '2026-02-01' => 11.80]],

            // ---- Additifs & ferments --------------------------------------
            'levure_fraiche' => ['Levure de boulanger fraîche', 'Additifs & ferments', 'kg', 5.5, 'moulins', [], [], null, 'Levure', ['2025-09-01' => 2.70, '2026-02-01' => 2.85]],
            'gelatine'       => ['Gélatine en feuille 2 g', 'Additifs & ferments', 'piece', 5.5, 'metro', [], [], 2.0, null, ['2025-09-01' => 0.085, '2026-02-01' => 0.09]],
            'pectine_nh'     => ['Pectine NH nappage', 'Additifs & ferments', 'kg', 5.5, 'metro', [], [], null, null, ['2025-09-01' => 39.00, '2026-02-01' => 42.00]],
            'gelee_poudre'   => ['Gelée en poudre pour charcuterie', 'Additifs & ferments', 'kg', 5.5, 'epices', [], [], null, null, ['2025-09-01' => 9.20, '2026-02-01' => 9.60]],
            'boyau_porc'     => ['Boyau naturel de porc (au mètre)', 'Additifs & ferments', 'piece', 5.5, 'berthaud', [], [], 6.0, null, ['2025-09-01' => 0.40, '2026-02-01' => 0.42]],
            'eau'            => ['Eau de réseau', 'Autres', 'litre', 5.5, null, [], [], null, 'Eau du robinet', ['2025-09-01' => 0.0045]],

            // ---- Emballage (TVA 20%) --------------------------------------
            'barquette500'   => ['Barquette operculable 500 ml', 'Emballage', 'piece', 20.0, 'embal', [], [], null, null, ['2025-09-01' => 0.22, '2026-02-01' => 0.24]],
            'bocal350'       => ['Bocal verre 350 ml + capsule', 'Emballage', 'piece', 20.0, 'embal', [], [], null, null, ['2025-09-01' => 0.82, '2026-02-01' => 0.88]],
            'etiquette'      => ['Étiquette adhésive imprimée', 'Emballage', 'piece', 20.0, 'embal', [], [], null, null, ['2025-09-01' => 0.032, '2026-02-01' => 0.035]],
            'sac_baguette'   => ['Sac papier baguette', 'Emballage', 'piece', 20.0, 'embal', [], [], null, null, ['2025-09-01' => 0.026, '2026-02-01' => 0.028]],
            'boite_patisserie' => ['Boîte pâtissière 20 cm', 'Emballage', 'piece', 20.0, 'embal', [], [], null, null, ['2025-09-01' => 0.68, '2026-02-01' => 0.74]],
        ];
    }

    private function loadIngredients(SymfonyStyle $io): void
    {
        $repo      = $this->em->getRepository(Ingredient::class);
        $priceRepo = $this->em->getRepository(IngredientPrice::class);
        $newIng    = 0;
        $newPrice  = 0;

        foreach ($this->ingredientDefs() as $key => [$name, $cat, $unit, $vat, $supKey, $allerg, $traces, $unitW, $ciqual, $prices]) {
            $ing = $repo->findOneBy(['name' => $name]);
            if (!$ing) {
                $ing = new Ingredient();
                $ing->setName($name);
                $this->em->persist($ing);
                $newIng++;
            }

            $ing->setCategory($this->cats[$cat] ?? $this->cats['Autres']);
            $ing->setBaseUnit($unit);
            $ing->setVatRate($vat);
            $ing->setDefaultSupplier($supKey ? $this->suppliers[$supKey]->getName() : null);
            $ing->setAllergens($allerg);
            $ing->setTraces($traces);
            $ing->setUnitWeightG($unitW);

            // Rattachement Ciqual : uniquement si la table a été importée et
            // qu'un aliment correspond, sinon la nutrition reste incalculable.
            // Marqué « auto » comme tout appariement non validé par un humain.
            if (!$ing->getCiqualCode()) {
                $best = $this->matcher->bestMatch($ciqual ?: $name, $cat);
                if ($best) {
                    $ing->setCiqualCode($best['food']->getCode());
                    $ing->setCiqualAuto(true);
                }
            }

            $this->em->flush(); // besoin de l'id pour rechercher les prix existants

            foreach ($prices as $date => $priceHt) {
                $eff = new \DateTime($date);
                if ($ing->getId() && $priceRepo->findOneBy(['ingredient' => $ing, 'effectiveDate' => $eff])) {
                    continue;
                }
                $p = new IngredientPrice();
                $p->setPriceHt($priceHt);
                $p->setEffectiveDate($eff);
                if ($supKey) {
                    $p->setSupplierEntity($this->suppliers[$supKey]);
                } else {
                    $p->setSupplier('Saisie manuelle');
                }
                $ing->addPrice($p);
                $this->em->persist($p);
                $newPrice++;
            }

            $this->ings[$key] = $ing;
        }

        $this->em->flush();
        $io->writeln("  Ingrédients : $newIng créé(s), $newPrice tarif(s) ajouté(s).");

        if (!$this->ciqualRepo->count([])) {
            $io->writeln('  <comment>Table Ciqual vide : aucun rattachement nutritionnel. Lancez « app:import-ciqual » puis relancez.</comment>');
        }
    }

    // ── Recettes ─────────────────────────────────────────────────────────────

    /**
     * Définition d'une recette. Les lignes référencent soit une clé
     * d'ingrédient (`i`), soit une clé de recette (`r`, sous-recette).
     */
    private function recipeDefs(): array
    {
        return [
            // ═══ CHARCUTIER-TRAITEUR ═════════════════════════════════════════
            'farce_fine' => [
                'profile' => 'charcutier', 'name' => 'Farce fine de porc', 'family' => 'Sous-recette',
                'output' => ['weight', 10.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 45, 'packaging' => 0.0, 'pricing' => ['coef', 1.600],
                'lines' => [
                    ['i', 'gorge_porc', 5.0, 'kg'],
                    ['i', 'epaule_porc', 3.0, 'kg'],
                    ['i', 'foie_porc', 1.2, 'kg', 3, 100, 'Parage nerfs'],
                    ['i', 'oeuf', 8, 'piece'],
                    ['i', 'creme35', 0.4, 'litre'],
                    ['i', 'sel_nitrite', 0.180, 'kg'],
                    ['i', 'poivre_noir', 0.025, 'kg'],
                    ['i', 'quatre_epices', 0.012, 'kg'],
                    ['i', 'cognac', 0.12, 'litre'],
                ],
            ],
            'pate_croute' => [
                'profile' => 'charcutier', 'name' => 'Pâte à pâté en croûte', 'family' => 'Sous-recette',
                'output' => ['weight', 2.35], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 25, 'packaging' => 0.0, 'pricing' => ['coef', 1.600],
                'lines' => [
                    ['i', 'farine_t55', 1.3, 'kg'],
                    ['i', 'beurre_doux', 0.6, 'kg'],
                    ['i', 'oeuf', 3, 'piece'],
                    ['i', 'eau', 0.28, 'litre'],
                    ['i', 'sel_fin', 0.022, 'kg'],
                ],
            ],
            'terrine_campagne' => [
                'profile' => 'charcutier', 'name' => 'Terrine de campagne', 'family' => 'Terrine',
                'output' => ['weight', 6.0], 'loss' => 14, 'yield' => 100, 'vat' => 5.5,
                'labor' => 55, 'packaging' => 1.55, 'pricing' => ['coef', 1.800],
                'lines' => [
                    ['r', 'farce_fine', 6.5, 'kg'],
                    ['i', 'oignon', 0.5, 'kg', 12, 100, 'Épluchage'],
                    ['i', 'persil', 0.05, 'kg', 25, 100, 'Équeutage'],
                    ['i', 'vin_blanc', 0.2, 'litre'],
                    ['i', 'poivre_noir', 0.010, 'kg'],
                    ['i', 'barquette500', 10, 'piece'],
                    ['i', 'etiquette', 10, 'piece'],
                ],
            ],
            'pate_en_croute' => [
                'profile' => 'charcutier', 'name' => 'Pâté en croûte volaille-pistache', 'family' => 'Pâté',
                'output' => ['portion', 30.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 120, 'packaging' => 2.40, 'pricing' => ['margin', 45.000],
                'lines' => [
                    ['r', 'pate_croute', 1.8, 'kg'],
                    ['r', 'farce_fine', 2.6, 'kg'],
                    ['i', 'filet_volaille', 0.8, 'kg', 8, 100, 'Parage'],
                    ['i', 'pistache', 0.120, 'kg'],
                    ['i', 'porto', 0.10, 'litre'],
                    ['i', 'gelee_poudre', 0.060, 'kg'],
                    ['i', 'oeuf', 1, 'piece', 0, 100, 'Dorure'],
                ],
            ],
            'saucisse_toulouse' => [
                'profile' => 'charcutier', 'name' => 'Saucisse de Toulouse', 'family' => 'Saucisse',
                'output' => ['weight', 10.0], 'loss' => 2, 'yield' => 100, 'vat' => 5.5,
                'labor' => 55, 'packaging' => 0.70, 'pricing' => ['coef', 1.300],
                'lines' => [
                    ['i', 'epaule_porc', 7.0, 'kg'],
                    ['i', 'poitrine_porc', 3.0, 'kg'],
                    ['i', 'sel_fin', 0.180, 'kg'],
                    ['i', 'poivre_noir', 0.030, 'kg'],
                    ['i', 'ail_semoule', 0.040, 'kg'],
                    ['i', 'boyau_porc', 16, 'piece', 0, 100, '16 m de boyau'],
                ],
            ],
            'rillettes' => [
                'profile' => 'charcutier', 'name' => 'Rillettes du Mans', 'family' => 'Rillettes',
                'output' => ['weight', 8.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 120, 'packaging' => 0.90, 'pricing' => ['coef', 1.400],
                'lines' => [
                    ['i', 'poitrine_porc', 10.0, 'kg'],
                    ['i', 'epaule_porc', 3.0, 'kg'],
                    ['i', 'saindoux', 0.6, 'kg'],
                    ['i', 'sel_fin', 0.120, 'kg'],
                    ['i', 'poivre_noir', 0.015, 'kg'],
                    ['i', 'vin_blanc', 0.50, 'litre'],
                    ['i', 'bocal350', 20, 'piece'],
                    ['i', 'etiquette', 20, 'piece'],
                ],
            ],
            'quiche_lorraine' => [
                'profile' => 'charcutier', 'name' => 'Quiche lorraine 8 parts', 'family' => 'Traiteur',
                'output' => ['portion', 8.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 14, 'packaging' => 0.35, 'pricing' => ['coef', 1.450],
                'lines' => [
                    ['r', 'pate_croute', 0.45, 'kg'],
                    ['i', 'poitrine_porc', 0.25, 'kg', 5, 100, 'Taillée en lardons'],
                    ['i', 'oeuf', 4, 'piece'],
                    ['i', 'creme35', 0.35, 'litre'],
                    ['i', 'lait_entier', 0.15, 'litre'],
                    ['i', 'muscade', 0.002, 'kg'],
                    ['i', 'poivre_noir', 0.002, 'kg'],
                ],
            ],

            // ═══ BOULANGER ═══════════════════════════════════════════════════
            'levain' => [
                'profile' => 'boulanger', 'name' => 'Levain liquide', 'family' => 'Sous-recette',
                'output' => ['weight', 4.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 10, 'packaging' => 0.0, 'pricing' => ['coef', 1.500],
                'lines' => [
                    ['i', 'farine_t80', 1.9, 'kg'],
                    ['i', 'eau', 2.1, 'litre'],
                ],
            ],
            'pate_baguette' => [
                'profile' => 'boulanger', 'name' => 'Pâte à baguette tradition', 'family' => 'Sous-recette',
                'output' => ['weight', 35.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 45, 'packaging' => 0.0, 'pricing' => ['coef', 1.500],
                'lines' => [
                    ['i', 'farine_t65', 20.0, 'kg'],
                    ['i', 'eau', 13.0, 'litre'],
                    ['r', 'levain', 2.0, 'kg'],
                    ['i', 'levure_fraiche', 0.060, 'kg'],
                    ['i', 'sel_fin', 0.360, 'kg'],
                ],
            ],
            'baguette_tradition' => [
                'profile' => 'boulanger', 'name' => 'Baguette de tradition française', 'family' => 'Pain',
                'output' => ['portion', 20.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 25, 'packaging' => 0.0, 'pricing' => ['coef', 1.450],
                'lines' => [
                    ['r', 'pate_baguette', 6.5, 'kg', 0, 100, '20 pâtons de 325 g'],
                    ['i', 'sac_baguette', 20, 'piece'],
                ],
            ],
            'pain_campagne' => [
                'profile' => 'boulanger', 'name' => 'Pain de campagne 800 g', 'family' => 'Pain',
                'output' => ['portion', 6.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 25, 'packaging' => 0.0, 'pricing' => ['coef', 1.600],
                'lines' => [
                    ['i', 'farine_t65', 2.4, 'kg'],
                    ['i', 'farine_seigle', 0.6, 'kg'],
                    ['r', 'levain', 1.2, 'kg'],
                    ['i', 'eau', 2.1, 'litre'],
                    ['i', 'sel_fin', 0.060, 'kg'],
                    ['i', 'levure_fraiche', 0.006, 'kg'],
                ],
            ],
            'detrempe_croissant' => [
                'profile' => 'boulanger', 'name' => 'Détrempe croissant (tourée)', 'family' => 'Sous-recette',
                'output' => ['weight', 19.2], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 90, 'packaging' => 0.0, 'pricing' => ['coef', 1.600],
                'lines' => [
                    ['i', 'farine_t45', 6.0, 'kg'],
                    ['i', 'farine_t65', 3.0, 'kg'],
                    ['i', 'eau', 2.7, 'litre'],
                    ['i', 'lait_entier', 1.8, 'litre'],
                    ['i', 'levure_fraiche', 0.270, 'kg'],
                    ['i', 'sel_fin', 0.180, 'kg'],
                    ['i', 'sucre', 1.080, 'kg'],
                    ['i', 'beurre_tourage', 4.5, 'kg', 0, 100, 'Beurre de tourage'],
                ],
            ],
            'croissant' => [
                'profile' => 'boulanger', 'name' => 'Croissant pur beurre', 'family' => 'Viennoiserie',
                'output' => ['portion', 100.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 60, 'packaging' => 0.0, 'pricing' => ['coef', 2.200],
                'lines' => [
                    ['r', 'detrempe_croissant', 8.5, 'kg', 0, 100, 'Pâtons de 85 g'],
                    ['i', 'oeuf', 4, 'piece', 0, 100, 'Dorure'],
                ],
            ],
            'pain_aux_raisins' => [
                'profile' => 'boulanger', 'name' => 'Pain aux raisins', 'family' => 'Viennoiserie',
                'output' => ['portion', 40.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 40, 'packaging' => 0.0, 'pricing' => ['coef', 1.450],
                'lines' => [
                    ['r', 'detrempe_croissant', 4.2, 'kg'],
                    ['r', 'creme_patissiere', 2.0, 'kg'],
                    ['i', 'raisin_sec', 0.500, 'kg'],
                    ['i', 'oeuf', 3, 'piece', 0, 100, 'Dorure'],
                ],
            ],
            'cookie_choco' => [
                'profile' => 'boulanger', 'name' => 'Cookie pépites de chocolat', 'family' => 'Petit four',
                'output' => ['portion', 60.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 50, 'packaging' => 0.0, 'pricing' => ['coef', 2.600],
                'lines' => [
                    ['i', 'farine_t55', 2.0, 'kg'],
                    ['i', 'beurre_doux', 1.0, 'kg'],
                    ['i', 'sucre', 1.10, 'kg'],
                    ['i', 'oeuf', 8, 'piece'],
                    ['i', 'pepites_choco', 0.90, 'kg'],
                    ['i', 'sel_fin', 0.016, 'kg'],
                ],
            ],

            // ═══ PÂTISSIER ═══════════════════════════════════════════════════
            'creme_patissiere' => [
                'profile' => 'patissier', 'name' => 'Crème pâtissière vanille', 'family' => 'Base pâtissière',
                'output' => ['weight', 4.0], 'loss' => 5, 'yield' => 100, 'vat' => 5.5,
                'labor' => 30, 'packaging' => 0.0, 'pricing' => ['coef', 1.600],
                'lines' => [
                    ['i', 'lait_entier', 3.0, 'litre'],
                    ['i', 'oeuf', 12, 'piece', 45, 100, 'Jaunes uniquement'],
                    ['i', 'sucre', 0.600, 'kg'],
                    ['i', 'poudre_creme', 0.240, 'kg'],
                    ['i', 'vanille', 1, 'piece'],
                    ['i', 'beurre_doux', 0.100, 'kg'],
                ],
            ],
            'pate_sucree' => [
                'profile' => 'patissier', 'name' => 'Pâte sucrée amande', 'family' => 'Base pâtissière',
                'output' => ['weight', 2.4], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 20, 'packaging' => 0.0, 'pricing' => ['coef', 1.600],
                'lines' => [
                    ['i', 'farine_t55', 1.2, 'kg'],
                    ['i', 'beurre_doux', 0.6, 'kg'],
                    ['i', 'sucre_glace', 0.45, 'kg'],
                    ['i', 'poudre_amande', 0.15, 'kg'],
                    ['i', 'oeuf', 3, 'piece'],
                ],
            ],
            'ganache_70' => [
                'profile' => 'patissier', 'name' => 'Ganache chocolat 70%', 'family' => 'Base pâtissière',
                'output' => ['weight', 1.6], 'loss' => 3, 'yield' => 100, 'vat' => 5.5,
                'labor' => 20, 'packaging' => 0.0, 'pricing' => ['coef', 1.600],
                'lines' => [
                    ['i', 'choco_noir70', 0.75, 'kg'],
                    ['i', 'creme35', 0.70, 'litre'],
                    ['i', 'glucose', 0.060, 'kg'],
                    ['i', 'beurre_doux', 0.080, 'kg'],
                ],
            ],
            'pate_a_choux' => [
                'profile' => 'patissier', 'name' => 'Pâte à choux', 'family' => 'Base pâtissière',
                'output' => ['weight', 2.2], 'loss' => 8, 'yield' => 100, 'vat' => 5.5,
                'labor' => 25, 'packaging' => 0.0, 'pricing' => ['coef', 1.600],
                'lines' => [
                    ['i', 'eau', 0.5, 'litre'],
                    ['i', 'lait_entier', 0.5, 'litre'],
                    ['i', 'beurre_doux', 0.40, 'kg'],
                    ['i', 'farine_t55', 0.55, 'kg'],
                    ['i', 'oeuf', 16, 'piece'],
                    ['i', 'sel_fin', 0.010, 'kg'],
                    ['i', 'sucre', 0.020, 'kg'],
                ],
            ],
            'tarte_citron' => [
                'profile' => 'patissier', 'name' => 'Tarte au citron meringuée 6 parts', 'family' => 'Tarte',
                'output' => ['portion', 24.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 60, 'packaging' => 0.0, 'pricing' => ['coef', 1.500],
                'lines' => [
                    ['r', 'pate_sucree', 1.40, 'kg', 0, 100, '4 fonds de tarte'],
                    ['i', 'citron', 20, 'piece', 62, 100, 'Jus + zestes'],
                    ['i', 'oeuf', 16, 'piece'],
                    ['i', 'sucre', 0.880, 'kg'],
                    ['i', 'beurre_doux', 0.720, 'kg'],
                    ['i', 'gelatine', 8, 'piece'],
                    ['i', 'boite_patisserie', 4, 'piece'],
                ],
            ],
            'entremets_choco_framboise' => [
                'profile' => 'patissier', 'name' => 'Entremets chocolat-framboise 8 parts', 'family' => 'Entremets',
                'output' => ['portion', 24.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 90, 'packaging' => 0.0, 'pricing' => ['margin', 38.000],
                'lines' => [
                    ['r', 'ganache_70', 1.65, 'kg', 0, 100, '3 entremets'],
                    ['i', 'puree_framboise', 0.90, 'kg'],
                    ['i', 'creme35', 0.90, 'litre'],
                    ['i', 'poudre_amande', 0.240, 'kg'],
                    ['i', 'oeuf', 12, 'piece'],
                    ['i', 'sucre', 0.540, 'kg'],
                    ['i', 'gelatine', 12, 'piece'],
                    ['i', 'pectine_nh', 0.024, 'kg'],
                    ['i', 'boite_patisserie', 3, 'piece'],
                ],
            ],
            'eclair_chocolat' => [
                'profile' => 'patissier', 'name' => 'Éclair au chocolat', 'family' => 'Petit four',
                'output' => ['portion', 48.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 100, 'packaging' => 0.0, 'pricing' => ['coef', 1.600],
                'lines' => [
                    ['r', 'pate_a_choux', 3.2, 'kg'],
                    ['r', 'creme_patissiere', 3.6, 'kg'],
                    ['i', 'choco_noir70', 0.500, 'kg'],
                    ['i', 'sucre_glace', 0.300, 'kg'],
                ],
            ],
            'fraisier' => [
                'profile' => 'patissier', 'name' => 'Fraisier 8 parts', 'family' => 'Entremets',
                'output' => ['portion', 24.0], 'loss' => 0, 'yield' => 100, 'vat' => 5.5,
                'labor' => 90, 'packaging' => 0.0, 'pricing' => ['coef', 1.550],
                'lines' => [
                    ['r', 'creme_patissiere', 1.80, 'kg', 0, 100, '3 fraisiers'],
                    ['i', 'mascarpone', 0.450, 'kg'],
                    ['i', 'fraise', 1.500, 'kg', 8, 100, 'Équeutage'],
                    ['i', 'sucre', 0.300, 'kg'],
                    ['i', 'gelatine', 9, 'piece'],
                    ['i', 'poudre_amande', 0.200, 'kg'],
                    ['i', 'oeuf', 9, 'piece'],
                    ['i', 'boite_patisserie', 3, 'piece'],
                ],
            ],
            'cake_vanille' => [
                'profile' => 'patissier', 'name' => 'Cake vanille', 'family' => 'Gâteau de voyage',
                'output' => ['weight', 4.8], 'loss' => 8, 'yield' => 100, 'vat' => 5.5,
                'labor' => 55, 'packaging' => 2.20, 'pricing' => ['coef', 1.700],
                'lines' => [
                    ['i', 'farine_t55', 1.400, 'kg'],
                    ['i', 'sucre', 1.280, 'kg'],
                    ['i', 'beurre_doux', 1.200, 'kg'],
                    ['i', 'oeuf', 24, 'piece'],
                    ['i', 'poudre_amande', 0.320, 'kg'],
                    ['i', 'vanille', 1, 'piece'],
                ],
            ],
        ];
    }

    private function loadRecipes(string $profile, SymfonyStyle $io): void
    {
        $defs     = $this->recipeDefs();
        $selected = $this->selectRecipes($defs, $profile);
        $repo     = $this->em->getRepository(Recipe::class);
        $taux     = $this->configRepo->getConfig()->getTauxHoraireMo();

        // 1er passage : créer les recettes (sans lignes) pour pouvoir résoudre
        // les références de sous-recettes dans n'importe quel ordre.
        $new = 0;
        foreach ($selected as $key) {
            $d = $defs[$key];
            $r = $repo->findOneBy(['name' => $d['name']]);
            if (!$r) {
                $r = new Recipe();
                $r->setName($d['name']);
                $this->em->persist($r);
                $new++;
            }

            [$outType, $outValue] = $d['output'];
            [$mode, $value]       = $d['pricing'];

            $r->setFamily($d['family'])
              ->setOutputType($outType)
              ->setOutputValue($outValue)
              ->setLossPercent((float) $d['loss'])
              ->setYieldPercent((float) $d['yield'])
              ->setProductVatRate((float) $d['vat'])
              ->setLaborMinutes((int) $d['labor'])
              ->setLaborCostHt($d['labor'] / 60 * $taux)
              ->setPackagingCostHt((float) $d['packaging'])
              ->setPricingMode($mode)
              ->setPricingValue((float) $value);

            $this->recipes[$key] = $r;
        }
        $this->em->flush();

        // 2e passage : (re)construire les lignes.
        $lineCount = 0;
        foreach ($selected as $key) {
            $r = $this->recipes[$key];

            foreach ($r->getLines()->toArray() as $old) {
                $r->removeLine($old);
                $this->em->remove($old);
            }
            $this->em->flush();

            $sort = 0;
            foreach ($defs[$key]['lines'] as $l) {
                [$type, $ref, $qty, $unit] = $l;
                $loss  = $l[4] ?? 0;
                $yield = $l[5] ?? 100;
                $note  = $l[6] ?? null;

                $line = new RecipeLine();
                $line->setQtyBrute((float) $qty)
                     ->setUnit($unit)
                     ->setLossPercent((float) $loss)
                     ->setYieldPercent((float) $yield)
                     ->setNote($note)
                     ->setSortOrder($sort++);

                if ($type === 'i') {
                    if (!isset($this->ings[$ref])) {
                        throw new \RuntimeException("Ingrédient inconnu « $ref » dans la recette « {$defs[$key]['name']} »");
                    }
                    $line->setIngredient($this->ings[$ref]);
                } else {
                    if (!isset($this->recipes[$ref])) {
                        throw new \RuntimeException("Sous-recette inconnue « $ref » dans la recette « {$defs[$key]['name']} »");
                    }
                    $line->setSubRecipe($this->recipes[$ref]);
                }

                $r->addLine($line);
                $this->em->persist($line);
                $lineCount++;
            }
        }

        $this->em->flush();
        $io->writeln('  Recettes : ' . count($selected) . " traitée(s) dont $new nouvelle(s), $lineCount ligne(s).");
    }

    /**
     * Recettes du profil demandé + leurs sous-recettes (résolution transitive),
     * dans un ordre où chaque sous-recette précède ses parents.
     *
     * @return string[] clés de recettes
     */
    private function selectRecipes(array $defs, string $profile): array
    {
        $wanted = array_keys(array_filter(
            $defs,
            fn (array $d) => $profile === 'all' || $d['profile'] === $profile
        ));

        $out = [];
        $add = function (string $key) use (&$add, &$out, $defs): void {
            if (isset($out[$key])) return;
            $out[$key] = true; // marque avant récursion : coupe les cycles éventuels
            foreach ($defs[$key]['lines'] as $l) {
                if ($l[0] === 'r') $add($l[1]);
            }
        };
        foreach ($wanted as $key) {
            $add($key);
        }

        // Les dépendances doivent être créées avant leurs parents : on trie par
        // profondeur croissante dans le graphe des sous-recettes.
        $depth = function (string $key) use (&$depth, $defs): int {
            $max = 0;
            foreach ($defs[$key]['lines'] as $l) {
                if ($l[0] === 'r' && $l[1] !== $key) {
                    $max = max($max, 1 + $depth($l[1]));
                }
            }
            return $max;
        };

        $keys = array_keys($out);
        usort($keys, fn ($a, $b) => $depth($a) <=> $depth($b));

        return $keys;
    }

    // ── Restitution ──────────────────────────────────────────────────────────

    private function printMarginTable(SymfonyStyle $io): void
    {
        $rows = [];
        foreach ($this->recipes as $r) {
            $c = $r->getCostCache();
            if (!$c) continue;

            $unit = $r->getOutputUnitLabel();
            $rows[] = [
                $r->getName(),
                $r->getFamily(),
                number_format($c->getTotalCostHt(), 2, ',', ' ') . ' €',
                number_format($c->getCostPerOutputHt(), 2, ',', ' ') . ' €/' . $unit,
                number_format($c->getAdvisedSellHt(), 2, ',', ' ') . ' €',
                number_format($c->getAdvisedSellTtc(), 2, ',', ' ') . ' €',
                number_format($c->getMarginHt(), 2, ',', ' ') . ' €',
                number_format($c->getMarginPercent(), 1, ',', ' ') . ' %',
            ];
        }

        if (!$rows) {
            return;
        }

        $io->section('Coûts et marges calculés');
        $io->table(
            ['Recette', 'Famille', 'Coût total HT', 'Coût / unité', 'PV HT', 'PV TTC', 'Marge HT', 'Marque'],
            $rows
        );
        $io->note('« Marque » = (PV − coût) / PV, cohérent avec le cache de coûts.');

        $this->printWarnings($io);
    }

    /** Alertes du calculateur (prix manquant, unité incompatible, masse incohérente). */
    private function printWarnings(SymfonyStyle $io): void
    {
        $lines = [];
        foreach ($this->recipes as $r) {
            $w = $this->calc->compute($r->getId())['totals']['warnings'] ?? null;
            if (!$w || $w['total'] === 0) continue;

            $details = [];
            if ($w['no_price'])             $details[] = $w['no_price'] . ' ligne(s) sans prix';
            if ($w['unit_mismatch'])        $details[] = $w['unit_mismatch'] . ' unité(s) inconvertible(s)';
            if ($w['pricing'])              $details[] = 'tarification : ' . $w['pricing'];
            if ($w['output_exceeds_input']) $details[] = 'rendement > masse nette entrante (' . $w['net_input_kg'] . ' kg)';

            $lines[] = $r->getName() . ' — ' . implode(', ', $details);
        }

        if ($lines) {
            $io->warning(array_merge(['Alertes de calcul :'], $lines));
        }
    }
}
