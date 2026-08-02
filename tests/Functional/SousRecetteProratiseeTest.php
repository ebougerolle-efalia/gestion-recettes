<?php
namespace App\Tests\Functional;

use App\Repository\RecipeRepository;
use App\Service\CostCalculator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les composants d'une sous-recette sont ramenés à ce qui est consommé.
 *
 * Sans cette proratisation, les fiches affichaient la sous-recette ENTIÈRE :
 * une farce fabriquée par bâches de 10 kg dont on prélève 2,6 kg montrait
 * « 5 kg de gorge » là où il en faut 1,3. Lu au poste de travail, cela conduit
 * à fabriquer quatre fois trop ; lu sur la fiche chiffrée, la somme des
 * composants ne correspondait pas au coût de la ligne — 59,49 € affichés pour
 * une contribution réelle de 20,33 €.
 *
 * Le calcul vit dans CostCalculator et non dans les gabarits, pour que tout
 * consommateur — écran, fiche labo, fiche chiffrée — reçoive la même valeur.
 */
class SousRecetteProratiseeTest extends KernelTestCase
{
    private function calculateur(): CostCalculator
    {
        self::bootKernel();

        return static::getContainer()->get(CostCalculator::class);
    }

    /**
     * Cherche une recette contenant au moins une sous-recette dont la quantité
     * utilisée diffère de la production de base — sans quoi le rapport vaut 1
     * et le test ne démontrerait rien.
     */
    private function trouverRecetteAvecSousRecette(CostCalculator $calc): ?array
    {
        $recettes = static::getContainer()->get(RecipeRepository::class)->findAll();

        foreach ($recettes as $recette) {
            $calcule = $calc->compute($recette->getId());

            if (!$calcule) {
                continue;
            }

            foreach ($calcule['lines'] as $ligne) {
                if (($ligne['type'] ?? '') !== 'sub_recipe' || empty($ligne['sub_recipe']['components'])) {
                    continue;
                }

                $base   = (float) $ligne['sub_recipe']['output_value'];
                $utilise = (float) $ligne['sub_recipe']['qty_used'];

                if ($base > 0 && abs($utilise - $base) > 0.0001) {
                    return $ligne;
                }
            }
        }

        return null;
    }

    public function testLesComposantsSontRamenesALaQuantiteConsommee(): void
    {
        $calc  = $this->calculateur();
        $ligne = $this->trouverRecetteAvecSousRecette($calc);

        if ($ligne === null) {
            self::markTestSkipped('Aucune recette de test n\'emploie une sous-recette en quantité partielle.');
        }

        $base    = (float) $ligne['sub_recipe']['output_value'];
        $utilise = (float) $ligne['sub_recipe']['qty_used'];
        $rapport = $utilise / $base;

        self::assertLessThan(1.0, $rapport, 'Le cas retenu doit être une consommation partielle.');

        foreach ($ligne['sub_recipe']['components'] as $composant) {
            self::assertArrayHasKey('qty_brute_used', $composant);
            self::assertArrayHasKey('qty_net_used', $composant);
            self::assertArrayHasKey('line_cost_ht_used', $composant);

            self::assertEqualsWithDelta(
                $composant['qty_brute'] * $rapport,
                $composant['qty_brute_used'],
                0.001,
                sprintf('« %s » : quantité brute mal proratisée.', $composant['name'])
            );

            // Ce qui est consommé ne peut pas dépasser ce qui est produit.
            self::assertLessThanOrEqual(
                $composant['qty_brute'] + 0.001,
                $composant['qty_brute_used'],
                sprintf('« %s » : la part consommée dépasse la recette de base.', $composant['name'])
            );
        }
    }

    /**
     * Le total des composants proratisés ne dépasse jamais le coût de la ligne,
     * et lui reste inférieur ou égal.
     *
     * L'égalité stricte serait fausse, et l'écrire l'a démontré : le coût d'une
     * ligne de sous-recette repose sur son coût de revient complet, main
     * d'œuvre et emballage compris, tandis que les composants ne portent que la
     * matière. Sur une farce, 35,58 € de matière pour une ligne à 47,78 € —
     * l'écart est le travail de fabrication de la farce, pas une erreur.
     *
     * C'est aussi pourquoi la fiche chiffrée affiche désormais cet écart en
     * clair plutôt que de laisser un lecteur additionner sans tomber juste.
     */
    public function testLeTotalDesComposantsNeDepassePasLeCoutDeLaLigne(): void
    {
        $calc  = $this->calculateur();
        $ligne = $this->trouverRecetteAvecSousRecette($calc);

        if ($ligne === null) {
            self::markTestSkipped('Aucune recette de test n\'emploie une sous-recette en quantité partielle.');
        }

        $somme = 0.0;
        foreach ($ligne['sub_recipe']['components'] as $composant) {
            $somme += (float) $composant['line_cost_ht_used'];
        }

        $coutLigne = (float) $ligne['line_cost_ht'];

        self::assertGreaterThan(0.0, $somme, 'Les composants proratisés doivent porter un coût.');
        self::assertLessThanOrEqual(
            $coutLigne + 0.01,
            $somme,
            'La matière proratisée ne peut pas dépasser le coût de revient de la ligne.'
        );
    }
}
