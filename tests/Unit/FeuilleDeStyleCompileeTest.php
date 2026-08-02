<?php
namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * La feuille compilée doit correspondre aux templates.
 *
 * Depuis le retrait du CDN, le CSS est produit par Tailwind CLI et **versionné**
 * — le déploiement n'a besoin ni de Node ni de npm. La contrepartie est qu'une
 * classe ajoutée dans un template n'existe pas tant qu'on n'a pas relancé
 * « npm run build:css ».
 *
 * L'oubli ne casse rien de visible côté serveur : la page répond 200, les tests
 * fonctionnels passent, et seul un humain devant son navigateur voit l'élément
 * sans mise en forme. C'est exactement le scénario qui a coûté une heure avec
 * les 403 sur /assets/vendor/. Ce test est le garde-fou.
 *
 * Il ne recompile pas et ne compare pas des empreintes — un CSS minifié change
 * à chaque version de Tailwind. Il vérifie ce qui compte : que chaque classe
 * écrite dans un template ait bien produit un sélecteur.
 */
class FeuilleDeStyleCompileeTest extends TestCase
{
    private const CSS = __DIR__ . '/../../public/assets/app.css';

    /**
     * Classes qu'on ne peut pas retrouver dans le CSS sans faux positifs.
     *
     * Les utilitaires de disposition purs (flex, grid…) et les préfixes d'état
     * seuls n'ont pas de sélecteur propre, ou sont générés par Tailwind quoi
     * qu'il arrive. On se concentre sur les valeurs arbitraires, qui sont les
     * seules réellement à risque : ce sont elles que la compilation doit avoir
     * rencontrées dans un template pour exister.
     */
    public function testChaqueValeurArbitraireDesTemplatesExisteDansLeCssCompile(): void
    {
        self::assertFileExists(self::CSS, 'Feuille compilée absente : lancer « npm run build:css ».');

        $css = (string) file_get_contents(self::CSS);

        $manquantes = [];

        foreach ($this->valeursArbitrairesDesTemplates() as $classe) {
            if (!str_contains($css, $this->versSelecteurCss($classe))) {
                $manquantes[] = $classe;
            }
        }

        self::assertSame([], $manquantes, sprintf(
            "Ces classes sont écrites dans un template mais absentes du CSS compilé :\n  %s\n\n"
            . "La feuille n'a pas été régénérée après la dernière modification.\n"
            . "Corriger avec : npm run build:css",
            implode("\n  ", $manquantes)
        ));
    }

    /**
     * Aucune classe ne doit être assemblée à l'exécution.
     *
     * Tailwind compile en LISANT les fichiers : il ne reconnaît que des chaînes
     * complètes. Un « text-{{ actif ? 'white' : 'gray-400' }} » ne produit donc
     * aucun sélecteur, et l'élément s'affiche sans mise en forme.
     *
     * Le CDN, lui, fabriquait ces classes à la volée en lisant le DOM : le
     * piège n'existait pas avant la compilation. Deux occurrences dormaient
     * dans les écrans de facture ; elles ne fonctionnaient que par accident,
     * une autre page employant par hasard la même classe.
     *
     * Écrire le nom entier dans chaque branche :
     *     {{ actif ? 'text-white' : 'text-ardoise-faible' }}
     */
    public function testAucuneClasseNEstAssembleeALExecution(): void
    {
        $fautives = [];

        foreach ($this->fichiersTwig() as $fichier) {
            // Les commentaires Twig sont retirés : ils citent volontairement le
            // motif interdit pour l'expliquer, et le test se signalerait
            // lui-même — ce qu'il a fait à la première exécution.
            $contenu = preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($fichier->getPathname())) ?? '';

            // Un préfixe d'utilitaire collé à une expression Twig.
            if (preg_match_all(
                '/\b(?:text|bg|border|ring|divide|from|to|via|fill|stroke|shadow)-\{\{[^}]*\}\}/',
                $contenu,
                $trouvees
            )) {
                foreach ($trouvees[0] as $extrait) {
                    $fautives[] = sprintf('%s : %s', $fichier->getFilename(), $extrait);
                }
            }
        }

        self::assertSame([], $fautives, sprintf(
            "Ces classes sont assemblées à l'exécution et n'existeront pas dans le CSS compilé :\n  %s\n\n"
            . "Écrire le nom entier dans chaque branche du ternaire.",
            implode("\n  ", $fautives)
        ));
    }

    /** La feuille ne doit pas être vide ni tronquée par une compilation interrompue. */
    public function testLaFeuilleCompileeNEstPasTronquee(): void
    {
        self::assertFileExists(self::CSS);
        self::assertGreaterThan(
            10_000,
            filesize(self::CSS),
            'Feuille anormalement petite : compilation probablement incomplète.'
        );
    }

    /**
     * Valeurs arbitraires réellement écrites dans un attribut class.
     *
     * Lecture restreinte aux attributs class= : ailleurs, une expression Twig
     * comme « lines[0] » ressemble à s'y méprendre à une valeur arbitraire.
     *
     * @return list<string>
     */
    private function valeursArbitrairesDesTemplates(): array
    {
        $trouvees = [];

        foreach ($this->fichiersTwig() as $fichier) {
            $contenu = (string) file_get_contents($fichier->getPathname());

            preg_match_all('/class="([^"]*)"/', $contenu, $attributs);

            foreach ($attributs[1] as $attribut) {
                foreach (preg_split('/\s+/', $attribut) ?: [] as $classe) {
                    // Les apostrophes viennent des ternaires Twig : class="{{ x ? 'a' : 'b' }}"
                    $classe = trim($classe, "'\"");

                    if (str_contains($classe, '[') && str_contains($classe, ']')) {
                        $trouvees[$classe] = true;
                    }
                }
            }
        }

        return array_keys($trouvees);
    }

    /** @return list<\SplFileInfo> */
    private function fichiersTwig(): array
    {
        $liste = [];

        $parcours = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../templates')
        );

        foreach ($parcours as $fichier) {
            if ($fichier->isFile() && str_ends_with($fichier->getFilename(), '.twig')) {
                $liste[] = $fichier;
            }
        }

        return $liste;
    }

    /**
     * Nom de classe tel qu'il apparaît, échappé, dans la feuille produite.
     *
     * CSS impose d'échapper par une contre-oblique tout caractère qui n'est pas
     * admis dans un identifiant. Tailwind encode en plus la virgule en « \2c »
     * suivi d'une espace — d'où le traitement séparé.
     */
    private function versSelecteurCss(string $classe): string
    {
        $selecteur = '';

        foreach (mb_str_split($classe) as $caractere) {
            $selecteur .= match ($caractere) {
                ','     => '\\2c ',
                '[', ']', '#', '.', '%', '/', ':', '(', ')', '!', '@' => '\\' . $caractere,
                default => $caractere,
            };
        }

        return '.' . $selecteur;
    }
}
