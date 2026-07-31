<?php
namespace App\Tests\Unit;

use App\Service\TextMatching;
use PHPUnit\Framework\TestCase;

/**
 * Verrouille le rapprochement des libellés fournisseurs.
 *
 * Chaque cas ci-dessous vient d'une facture réelle du jeu d'exemple : ce sont
 * les erreurs qu'a produites l'ancien similar_text, ou les correspondances
 * qu'il ne fallait pas perdre en le remplaçant.
 */
class TextMatchingTest extends TestCase
{
    private const STOPWORDS = [
        'de', 'du', 'des', 'a', 'au', 'aux', 'le', 'la', 'les',
        'frais', 'fraiche', 'choix', '1er', 'sac', 'colis', 'carton', 'seau',
        'plaquette', 'bobine', 'lot', 'alimentaire', 'kg', 'g', 'l', 'mg', 'ref',
    ];

    private function match(string $libelle, string $ingredient): float
    {
        return TextMatching::score(
            TextMatching::tokenize($libelle, self::STOPWORDS),
            TextMatching::tokenize($ingredient, self::STOPWORDS)
        );
    }

    public function testAccentsEtCasseNEmpechentPasLaCorrespondance(): void
    {
        self::assertSame('epaule de porc desossee', TextMatching::normalize('Épaule de porc désossée'));
        self::assertSame('oeuf plein air', TextMatching::normalize('Œuf plein air'));
    }

    public function testLEpauleNeDoitPasEtreConfondueAvecLeFoie(): void
    {
        $epaule = $this->match('Epaule porc desossee 1er choix', 'Épaule de porc désossée');
        $foie   = $this->match('Epaule porc desossee 1er choix', 'Foie de porc');

        self::assertGreaterThan(0.9, $epaule, "L'épaule doit être reconnue sans ambiguïté");
        self::assertGreaterThan($foie + 0.3, $epaule, "L'écart avec le foie doit être franc");
    }

    public function testLesFraisDePortNeSontPasDeLaPoitrineDePorc(): void
    {
        // « port » ne doit jamais concorder avec « porc » : c'est le faux
        // positif qui a motivé la règle de concordance stricte.
        self::assertSame(0.0, $this->match('Participation frais de port', 'Poitrine de porc'));
        self::assertSame(0.0, $this->match('Participation frais de port', 'Porto rouge'));
    }

    public function testLeConditionnementNEmpechePasLaReconnaissance(): void
    {
        $cas = [
            ['Creme liquide 35% MG - colis de 6 x 1 L', 'Crème liquide 35% MG'],
            ['Farine de tradition T65 - sac 25 kg',      'Farine de tradition T65'],
            ['Mascarpone seau 500 g',                    'Mascarpone'],
            ['POIVRE NOIR MOULU REF/PNM500',             'Poivre noir moulu'],
            ['Sel nitrite 0,6% seau 5 kg',               'Sel nitrité 0,6%'],
            ['Beurre doux 82% MG plaquette',             'Beurre doux 82% MG'],
        ];

        foreach ($cas as [$libelle, $ingredient]) {
            self::assertGreaterThanOrEqual(
                0.8,
                $this->match($libelle, $ingredient),
                "« $libelle » doit être rapproché de « $ingredient »"
            );
        }
    }

    public function testUnPluriemNEmpechePasLaConcordanceMaisUnPrefixeOui(): void
    {
        self::assertTrue(TextMatching::tokensMatch('epice', 'epices'));
        self::assertTrue(TextMatching::tokensMatch('oeuf', 'oeufs'));
        self::assertFalse(TextMatching::tokensMatch('lait', 'laitue'));
        self::assertFalse(TextMatching::tokensMatch('port', 'porc'));
    }

    public function testLesNombresNusSontIgnores(): void
    {
        // Un « 500 » de conditionnement ne dit rien du produit, mais un code
        // alphanumérique comme T65 doit être conservé.
        self::assertSame(['mascarpone'], TextMatching::tokenize('Mascarpone 500', self::STOPWORDS));
        self::assertContains('t65', TextMatching::tokenize('Farine T65', self::STOPWORDS));
    }
}
