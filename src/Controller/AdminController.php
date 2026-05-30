<?php
namespace App\Controller;

use App\Entity\{IngredientCategory, RecipeFamily, User};
use App\Repository\{IngredientCategoryRepository, RecipeFamilyRepository, UserRepository, RecipeRepository, IngredientRepository};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    // ==================== DASHBOARD ====================

    #[Route('', name: 'app_admin_index')]
    public function index(EntityManagerInterface $em): Response
    {
        $conn = $em->getConnection();

        // ── KPIs principaux ──────────────────────────────────────────────────
        $totalRecipes       = (int) $conn->fetchOne('SELECT COUNT(*) FROM recipes');
        $recipesWithCost    = (int) $conn->fetchOne('SELECT COUNT(*) FROM recipe_cost_cache');
        $recipesNoCost      = $totalRecipes - $recipesWithCost;
        $totalIngredients   = (int) $conn->fetchOne('SELECT COUNT(*) FROM ingredients');
        $ingsWithPrice      = (int) $conn->fetchOne('SELECT COUNT(DISTINCT ingredient_id) FROM ingredient_prices');
        $ingsWithoutPrice   = $totalIngredients - $ingsWithPrice;
        $avgMargin          = (float) ($conn->fetchOne(
            'SELECT ROUND(AVG(margin_percent), 1) FROM recipe_cost_cache WHERE margin_percent > 0'
        ) ?: 0);

        // ── Alertes : ingrédients sans aucun prix ────────────────────────────
        $ingsNoPrice = $conn->fetchAllAssociative('
            SELECT i.id, i.name, ic.name AS category
            FROM ingredients i
            LEFT JOIN ingredient_categories ic ON ic.id = i.category_id
            LEFT JOIN ingredient_prices ip     ON ip.ingredient_id = i.id
            WHERE ip.id IS NULL
            ORDER BY i.name
        ');

        // ── Alertes : recettes sans lignes (vides) ───────────────────────────
        $recipesEmpty = $conn->fetchAllAssociative('
            SELECT r.id, r.name, r.family
            FROM recipes r
            LEFT JOIN recipe_lines rl ON rl.recipe_id = r.id
            WHERE rl.id IS NULL
            ORDER BY r.name
        ');

        // ── Alertes : prix anciens (> 90 jours) ──────────────────────────────
        $oldPrices = $conn->fetchAllAssociative("
            SELECT i.id, i.name, MAX(ip.effective_date) AS last_date,
                   CAST(julianday('now') - julianday(MAX(ip.effective_date)) AS INTEGER) AS days_old
            FROM ingredients i
            JOIN ingredient_prices ip ON ip.ingredient_id = i.id
            GROUP BY i.id, i.name
            HAVING days_old > 90
            ORDER BY days_old DESC
            LIMIT 10
        ");

        // ── Derniers prix importés ────────────────────────────────────────────
        $lastPrices = $conn->fetchAllAssociative('
            SELECT ip.id, i.id AS ing_id, i.name AS ingredient,
                   ip.price_ht, ip.supplier, ip.effective_date, i.base_unit
            FROM ingredient_prices ip
            JOIN ingredients i ON i.id = ip.ingredient_id
            ORDER BY ip.id DESC
            LIMIT 8
        ');

        // ── Top 6 ingrédients les plus chers (dernier prix connu) ────────────
        $topIngredients = $conn->fetchAllAssociative('
            SELECT i.id, i.name, i.base_unit, ip.price_ht, ip.supplier
            FROM ingredients i
            JOIN ingredient_prices ip ON ip.ingredient_id = i.id
            WHERE ip.id = (
                SELECT id FROM ingredient_prices
                WHERE ingredient_id = i.id
                ORDER BY effective_date DESC, id DESC LIMIT 1
            )
            ORDER BY ip.price_ht DESC
            LIMIT 6
        ');

        // ── Top 6 recettes les plus coûteuses ────────────────────────────────
        $topRecipes = $conn->fetchAllAssociative('
            SELECT r.id, r.name, r.family, r.output_type,
                   rcc.cost_per_output_ht, rcc.advised_sell_ttc, rcc.margin_percent
            FROM recipe_cost_cache rcc
            JOIN recipes r ON r.id = rcc.recipe_id
            ORDER BY rcc.cost_per_output_ht DESC
            LIMIT 6
        ');

        // ── Répartition par famille ───────────────────────────────────────────
        $byFamily = $conn->fetchAllAssociative("
            SELECT
                COALESCE(r.family, 'Sans famille') AS family,
                COUNT(r.id) AS nb,
                COUNT(rcc.recipe_id) AS nb_cost,
                ROUND(AVG(CASE WHEN rcc.margin_percent > 0 THEN rcc.margin_percent END), 1) AS avg_margin
            FROM recipes r
            LEFT JOIN recipe_cost_cache rcc ON rcc.recipe_id = r.id
            GROUP BY r.family
            ORDER BY nb DESC
        ");

        // ── Infos base SQLite ─────────────────────────────────────────────────
        $dbPath  = $this->getParameter('kernel.project_dir') . '/var/data/recettes.db';
        $dbBytes = file_exists($dbPath) ? filesize($dbPath) : 0;
        $dbSize  = $dbBytes > 1_048_576
            ? round($dbBytes / 1_048_576, 1) . ' Mo'
            : round($dbBytes / 1024) . ' Ko';

        // Mappings factures mémorisés (si la table existe)
        try {
            $mappingCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM invoice_ingredient_mappings');
        } catch (\Throwable) {
            $mappingCount = 0;
        }

        return $this->render('admin/index.html.twig', [
            'kpis' => [
                'total_recipes'     => $totalRecipes,
                'recipes_with_cost' => $recipesWithCost,
                'recipes_no_cost'   => $recipesNoCost,
                'total_ingredients' => $totalIngredients,
                'ings_with_price'   => $ingsWithPrice,
                'ings_no_price'     => $ingsWithoutPrice,
                'avg_margin'        => $avgMargin,
            ],
            'alerts' => [
                'no_price'  => $ingsNoPrice,
                'empty'     => $recipesEmpty,
                'old_prices'=> $oldPrices,
            ],
            'last_prices'    => $lastPrices,
            'top_ingredients'=> $topIngredients,
            'top_recipes'    => $topRecipes,
            'by_family'      => $byFamily,
            'db_size'        => $dbSize,
            'mapping_count'  => $mappingCount,
        ]);
    }

    // ==================== CATEGORIES ====================

    #[Route('/categories', name: 'app_admin_categories')]
    public function categories(IngredientCategoryRepository $repo): Response
    {
        return $this->render('admin/categories.html.twig', [
            'categories' => $repo->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']),
        ]);
    }

    #[Route('/categories/creer', name: 'app_admin_category_create', methods: ['POST'])]
    public function categoryCreate(Request $request, EntityManagerInterface $em): Response
    {
        $cat = new IngredientCategory();
        $cat->setName($request->request->get('name', ''));
        $cat->setSortOrder((int) $request->request->get('sort_order', 0));
        $em->persist($cat);
        $em->flush();
        $this->addFlash('success', "Catégorie « {$cat->getName()} » créée.");
        return $this->redirectToRoute('app_admin_categories');
    }

    #[Route('/categories/{id}/modifier', name: 'app_admin_category_update', methods: ['POST'])]
    public function categoryUpdate(int $id, Request $request, IngredientCategoryRepository $repo, EntityManagerInterface $em): Response
    {
        $cat = $repo->find($id);
        if (!$cat) throw $this->createNotFoundException();
        $cat->setName($request->request->get('name', $cat->getName()));
        $cat->setSortOrder((int) $request->request->get('sort_order', $cat->getSortOrder()));
        $em->flush();
        $this->addFlash('success', 'Catégorie modifiée.');
        return $this->redirectToRoute('app_admin_categories');
    }

    #[Route('/categories/{id}/supprimer', name: 'app_admin_category_delete', methods: ['POST'])]
    public function categoryDelete(int $id, IngredientCategoryRepository $repo, EntityManagerInterface $em): Response
    {
        $cat = $repo->find($id);
        if ($cat) {
            if (count($cat->getIngredients()) > 0) {
                $this->addFlash('danger', "Impossible : {$cat->getIngredients()->count()} ingrédient(s) utilisent cette catégorie.");
                return $this->redirectToRoute('app_admin_categories');
            }
            $em->remove($cat);
            $em->flush();
            $this->addFlash('success', 'Catégorie supprimée.');
        }
        return $this->redirectToRoute('app_admin_categories');
    }

    // ==================== FAMILIES ====================

    #[Route('/familles', name: 'app_admin_families')]
    public function families(RecipeFamilyRepository $repo): Response
    {
        return $this->render('admin/families.html.twig', [
            'families' => $repo->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']),
        ]);
    }

    #[Route('/familles/creer', name: 'app_admin_family_create', methods: ['POST'])]
    public function familyCreate(Request $request, EntityManagerInterface $em): Response
    {
        $fam = new RecipeFamily();
        $fam->setName($request->request->get('name', ''));
        $fam->setSortOrder((int) $request->request->get('sort_order', 0));
        $em->persist($fam);
        $em->flush();
        $this->addFlash('success', "Famille « {$fam->getName()} » créée.");
        return $this->redirectToRoute('app_admin_families');
    }

    #[Route('/familles/{id}/modifier', name: 'app_admin_family_update', methods: ['POST'])]
    public function familyUpdate(int $id, Request $request, RecipeFamilyRepository $repo, EntityManagerInterface $em): Response
    {
        $fam = $repo->find($id);
        if (!$fam) throw $this->createNotFoundException();
        $fam->setName($request->request->get('name', $fam->getName()));
        $fam->setSortOrder((int) $request->request->get('sort_order', $fam->getSortOrder()));
        $em->flush();
        $this->addFlash('success', 'Famille modifiée.');
        return $this->redirectToRoute('app_admin_families');
    }

    #[Route('/familles/{id}/supprimer', name: 'app_admin_family_delete', methods: ['POST'])]
    public function familyDelete(int $id, RecipeFamilyRepository $repo, EntityManagerInterface $em, RecipeRepository $recipeRepo): Response
    {
        $fam = $repo->find($id);
        if ($fam) {
            $usage = $recipeRepo->count(['family' => $fam->getName()]);
            if ($usage > 0) {
                $this->addFlash('danger', "Impossible : $usage recette(s) utilisent cette famille.");
                return $this->redirectToRoute('app_admin_families');
            }
            $em->remove($fam);
            $em->flush();
            $this->addFlash('success', 'Famille supprimée.');
        }
        return $this->redirectToRoute('app_admin_families');
    }

    // ==================== USERS ====================

    #[Route('/utilisateurs', name: 'app_admin_users')]
    public function users(UserRepository $repo): Response
    {
        return $this->render('admin/users.html.twig', [
            'users' => $repo->findBy([], ['username' => 'ASC']),
        ]);
    }

    #[Route('/utilisateurs/creer', name: 'app_admin_user_create', methods: ['POST'])]
    public function userCreate(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $user = new User();
        $user->setUsername($request->request->get('username', ''));
        $user->setRole($request->request->get('role', 'editor'));
        $password = $request->request->get('password', '');
        if (strlen($password) < 6) {
            $this->addFlash('danger', 'Mot de passe trop court (min 6).');
            return $this->redirectToRoute('app_admin_users');
        }
        $user->setPassword($hasher->hashPassword($user, $password));
        $em->persist($user);
        $em->flush();
        $this->addFlash('success', "Utilisateur « {$user->getUsername()} » créé.");
        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/utilisateurs/{id}/modifier', name: 'app_admin_user_update', methods: ['POST'])]
    public function userUpdate(int $id, Request $request, UserRepository $repo, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $user = $repo->find($id);
        if (!$user) throw $this->createNotFoundException();
        $user->setRole($request->request->get('role', $user->getRole()));
        $user->setIsActive($request->request->has('is_active'));
        $newPass = $request->request->get('new_password', '');
        if ($newPass !== '') {
            if (strlen($newPass) < 6) {
                $this->addFlash('danger', 'Mot de passe trop court.');
                return $this->redirectToRoute('app_admin_users');
            }
            $user->setPassword($hasher->hashPassword($user, $newPass));
        }
        $em->flush();
        $this->addFlash('success', "Utilisateur « {$user->getUsername()} » modifié.");
        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/utilisateurs/{id}/supprimer', name: 'app_admin_user_delete', methods: ['POST'])]
    public function userDelete(int $id, UserRepository $repo, EntityManagerInterface $em): Response
    {
        $user = $repo->find($id);
        if ($user) {
            if ($user->getId() === $this->getUser()->getId()) {
                $this->addFlash('danger', 'Impossible de supprimer son propre compte.');
                return $this->redirectToRoute('app_admin_users');
            }
            $em->remove($user);
            $em->flush();
            $this->addFlash('success', 'Utilisateur supprimé.');
        }
        return $this->redirectToRoute('app_admin_users');
    }

    // ==================== SEED ====================

    #[Route('/seed', name: 'app_admin_seed', methods: ['POST'])]
    public function seed(EntityManagerInterface $em, UserPasswordHasherInterface $hasher, IngredientCategoryRepository $catRepo, RecipeFamilyRepository $famRepo): Response
    {
        $defaultCats = ['Viande' => 1, 'Epices' => 2, 'Emballage' => 3, 'Autres' => 99];
        foreach ($defaultCats as $name => $order) {
            if (!$catRepo->findOneBy(['name' => $name])) {
                $c = new IngredientCategory();
                $c->setName($name);
                $c->setSortOrder($order);
                $em->persist($c);
            }
        }
        $defaultFams = ['Terrine' => 1, 'Pâté' => 2, 'Saucisse' => 3, 'Jambon' => 4, 'Cuit' => 5, 'Sec' => 6, 'Autres' => 99];
        foreach ($defaultFams as $name => $order) {
            if (!$famRepo->findOneBy(['name' => $name])) {
                $f = new RecipeFamily();
                $f->setName($name);
                $f->setSortOrder($order);
                $em->persist($f);
            }
        }
        $em->flush();
        $this->addFlash('success', 'Données initiales créées.');
        return $this->redirectToRoute('app_admin_index');
    }
}
