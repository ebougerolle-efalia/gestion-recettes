<?php
namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\IngredientPrice;
use App\Repository\IngredientRepository;
use App\Repository\IngredientCategoryRepository;
use App\Repository\CiqualFoodRepository;
use App\Service\CiqualMatcher;
use App\Service\CostCalculator;
use Sensiolabs\GotenbergBundle\GotenbergPdfInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class IngredientController extends AbstractController
{
    /**
     * Mercuriale au format PDF : la liste des ingrédients avec leur dernier prix,
     * son fournisseur, sa date et son évolution.
     *
     * Destinée à être emportée en rendez-vous fournisseur ou chez le comptable —
     * d'où le tri par catégorie et la mise en évidence des hausses, qui donnent
     * l'ordre du jour de la négociation.
     *
     * Réservée aux éditeurs : elle ne contient que des prix d'achat.
     */
    // Pas d'extension .pdf dans l'URL : le serveur de développement PHP
    // intercepterait la requête pour chercher un fichier sur disque. Le nom du
    // fichier téléchargé est porté par l'en-tête Content-Disposition.
    #[Route('/ingredients/mercuriale', name: 'app_ingredient_mercuriale_pdf')]
    #[IsGranted('ROLE_EDITOR')]
    public function mercurialePdf(IngredientRepository $repo, GotenbergPdfInterface $gotenberg): Response
    {
        $rows = $repo->findMercuriale(30);

        // Regroupement par catégorie fait ici plutôt qu'en Twig : le gabarit
        // reste une mise en page, sans logique de tri.
        $byCategory = [];
        foreach ($rows as $row) {
            $byCategory[$row['category']][] = $row;
        }

        return $gotenberg->html()
            ->content('ingredient/mercuriale.html.twig', [
                'byCategory' => $byCategory,
                'rows'       => $rows,
                'days'       => 30,
            ])
            ->paperSize(8.27, 11.69)
            ->margins(0.55, 0.55, 0.55, 0.55)
            ->printBackground(true)
            ->preferCssPageSize(false)
            ->fileName('mercuriale-' . date('Y-m-d') . '.pdf')
            ->generate()
            ->stream();
    }

    #[Route('/ingredients', name: 'app_ingredient_index')]
    public function index(IngredientRepository $repo, IngredientCategoryRepository $catRepo): Response
    {
        $ingredients = $repo->findBy([], ['name' => 'ASC']);
        $categories = $catRepo->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']);

        return $this->render('ingredient/index.html.twig', [
            'ingredients' => $ingredients,
            'categories' => $categories,
        ]);
    }

    #[Route('/api/ciqual/search', name: 'app_ciqual_search', methods: ['GET'])]
    public function ciqualSearch(Request $request, CiqualFoodRepository $repo): JsonResponse
    {
        $q = (string) $request->query->get('q', '');
        $results = array_map(
            fn($f) => ['code' => $f->getCode(), 'nom' => $f->getNom(), 'groupe' => $f->getGroupe()],
            $repo->search($q, 20)
        );
        return $this->json($results);
    }

    /**
     * Suggestion automatique d'aliments Ciqual pour un libellé d'ingrédient.
     * Renvoie les meilleurs candidats avec leur score : le choix reste à
     * l'utilisateur, aucune valeur nutritionnelle n'est appliquée ici.
     */
    #[Route('/api/ciqual/suggest', name: 'app_ciqual_suggest', methods: ['GET'])]
    public function ciqualSuggest(Request $request, CiqualMatcher $matcher, IngredientCategoryRepository $catRepo): JsonResponse
    {
        $name = trim((string) $request->query->get('name', ''));
        if ($name === '') {
            return $this->json([]);
        }

        $category = null;
        if ($catId = (int) $request->query->get('category_id')) {
            $category = $catRepo->find($catId)?->getName();
        }

        $results = array_map(
            fn (array $r) => [
                'code'   => $r['food']->getCode(),
                'nom'    => $r['food']->getNom(),
                'groupe' => $r['food']->getGroupe(),
                'score'  => $r['score'],
            ],
            $matcher->suggest($name, $category, 5)
        );

        return $this->json($results);
    }

    /** Valide un rattachement Ciqual posé automatiquement. */
    #[Route('/ingredients/{id}/ciqual/confirmer', name: 'app_ingredient_ciqual_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function confirmCiqual(int $id, IngredientRepository $repo, EntityManagerInterface $em): Response
    {
        $ing = $repo->find($id);
        if (!$ing) throw $this->createNotFoundException();

        $ing->confirmCiqual();
        $em->flush();
        $this->addFlash('success', 'Rattachement Ciqual confirmé.');

        return $this->redirectToRoute('app_ingredient_show', ['id' => $id]);
    }

    #[Route('/ingredients/{id}', name: 'app_ingredient_show', requirements: ['id' => '\d+'])]
    public function show(int $id, IngredientRepository $repo, CiqualFoodRepository $ciqualRepo): Response
    {
        $ingredient = $repo->find($id);
        if (!$ingredient) throw $this->createNotFoundException();

        $ciqualName = null;
        if ($ingredient->getCiqualCode()) {
            $food = $ciqualRepo->find($ingredient->getCiqualCode());
            $ciqualName = $food?->getNom();
        }

        return $this->render('ingredient/show.html.twig', [
            'ingredient' => $ingredient,
            'ciqualName' => $ciqualName,
        ]);
    }

    #[Route('/ingredients/creer', name: 'app_ingredient_create', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function create(Request $request, IngredientCategoryRepository $catRepo, EntityManagerInterface $em): Response
    {
        $ing = new Ingredient();
        $ing->setName($request->request->get('name', ''));
        $ing->setBaseUnit($request->request->get('base_unit', 'kg'));
        $ing->setVatRate((float) $request->request->get('vat_rate', 5.5));
        $ing->setDefaultSupplier($request->request->get('default_supplier'));
        $ing->setAllergens($this->cleanAllergens($request->request->all('allergens')));
        $ing->setTraces($this->cleanAllergens($request->request->all('traces')));
        $ing->setCiqualCode($request->request->get('ciqual_code'));
        $uw = $request->request->get('unit_weight_g');
        $ing->setUnitWeightG($uw !== null && $uw !== '' ? (float) str_replace(',', '.', $uw) : null);

        $catId = (int) $request->request->get('category_id');
        $cat = $catRepo->find($catId);
        if (!$cat) {
            $cat = $catRepo->findOneBy(['name' => 'Autres']);
        }
        $ing->setCategory($cat);

        $em->persist($ing);

        // Initial price
        $priceHt = $request->request->get('initial_price_ht');
        if ($priceHt !== null && $priceHt !== '') {
            $price = new IngredientPrice();
            $price->setIngredient($ing);
            $price->setPriceHt((float) $priceHt);
            $price->setSupplier($request->request->get('default_supplier'));
            $em->persist($price);
        }

        $em->flush();
        $this->addFlash('success', "Ingrédient « {$ing->getName()} » créé.");
        return $this->redirectToRoute('app_ingredient_index');
    }

    #[Route('/ingredients/{id}/modifier', name: 'app_ingredient_update', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function update(int $id, Request $request, IngredientRepository $repo, IngredientCategoryRepository $catRepo, EntityManagerInterface $em): Response
    {
        $ing = $repo->find($id);
        if (!$ing) throw $this->createNotFoundException();

        $ing->setName($request->request->get('name', $ing->getName()));
        $ing->setBaseUnit($request->request->get('base_unit', $ing->getBaseUnit()));
        $ing->setVatRate((float) $request->request->get('vat_rate', $ing->getVatRate()));
        $ing->setDefaultSupplier($request->request->get('default_supplier'));
        $ing->setAllergens($this->cleanAllergens($request->request->all('allergens')));
        $ing->setTraces($this->cleanAllergens($request->request->all('traces')));

        // Un code Ciqual choisi à la main lève le marqueur « auto ». Renvoyer le
        // même code sans y toucher ne vaut pas validation : elle passe par le
        // bouton de confirmation dédié.
        $submittedCiqual = $request->request->get('ciqual_code');
        if ($submittedCiqual !== $ing->getCiqualCode()) {
            $ing->setCiqualCode($submittedCiqual);
            $ing->setCiqualAuto(false);
        }

        $uw = $request->request->get('unit_weight_g');
        $ing->setUnitWeightG($uw !== null && $uw !== '' ? (float) str_replace(',', '.', $uw) : null);

        $catId = (int) $request->request->get('category_id');
        $cat = $catRepo->find($catId);
        if ($cat) $ing->setCategory($cat);

        $em->flush();
        $this->addFlash('success', 'Ingrédient mis à jour.');
        return $this->redirectToRoute('app_ingredient_show', ['id' => $id]);
    }

    #[Route('/ingredients/{id}/supprimer', name: 'app_ingredient_delete', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function delete(int $id, IngredientRepository $repo, EntityManagerInterface $em): Response
    {
        $ing = $repo->find($id);
        if ($ing) {
            try {
                $em->remove($ing);
                $em->flush();
                $this->addFlash('success', 'Ingrédient supprimé.');
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Impossible de supprimer : cet ingrédient est utilisé dans des recettes.');
            }
        }
        return $this->redirectToRoute('app_ingredient_index');
    }

    /** Add new price entry */
    #[Route('/ingredients/{id}/prix/ajouter', name: 'app_ingredient_add_price', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function addPrice(int $id, Request $request, IngredientRepository $repo, EntityManagerInterface $em, CostCalculator $calc): Response
    {
        $ing = $repo->find($id);
        if (!$ing) throw $this->createNotFoundException();

        $price = new IngredientPrice();
        $price->setIngredient($ing);
        $price->setPriceHt((float) $request->request->get('price_ht', 0));
        $price->setSupplier($request->request->get('supplier'));

        $dateStr = $request->request->get('effective_date');
        if ($dateStr) {
            $price->setEffectiveDate(new \DateTime($dateStr));
        }

        $em->persist($price);
        $em->flush();

        // Recalculate recipes using this ingredient
        $calc->recalculateAll($id);

        $this->addFlash('success', 'Prix ajouté.');
        return $this->redirectToRoute('app_ingredient_show', ['id' => $id]);
    }

    /** Ne conserve que les codes allergènes valides (les 14 réglementaires). */
    private function cleanAllergens(array $codes): array
    {
        $valid = array_keys(\App\Twig\BoutiqueExtension::ALLERGENES);
        return array_values(array_intersect($valid, $codes));
    }
}