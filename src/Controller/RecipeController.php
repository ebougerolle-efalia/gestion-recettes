<?php
namespace App\Controller;

use App\Entity\Recipe;
use App\Entity\RecipeLine;
use App\Repository\RecipeRepository;
use App\Repository\IngredientRepository;
use App\Repository\RecipeFamilyRepository;
use App\Repository\ConfigBoutiqueRepository;
use App\Service\CostCalculator;
use Sensiolabs\GotenbergBundle\GotenbergPdfInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RecipeController extends AbstractController
{
    /**
     * Page d'accueil, et cible de la redirection après connexion.
     *
     * Le tableau de bord porte des montants et des marges : un profil lecteur,
     * qui n'a jamais accès aux données financières, est envoyé sur la liste des
     * recettes. Sans cette distinction, il obtiendrait un 403 en se connectant.
     */
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->redirectToRoute(
            $this->isGranted('ROLE_EDITOR') ? 'app_dashboard' : 'app_recipe_index'
        );
    }

    #[Route('/recettes', name: 'app_recipe_index')]
    public function index(RecipeRepository $repo, RecipeFamilyRepository $familyRepo): Response
    {
        // Liste seule : les indicateurs et alertes vivent sur le tableau de bord,
        // pour ne pas entretenir deux tableaux de bord concurrents.
        return $this->render('recipe/index.html.twig', [
            'recipes'  => $repo->findBy([], ['name' => 'ASC']),
            'families' => $familyRepo->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']),
        ]);
    }

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

        $ingredients   = $ingRepo->findBy([], ['name' => 'ASC']);
        $allRecipes    = $recipeRepo->findBy([], ['name' => 'ASC']);
        $availableSubs = [];
        foreach ($allRecipes as $r) {
            if ($r->getId() === $id) continue;
            if (!$calc->wouldCreateCycle($id, $r->getId())) {
                $availableSubs[] = $r;
            }
        }

        return $this->render('recipe/show.html.twig', [
            'computed'      => $computed,
            'recipe'        => $computed['recipe'],
            'lines'         => $computed['lines'],
            'totals'        => $computed['totals'],
            'ingredients'   => $ingredients,
            'availableSubs' => $availableSubs,
        ]);
    }

    #[Route('/recettes/creer', name: 'app_recipe_create', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function create(Request $request, EntityManagerInterface $em, CostCalculator $calc, ConfigBoutiqueRepository $configRepo): Response
    {
        $recipe = new Recipe();
        $this->hydrateRecipe($recipe, $request, $configRepo->getConfig()->getTauxHoraireMo());
        $em->persist($recipe);
        $em->flush();
        $calc->updateCache($recipe->getId());
        $this->addFlash('success', "Recette « {$recipe->getName()} » créée.");
        return $this->redirectToRoute('app_recipe_show', ['id' => $recipe->getId()]);
    }

    #[Route('/recettes/{id}/modifier', name: 'app_recipe_update', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function update(int $id, Request $request, RecipeRepository $repo, EntityManagerInterface $em, CostCalculator $calc, ConfigBoutiqueRepository $configRepo): Response
    {
        $recipe = $repo->find($id);
        if (!$recipe) throw $this->createNotFoundException();
        $this->hydrateRecipe($recipe, $request, $configRepo->getConfig()->getTauxHoraireMo());
        $em->flush();
        $calc->updateCache($id);
        $this->addFlash('success', 'Recette mise à jour.');
        return $this->redirectToRoute('app_recipe_show', ['id' => $id]);
    }

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
            $sub   = $recipeRepo->find($subId);
            if (!$sub) { $this->addFlash('danger', 'Sous-recette introuvable.'); return $this->redirectToRoute('app_recipe_show', ['id' => $id]); }
            if ($calc->wouldCreateCycle($id, $subId)) { $this->addFlash('danger', 'Impossible : cela créerait une référence circulaire.'); return $this->redirectToRoute('app_recipe_show', ['id' => $id]); }
            $line->setSubRecipe($sub);
        } else {
            $ingId = (int) $request->request->get('ingredient_id');
            $ing   = $ingRepo->find($ingId);
            if (!$ing) { $this->addFlash('danger', 'Ingrédient introuvable.'); return $this->redirectToRoute('app_recipe_show', ['id' => $id]); }
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

    /**
     * Modifie une ligne existante.
     *
     * Corriger une quantité obligeait à supprimer la ligne puis à la recréer —
     * ce qui faisait perdre au passage l'annotation, la perte, le rendement et
     * la position dans la liste. L'annotation était même totalement
     * inaccessible : saisie à la création, plus jamais modifiable ensuite.
     *
     * L'ingrédient, lui, reste figé : le changer ne corrige pas une ligne, il
     * en fait une autre. Supprimer puis ajouter reste alors le geste juste.
     */
    #[Route('/recettes/{id}/lignes/{lineId}/modifier', name: 'app_recipe_edit_line', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function editLine(int $id, int $lineId, Request $request, EntityManagerInterface $em, CostCalculator $calc): Response
    {
        $line = $em->getRepository(RecipeLine::class)->find($lineId);

        // Le contrôle d'appartenance n'est pas cosmétique : sans lui, l'identifiant
        // d'une ligne suffirait à modifier la recette d'un autre utilisateur.
        if (!$line || $line->getRecipe()->getId() !== $id) {
            throw $this->createNotFoundException();
        }

        $qty = (float) str_replace(',', '.', (string) $request->request->get('qty_brute', 0));

        if ($qty <= 0) {
            $this->addFlash('danger', 'La quantité doit être supérieure à zéro. Pour retirer cet élément, utilisez Supprimer.');
            return $this->redirectToRoute('app_recipe_show', ['id' => $id]);
        }

        $clamp = static fn (float $v) => max(0.0, min(100.0, $v));

        $line->setQtyBrute($qty);
        $line->setUnit((string) $request->request->get('unit', $line->getUnit()));
        $line->setLossPercent($clamp((float) str_replace(',', '.', (string) $request->request->get('loss_percent', 0))));
        $line->setYieldPercent($clamp((float) str_replace(',', '.', (string) $request->request->get('yield_percent', 100))));

        // Champ vidé volontairement : on efface l'annotation.
        $note = trim((string) $request->request->get('note', ''));
        $line->setNote($note !== '' ? $note : null);

        $em->flush();
        $calc->updateCache($id);

        $this->addFlash('success', 'Ligne modifiée.');

        return $this->redirectToRoute('app_recipe_show', ['id' => $id]);
    }

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
        $copy->setLaborMinutes($src->getLaborMinutes());
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

    #[Route('/api/recettes/recalculer', name: 'app_recipe_recalculate', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function recalculate(Request $request, CostCalculator $calc): JsonResponse
    {
        $data         = json_decode($request->getContent(), true) ?? [];
        $ingredientId = $data['ingredient_id'] ?? null;
        $recipeId     = $data['recipe_id'] ?? null;

        if ($recipeId) {
            $calc->updateCache((int) $recipeId);
            return new JsonResponse(['ok' => true, 'updated' => 1]);
        }

        $count = $calc->recalculateAll($ingredientId ? (int) $ingredientId : null);
        return new JsonResponse(['ok' => true, 'updated' => $count]);
    }

    /**
     * PDF fiche technique complète.
     * Accepte ?qty=X pour adapter les quantités à une production différente de la base.
     */
    #[Route('/recettes/{id}/pdf', name: 'app_recipe_pdf')]
    #[IsGranted('ROLE_EDITOR')]
    public function pdf(int $id, Request $request, CostCalculator $calc, GotenbergPdfInterface $gotenberg): Response
    {
        $computed = $calc->compute($id);
        if (!$computed) throw $this->createNotFoundException();

        $baseQty = $computed['recipe']->getOutputValue();
        $reqQty  = max(0.001, (float) ($request->query->get('qty') ?: $baseQty));
        $factor  = $baseQty > 0 ? $reqQty / $baseQty : 1.0;

        return $gotenberg->html()
            ->content('recipe/print.html.twig', [
                'recipe'       => $computed['recipe'],
                'lines'        => $computed['lines'],
                'totals'       => $computed['totals'],
                'factor'       => $factor,
                'requestedQty' => $reqQty,
            ])
            ->paperSize(8.27, 11.69)
            ->margins(0.59, 0.59, 0.59, 0.59)
            ->printBackground(true)
            ->preferCssPageSize(false)
            ->fileName('fiche-recette-' . $id . '-' . number_format($reqQty, 3, '', '') . $computed['recipe']->getOutputUnitLabel() . '.pdf')
            ->generate()
            ->stream();
    }

    /**
     * PDF fiche labo (sans infos financières).
     * Accepte ?qty=X pour adapter les quantités à une production différente de la base.
     */
    #[Route('/recettes/{id}/pdf-labo', name: 'app_recipe_pdf_lab')]
    public function pdfLab(int $id, Request $request, CostCalculator $calc, GotenbergPdfInterface $gotenberg): Response
    {
        $computed = $calc->compute($id);
        if (!$computed) throw $this->createNotFoundException();

        $baseQty = $computed['recipe']->getOutputValue();
        $reqQty  = max(0.001, (float) ($request->query->get('qty') ?: $baseQty));
        $factor  = $baseQty > 0 ? $reqQty / $baseQty : 1.0;

        return $gotenberg->html()
            ->content('recipe/print_lab.html.twig', [
                'recipe'       => $computed['recipe'],
                'lines'        => $computed['lines'],
                'totals'       => $computed['totals'],
                'factor'       => $factor,
                'requestedQty' => $reqQty,
            ])
            ->paperSize(8.27, 11.69)
            ->margins(0.59, 0.59, 0.59, 0.59)
            ->printBackground(true)
            ->preferCssPageSize(false)
            ->fileName('fiche-labo-' . $id . '-' . number_format($reqQty, 3, '', '') . $computed['recipe']->getOutputUnitLabel() . '.pdf')
            ->generate()
            ->stream();
    }

    private function hydrateRecipe(Recipe $recipe, Request $request, float $tauxMo): void
    {
        $clamp = fn(float $v) => max(0.0, min(100.0, $v));

        $recipe->setName($request->request->get('name', $recipe->getName()));
        $recipe->setFamily($request->request->get('family', $recipe->getFamily()));
        $recipe->setOutputType($request->request->get('output_type', $recipe->getOutputType()));
        $recipe->setOutputValue(max(0.001, (float) $request->request->get('output_value', $recipe->getOutputValue())));
        $recipe->setLossPercent($clamp((float) $request->request->get('loss_percent', $recipe->getLossPercent())));
        $recipe->setYieldPercent($clamp((float) $request->request->get('yield_percent', $recipe->getYieldPercent())));
        $recipe->setProductVatRate(max(0.0, (float) $request->request->get('product_vat_rate', $recipe->getProductVatRate())));

        $minutes = max(0, (int) $request->request->get('labor_minutes', $recipe->getLaborMinutes()));
        $recipe->setLaborMinutes($minutes);
        $recipe->setLaborCostHt(round($minutes / 60 * $tauxMo, 2));

        $recipe->setPackagingCostHt(max(0.0, (float) $request->request->get('packaging_cost_ht', $recipe->getPackagingCostHt())));
        $recipe->setPricingMode($request->request->get('pricing_mode', $recipe->getPricingMode()));
        $recipe->setPricingValue(max(0.0, (float) $request->request->get('pricing_value', $recipe->getPricingValue())));

        // Champ absent du formulaire (création) : on ne touche pas à la valeur
        // existante. Champ vidé volontairement : on efface.
        if ($request->request->has('sell_price_ttc')) {
            $raw = trim((string) $request->request->get('sell_price_ttc'));
            $recipe->setSellPriceTtc($raw === '' ? null : (float) str_replace(',', '.', $raw));
        }

        // Même règle pour le mode opératoire : un formulaire qui ne le porte
        // pas ne doit pas effacer des étapes déjà saisies.
        if ($request->request->has('process')) {
            $recipe->setProcess((string) $request->request->get('process'));
        }
    }
}