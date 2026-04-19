<?php
namespace App\Controller;

use App\Entity\Recipe;
use App\Entity\RecipeLine;
use App\Repository\RecipeRepository;
use App\Repository\IngredientRepository;
use App\Repository\RecipeFamilyRepository;
use App\Service\CostCalculator;
use Sensiolabs\GotenbergBundle\GotenbergPdfInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RecipeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->redirectToRoute('app_recipe_index');
    }

    /** List all recipes */
    #[Route('/recettes', name: 'app_recipe_index')]
    public function index(RecipeRepository $repo, RecipeFamilyRepository $familyRepo): Response
    {
        $recipes = $repo->findBy([], ['name' => 'ASC']);
        $families = $familyRepo->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']);

        return $this->render('recipe/index.html.twig', [
            'recipes' => $recipes,
            'families' => $families,
        ]);
    }

    /** Recipe detail with computed costs */
    #[Route('/recettes/{id}', name: 'app_recipe_show', requirements: ['id' => '\d+'])]
    public function show(int $id, CostCalculator $calc, IngredientRepository $ingRepo, RecipeRepository $recipeRepo): Response
    {
        try {
            $computed = $calc->compute($id);
        } catch (\Throwable $e) {
            $computed = null;
        }

        if (!$computed) {
            throw $this->createNotFoundException('Recette introuvable');
        }

        $ingredients = $ingRepo->findBy([], ['name' => 'ASC']);

        // Available sub-recipes (exclude self + cycles)
        $allRecipes = $recipeRepo->findBy([], ['name' => 'ASC']);
        $availableSubs = [];
        foreach ($allRecipes as $r) {
            if ($r->getId() === $id) continue;
            if (!$calc->wouldCreateCycle($id, $r->getId())) {
                $availableSubs[] = $r;
            }
        }

        return $this->render('recipe/show.html.twig', [
            'computed' => $computed,
            'recipe' => $computed['recipe'],
            'lines' => $computed['lines'],
            'totals' => $computed['totals'],
            'ingredients' => $ingredients,
            'availableSubs' => $availableSubs,
        ]);
    }

    /** Create recipe */
    #[Route('/recettes/creer', name: 'app_recipe_create', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function create(Request $request, EntityManagerInterface $em, CostCalculator $calc): Response
    {
        $recipe = new Recipe();
        $this->hydrateRecipe($recipe, $request);
        $em->persist($recipe);
        $em->flush();

        $calc->updateCache($recipe->getId());

        $this->addFlash('success', "Recette « {$recipe->getName()} » créée.");
        return $this->redirectToRoute('app_recipe_show', ['id' => $recipe->getId()]);
    }

    /** Update recipe params */
    #[Route('/recettes/{id}/modifier', name: 'app_recipe_update', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function update(int $id, Request $request, RecipeRepository $repo, EntityManagerInterface $em, CostCalculator $calc): Response
    {
        $recipe = $repo->find($id);
        if (!$recipe) throw $this->createNotFoundException();

        $this->hydrateRecipe($recipe, $request);
        $em->flush();

        $calc->updateCache($id);

        $this->addFlash('success', 'Recette mise à jour.');
        return $this->redirectToRoute('app_recipe_show', ['id' => $id]);
    }

    /** Delete recipe */
    #[Route('/recettes/{id}/supprimer', name: 'app_recipe_delete', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function delete(int $id, RecipeRepository $repo, EntityManagerInterface $em): Response
    {
        $recipe = $repo->find($id);
        if ($recipe) {
            $em->remove($recipe);
            $em->flush();
            $this->addFlash('success', "Recette supprimée.");
        }
        return $this->redirectToRoute('app_recipe_index');
    }

    /** Add line (ingredient or sub-recipe) */
    #[Route('/recettes/{id}/lignes/ajouter', name: 'app_recipe_add_line', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function addLine(int $id, Request $request, RecipeRepository $recipeRepo, IngredientRepository $ingRepo, EntityManagerInterface $em, CostCalculator $calc): Response
    {
        $recipe = $recipeRepo->find($id);
        if (!$recipe) throw $this->createNotFoundException();

        $line = new RecipeLine();
        $line->setRecipe($recipe);

        $type = $request->request->get('type', 'ingredient');

        if ($type === 'sub_recipe') {
            $subId = (int) $request->request->get('sub_recipe_id');
            $sub = $recipeRepo->find($subId);
            if (!$sub) {
                $this->addFlash('danger', 'Sous-recette introuvable.');
                return $this->redirectToRoute('app_recipe_show', ['id' => $id]);
            }
            if ($calc->wouldCreateCycle($id, $subId)) {
                $this->addFlash('danger', 'Impossible : cela créerait une référence circulaire.');
                return $this->redirectToRoute('app_recipe_show', ['id' => $id]);
            }
            $line->setSubRecipe($sub);
        } else {
            $ingId = (int) $request->request->get('ingredient_id');
            $ing = $ingRepo->find($ingId);
            if (!$ing) {
                $this->addFlash('danger', 'Ingrédient introuvable.');
                return $this->redirectToRoute('app_recipe_show', ['id' => $id]);
            }
            $line->setIngredient($ing);
        }

        $line->setQtyBrute((float) $request->request->get('qty_brute', 0));
        $line->setUnit($request->request->get('unit', 'kg'));
        $line->setLossPercent((float) $request->request->get('loss_percent', 0));
        $line->setYieldPercent((float) $request->request->get('yield_percent', 100));
        $line->setNote($request->request->get('note'));

        $em->persist($line);
        $em->flush();

        $calc->updateCache($id);

        $this->addFlash('success', 'Ligne ajoutée.');
        return $this->redirectToRoute('app_recipe_show', ['id' => $id]);
    }

    /** Remove line */
    #[Route('/recettes/{id}/lignes/{lineId}/supprimer', name: 'app_recipe_remove_line', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function removeLine(int $id, int $lineId, EntityManagerInterface $em, CostCalculator $calc): Response
    {
        $line = $em->getRepository(RecipeLine::class)->find($lineId);
        if ($line && $line->getRecipe()->getId() === $id) {
            $em->remove($line);
            $em->flush();
            $calc->updateCache($id);
        }
        $this->addFlash('success', 'Ligne supprimée.');
        return $this->redirectToRoute('app_recipe_show', ['id' => $id]);
    }

    /** Duplicate recipe */
    #[Route('/recettes/{id}/dupliquer', name: 'app_recipe_duplicate', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function duplicate(int $id, Request $request, RecipeRepository $repo, EntityManagerInterface $em, CostCalculator $calc): Response
    {
        $src = $repo->find($id);
        if (!$src) throw $this->createNotFoundException();

        $newName = $request->request->get('new_name') ?: $src->getName() . ' (copie)';

        $copy = new Recipe();
        $copy->setName($newName);
        $copy->setFamily($src->getFamily());
        $copy->setOutputType($src->getOutputType());
        $copy->setOutputValue($src->getOutputValue());
        $copy->setLossPercent($src->getLossPercent());
        $copy->setYieldPercent($src->getYieldPercent());
        $copy->setProductVatRate($src->getProductVatRate());
        $copy->setLaborCostHt($src->getLaborCostHt());
        $copy->setPackagingCostHt($src->getPackagingCostHt());
        $copy->setPricingMode($src->getPricingMode());
        $copy->setPricingValue($src->getPricingValue());

        $em->persist($copy);
        $em->flush();

        foreach ($src->getLines() as $srcLine) {
            $newLine = new RecipeLine();
            $newLine->setRecipe($copy);
            $newLine->setIngredient($srcLine->getIngredient());
            $newLine->setSubRecipe($srcLine->getSubRecipe());
            $newLine->setQtyBrute($srcLine->getQtyBrute());
            $newLine->setUnit($srcLine->getUnit());
            $newLine->setLossPercent($srcLine->getLossPercent());
            $newLine->setYieldPercent($srcLine->getYieldPercent());
            $newLine->setNote($srcLine->getNote());
            $newLine->setSortOrder($srcLine->getSortOrder());
            $em->persist($newLine);
        }

        $em->flush();
        $calc->updateCache($copy->getId());

        $this->addFlash('success', "Recette dupliquée → « {$newName} »");
        return $this->redirectToRoute('app_recipe_show', ['id' => $copy->getId()]);
    }

    /** Recalculate all costs (AJAX) */
    #[Route('/api/recettes/recalculer', name: 'app_recipe_recalculate', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function recalculate(Request $request, CostCalculator $calc): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $ingredientId = $data['ingredient_id'] ?? null;
        $recipeId = $data['recipe_id'] ?? null;

        if ($recipeId) {
            $calc->updateCache((int) $recipeId);
            return new JsonResponse(['ok' => true, 'updated' => 1]);
        }

        $count = $calc->recalculateAll($ingredientId ? (int) $ingredientId : null);
        return new JsonResponse(['ok' => true, 'updated' => $count]);
    }

    /** PDF: fiche technique complète */
    #[Route('/recettes/{id}/pdf', name: 'app_recipe_pdf')]
    public function pdf(int $id, CostCalculator $calc, GotenbergPdfInterface $gotenberg): Response
    {
        $computed = $calc->compute($id);
        if (!$computed) throw $this->createNotFoundException();

        return $gotenberg->html()
            ->content('recipe/print.html.twig', [
                'recipe' => $computed['recipe'],
                'lines' => $computed['lines'],
                'totals' => $computed['totals'],
            ])
            ->paperSize(8.27, 11.69)       // A4 en pouces
            ->margins(0.59, 0.59, 0.59, 0.59) // ~15mm en pouces
            ->printBackground(true)
            ->preferCssPageSize(false)
            ->fileName('fiche-recette-' . $computed['recipe']->getId() . '.pdf')
            ->generate()
            ->stream();
    }

    /** PDF: fiche labo (sans infos financières) */
    #[Route('/recettes/{id}/pdf-labo', name: 'app_recipe_pdf_lab')]
    public function pdfLab(int $id, CostCalculator $calc, GotenbergPdfInterface $gotenberg): Response
    {
        $computed = $calc->compute($id);
        if (!$computed) throw $this->createNotFoundException();

        return $gotenberg->html()
            ->content('recipe/print_lab.html.twig', [
                'recipe' => $computed['recipe'],
                'lines' => $computed['lines'],
                'totals' => $computed['totals'],
            ])
            ->paperSize(8.27, 11.69)
            ->margins(0.59, 0.59, 0.59, 0.59)
            ->printBackground(true)
            ->preferCssPageSize(false)
            ->fileName('fiche-labo-' . $computed['recipe']->getId() . '.pdf')
            ->generate()
            ->stream();
    }

    private function hydrateRecipe(Recipe $recipe, Request $request): void
    {
        $recipe->setName($request->request->get('name', $recipe->getName()));
        $recipe->setFamily($request->request->get('family', $recipe->getFamily()));
        $recipe->setOutputType($request->request->get('output_type', $recipe->getOutputType()));
        $recipe->setOutputValue((float) $request->request->get('output_value', $recipe->getOutputValue()));
        $recipe->setLossPercent((float) $request->request->get('loss_percent', $recipe->getLossPercent()));
        $recipe->setYieldPercent((float) $request->request->get('yield_percent', $recipe->getYieldPercent()));
        $recipe->setProductVatRate((float) $request->request->get('product_vat_rate', $recipe->getProductVatRate()));
        $recipe->setLaborCostHt((float) $request->request->get('labor_cost_ht', $recipe->getLaborCostHt()));
        $recipe->setPackagingCostHt((float) $request->request->get('packaging_cost_ht', $recipe->getPackagingCostHt()));
        $recipe->setPricingMode($request->request->get('pricing_mode', $recipe->getPricingMode()));
        $recipe->setPricingValue((float) $request->request->get('pricing_value', $recipe->getPricingValue()));
    }
}
