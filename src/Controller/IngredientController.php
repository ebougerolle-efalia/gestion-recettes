<?php
namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\IngredientPrice;
use App\Repository\IngredientRepository;
use App\Repository\IngredientCategoryRepository;
use App\Service\CostCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class IngredientController extends AbstractController
{
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

    #[Route('/ingredients/{id}', name: 'app_ingredient_show', requirements: ['id' => '\d+'])]
    public function show(int $id, IngredientRepository $repo): Response
    {
        $ingredient = $repo->find($id);
        if (!$ingredient) throw $this->createNotFoundException();

        return $this->render('ingredient/show.html.twig', [
            'ingredient' => $ingredient,
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
}
