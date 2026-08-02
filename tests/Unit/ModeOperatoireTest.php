<?php
namespace App\Tests\Unit;

use App\Entity\Recipe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Découpage du mode opératoire en étapes.
 *
 * La saisie est un texte libre — on ne sait pas encore ce qu'un artisan y écrit
 * vraiment, et un formulaire trop cadré l'empêcherait d'y mettre ce qui lui
 * sert. Tout le contrat tient donc dans ce découpage : ce qui devient une
 * étape numérotée sur la fiche imprimée, et ce qui n'en devient pas.
 */
class ModeOperatoireTest extends TestCase
{
    public function testUneRecetteSansModeOperatoireNAAucuneEtape(): void
    {
        $recette = new Recipe();

        self::assertNull($recette->getProcess());
        self::assertSame([], $recette->getProcessSteps());
    }

    /**
     * Une saisie vide ou blanche vaut absence : sans cela, la fiche
     * afficherait un titre « Mode opératoire » suivi de rien.
     */
    #[DataProvider('saisiesVides')]
    public function testUneSaisieVideEquivautALAbsence(string $saisie): void
    {
        $recette = new Recipe();
        $recette->setProcess($saisie);

        self::assertNull($recette->getProcess());
        self::assertSame([], $recette->getProcessSteps());
    }

    public static function saisiesVides(): array
    {
        return [
            'chaîne vide'      => [''],
            'espaces'          => ['   '],
            'sauts de ligne'   => ["\n\n\n"],
            'mélange'          => ["  \n \n  "],
        ];
    }

    /**
     * Les lignes vides servent à aérer la saisie — séparer la farce de la
     * cuisson, par exemple. Elles ne doivent pas produire d'étape fantôme :
     * une case à cocher vide au milieu d'une fiche ferait douter d'un oubli.
     */
    public function testLesLignesVidesNeProduisentPasDEtapeFantome(): void
    {
        $recette = new Recipe();
        $recette->setProcess(
            "Désosser l'épaule\n"
            . "Hacher grille 8 mm\n"
            . "\n"
            . "   \n"
            . "Cuire 1 h 15 à 165 °C"
        );

        self::assertSame(
            ["Désosser l'épaule", 'Hacher grille 8 mm', 'Cuire 1 h 15 à 165 °C'],
            $recette->getProcessSteps()
        );
    }

    /**
     * Le texte est saisi depuis un navigateur, qui envoie des fins de ligne
     * Windows. Les traiter comme un seul saut est indispensable, sinon chaque
     * étape serait suivie d'une étape vide.
     */
    public function testLesFinsDeLigneWindowsSontTraiteesCommeUnSeulSaut(): void
    {
        $recette = new Recipe();
        $recette->setProcess("Première étape\r\nDeuxième étape\r\nTroisième étape");

        self::assertSame(
            ['Première étape', 'Deuxième étape', 'Troisième étape'],
            $recette->getProcessSteps()
        );
    }

    /** L'indentation de saisie ne doit pas se retrouver sur la fiche. */
    public function testLesEspacesDeBordSontRetires(): void
    {
        $recette = new Recipe();
        $recette->setProcess("  Désosser l'épaule  \n\tHacher grille 8 mm\t");

        self::assertSame(["Désosser l'épaule", 'Hacher grille 8 mm'], $recette->getProcessSteps());
    }

    /** Le contenu métier — temps, températures, dosages — traverse intact. */
    public function testLesTemperaturesEtDosagesTraversentIntacts(): void
    {
        $recette = new Recipe();
        $recette->setProcess("Saler à 18 g/kg, poivrer\nCuire 1 h 15 à 165 °C, cœur à 72 °C minimum");

        $etapes = $recette->getProcessSteps();

        self::assertCount(2, $etapes);
        self::assertStringContainsString('18 g/kg', $etapes[0]);
        self::assertStringContainsString('72 °C', $etapes[1]);
    }
}
