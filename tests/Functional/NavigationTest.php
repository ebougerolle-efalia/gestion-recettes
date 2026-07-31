<?php
namespace App\Tests\Functional;

use App\Repository\RecipeRepository;
use App\Repository\IngredientRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Parcours de toutes les pages en tant qu'éditeur.
 *
 * C'est le test qui manquait : deux requêtes SQLite oubliées lors du passage à
 * PostgreSQL — julianday() et date('now', '-12 months') — ont mis le tableau de
 * bord et la page fournisseurs en erreur 500 en production, sans que rien ne le
 * signale avant qu'un humain ne clique. Une page qui répond 200 ne prouve pas
 * qu'elle est juste, mais une page qui répond 500 est forcément cassée.
 */
class NavigationTest extends WebTestCase
{
    private function connecte(): KernelBrowser
    {
        $client = static::createClient();
        $admin  = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']);

        if (!$admin) {
            self::markTestSkipped('Base de test non peuplée : voir la préparation décrite dans .env.test');
        }

        $client->loginUser($admin);

        return $client;
    }

    public static function pagesFournies(): array
    {
        return [
            'accueil'           => ['/'],
            'tableau de bord'   => ['/tableau-de-bord'],
            'recettes'          => ['/recettes'],
            'ingrédients'       => ['/ingredients'],
            'factures'          => ['/factures'],
            'import de facture' => ['/factures/importer'],
            'fournisseurs'      => ['/fournisseurs'],
            'catégories'        => ['/admin/categories'],
            'familles'          => ['/admin/familles'],
            'utilisateurs'      => ['/admin/utilisateurs'],
            'paramètres'        => ['/admin/parametres'],
        ];
    }

    #[DataProvider('pagesFournies')]
    public function testChaquePageRepond(string $url): void
    {
        $client = $this->connecte();
        $client->request('GET', $url);

        self::assertTrue(
            $client->getResponse()->isSuccessful() || $client->getResponse()->isRedirection(),
            sprintf('%s renvoie %d', $url, $client->getResponse()->getStatusCode())
        );
    }

    public function testLesFichesDeDetailRepondent(): void
    {
        $client = $this->connecte();
        $c      = static::getContainer();

        $recette    = $c->get(RecipeRepository::class)->findOneBy([]);
        $ingredient = $c->get(IngredientRepository::class)->findOneBy([]);
        $fourn      = $c->get(SupplierRepository::class)->findOneBy([]);

        foreach (array_filter([
            $recette    ? '/recettes/' . $recette->getId()      : null,
            $ingredient ? '/ingredients/' . $ingredient->getId(): null,
            $fourn      ? '/fournisseurs/' . $fourn->getId()    : null,
        ]) as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful(sprintf('%s doit répondre', $url));
        }
    }

    public function testLeTableauDeBordAfficheSesIndicateurs(): void
    {
        $client = $this->connecte();
        $crawler = $client->request('GET', '/tableau-de-bord');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Marque réelle médiane', $crawler->html());
        self::assertStringContainsString('Factures à valider', $crawler->html());
    }

    public function testUnVisiteurAnonymeEstRenvoyeVersLaConnexion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/tableau-de-bord');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', $client->getResponse()->headers->get('Location') ?? '');
    }

    public function testLaPageDeConnexionEstPubliqueEtAnnonceLaVersionAlpha(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Alpha', $crawler->html());
        // Receto est la marque d'un concurrent : elle ne doit jamais réapparaître.
        self::assertStringNotContainsStringIgnoringCase('receto', $crawler->html());
    }
}
