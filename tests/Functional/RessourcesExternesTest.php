<?php
namespace App\Tests\Functional;

use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Aucune page ne doit appeler un serveur tiers.
 *
 * Les polices et les icônes étaient servies par Google et Cloudflare : l'adresse
 * IP de chaque utilisateur partait vers deux entreprises américaines à chaque
 * chargement, et un script tiers s'exécutait avec accès complet au DOM d'une
 * session affichant marges et prix fournisseurs.
 *
 * Rien n'empêcherait de recoller un lien de CDN : c'est une ligne, elle marche
 * tout de suite, et la régression est invisible à l'œil. Ce test est le seul
 * garde-fou.
 */
class RessourcesExternesTest extends WebTestCase
{
    /**
     * Hôtes tolérés — vide, et cela doit le rester.
     *
     * Tailwind était le dernier appel externe. Il est désormais compilé
     * localement (voir tailwind.config.js), donc plus aucune page ne contacte
     * de serveur tiers. Ajouter une entrée ici revient à rouvrir la brèche
     * fermée en connaissance de cause : ne le faire qu'avec une raison écrite.
     */
    private const TOLERES = [];

    public static function pagesFournies(): array
    {
        return [
            'connexion'     => ['/login', false],
            'recettes'      => ['/recettes', true],
            'tableau de bord' => ['/tableau-de-bord', true],
            'factures'      => ['/factures', true],
            'ingrédients'   => ['/ingredients', true],
        ];
    }

    #[DataProvider('pagesFournies')]
    public function testAucunAppelAUnServeurTiers(string $url, bool $authentifie): void
    {
        $client = static::createClient();

        if ($authentifie) {
            $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']);

            if (!$admin) {
                self::markTestSkipped('Base de test non peuplée : voir la préparation décrite dans .env.test');
            }

            $client->loginUser($admin);
        }

        $client->request('GET', $url);
        $html = $client->getResponse()->getContent();

        // Toutes les URL absolues du document, quel que soit l'attribut porteur.
        preg_match_all('#https?://([a-z0-9.-]+)#i', (string) $html, $trouvees);

        $tiers = array_values(array_unique(array_filter(
            $trouvees[1],
            fn (string $hote) => !in_array(strtolower($hote), self::TOLERES, true)
                // Les espaces de noms XML et les liens documentaires ne sont pas
                // des ressources chargées par le navigateur.
                && !in_array(strtolower($hote), ['www.w3.org', 'www.cnil.fr', 'fontawesome.com'], true)
        )));

        self::assertSame([], $tiers, sprintf(
            '%s appelle %s. Héberger la ressource dans public/assets/vendor — voir son LISEZMOI.',
            $url,
            implode(', ', $tiers)
        ));
    }

    /**
     * Les fichiers hébergés doivent exister : un chemin cassé remplacerait un
     * appel externe par une page sans mise en forme, ce qui n'est pas mieux.
     */
    public function testLesRessourcesHebergeesSontPresentes(): void
    {
        $racine = \dirname(__DIR__, 2) . '/public/assets/vendor';

        foreach ([
            'inter/inter.css',
            'inter/inter-latin.woff2',
            'inter/inter-latin-ext.woff2',
            'fontawesome/css/fontawesome.min.css',
            'fontawesome/css/solid.min.css',
            'fontawesome/webfonts/fa-solid-900.woff2',
            'chartjs/chart.umd.min.js',
        ] as $fichier) {
            self::assertFileExists($racine . '/' . $fichier);
            self::assertGreaterThan(400, filesize($racine . '/' . $fichier), $fichier . ' semble tronqué.');
        }
    }

    /**
     * La feuille de style des icônes ne doit référencer que des polices
     * réellement embarquées. Le .ttf d'origine a été retiré : le laisser
     * provoquerait une requête en 404 sur les navigateurs sans woff2.
     */
    public function testLaFeuilleDIconesNeReferenceQueDesPolicesPresentes(): void
    {
        $racine = \dirname(__DIR__, 2) . '/public/assets/vendor/fontawesome';
        $css    = (string) file_get_contents($racine . '/css/solid.min.css');

        preg_match_all('#url\(([^)]+)\)#', $css, $urls);

        foreach ($urls[1] as $url) {
            $chemin = $racine . '/css/' . trim($url, '"\'');
            self::assertFileExists($chemin, sprintf('La feuille référence %s, absent du dépôt.', $url));
        }
    }
}
