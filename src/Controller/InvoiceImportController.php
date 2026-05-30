<?php
namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\IngredientPrice;
use App\Repository\IngredientCategoryRepository;
use App\Repository\IngredientRepository;
use App\Service\CostCalculator;
use App\Service\FacturXParser;
use App\Service\InvoiceLinesMatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_EDITOR')]
class InvoiceImportController extends AbstractController
{
    // ─── Étape 1 : Upload ────────────────────────────────────────────────────

    #[Route('/factures/importer', name: 'app_invoice_import', methods: ['GET', 'POST'])]
    public function import(
        Request             $request,
        FacturXParser       $parser,
        InvoiceLinesMatcher $matcher,
        SessionInterface    $session,
    ): Response {
        if ($request->isMethod('GET')) {
            return $this->render('invoice/import.html.twig');
        }

        $file = $request->files->get('invoice_file');
        if (!$file || !$file->isValid()) {
            $this->addFlash('danger', 'Aucun fichier valide reçu.');
            return $this->render('invoice/import.html.twig');
        }

        $mime = $file->getMimeType() ?? '';
        $ext  = strtolower($file->getClientOriginalExtension());
        if ($ext === 'xml') $mime = 'application/xml';
        if ($ext === 'pdf') $mime = 'application/pdf';

        $allowed = ['application/pdf', 'application/xml', 'text/xml', 'application/octet-stream'];
        if (!in_array($mime, $allowed) && !in_array($ext, ['pdf', 'xml'])) {
            $this->addFlash('danger', 'Format non supporté. Envoyez un PDF Factur-X ou un fichier XML.');
            return $this->render('invoice/import.html.twig');
        }

        $invoice = $parser->parse(file_get_contents($file->getPathname()), $mime);

        if (!$invoice) {
            $this->addFlash('danger',
                'Impossible d\'extraire les données de ce fichier. '
                . 'Vérifiez qu\'il s\'agit bien d\'un PDF Factur-X ou d\'un XML CII/EN16931.'
            );
            return $this->render('invoice/import.html.twig');
        }

        if (empty($invoice['lines'])) {
            $this->addFlash('warning',
                "Facture « {$invoice['invoice_id']} » lue mais aucune ligne d'article trouvée."
            );
            return $this->render('invoice/import.html.twig');
        }

        $invoice['lines'] = $matcher->matchLines($invoice['lines']);
        $session->set('invoice_import', $invoice);

        $this->addFlash('info', sprintf(
            'Facture %s de %s : %d ligne(s) détectée(s).',
            $invoice['invoice_id'], $invoice['seller_name'], count($invoice['lines'])
        ));

        return $this->redirectToRoute('app_invoice_confirm');
    }

    // ─── Étape 2 : Confirmation ──────────────────────────────────────────────

    #[Route('/factures/confirmer', name: 'app_invoice_confirm', methods: ['GET', 'POST'])]
    public function confirm(
        Request                     $request,
        SessionInterface            $session,
        InvoiceLinesMatcher         $matcher,
        IngredientRepository        $ingRepo,
        IngredientCategoryRepository $catRepo,
        EntityManagerInterface      $em,
        CostCalculator              $calc,
    ): Response {
        $invoice = $session->get('invoice_import');
        if (!$invoice) {
            $this->addFlash('warning', 'Session expirée. Veuillez ré-importer la facture.');
            return $this->redirectToRoute('app_invoice_import');
        }

        $ingredients = $ingRepo->findBy([], ['name' => 'ASC']);
        $categories  = $catRepo->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']);

        if ($request->isMethod('POST')) {
            $formLines      = $request->request->all('lines') ?? [];
            $pricesCreated  = 0;
            $ingsCreated    = 0;
            $affectedIngIds = [];

            foreach ($invoice['lines'] as $i => $line) {
                $formLine = $formLines[$i] ?? [];
                if (empty($formLine['confirmed'])) continue;

                $mode = $formLine['mode'] ?? 'existing';

                // ── Créer un nouvel ingrédient si demandé ────────────────────
                if ($mode === 'create') {
                    $newName = trim($formLine['new_ingredient_name'] ?? '');
                    if (empty($newName)) continue;

                    $ingredient = new Ingredient();
                    $ingredient->setName($newName);
                    $ingredient->setBaseUnit($formLine['new_ingredient_unit'] ?? $line['unit'] ?? 'kg');
                    $ingredient->setVatRate((float) ($formLine['new_ingredient_vat'] ?? 5.5));
                    $ingredient->setDefaultSupplier($invoice['seller_name']);

                    $catId = (int) ($formLine['new_ingredient_category_id'] ?? 0);
                    if ($catId) {
                        $cat = $catRepo->find($catId);
                        if ($cat) $ingredient->setCategory($cat);
                    }

                    $em->persist($ingredient);
                    $em->flush(); // besoin de l'ID pour la suite
                    $ingsCreated++;

                    // Mémoriser l'association pour les prochains imports
                    $matcher->saveMapping($line['name'], $ingredient);

                } else {
                    // ── Associer à un ingrédient existant ───────────────────
                    $ingId = (int) ($formLine['ingredient_id'] ?? 0);
                    if (!$ingId) continue;
                    $ingredient = $ingRepo->find($ingId);
                    if (!$ingredient) continue;

                    $matcher->saveMapping($line['name'], $ingredient);
                }

                // ── Créer le prix ────────────────────────────────────────────
                $price = new IngredientPrice();
                $price->setIngredient($ingredient);
                $price->setPriceHt((float) ($formLine['price_ht'] ?? $line['price_ht']));
                $price->setSupplier($formLine['supplier'] ?? $invoice['seller_name']);
                $price->setEffectiveDate(
                    new \DateTime($formLine['effective_date'] ?? $invoice['issue_date'])
                );
                $em->persist($price);

                $affectedIngIds[] = $ingredient->getId();
                $pricesCreated++;
            }

            $em->flush();

            foreach (array_unique($affectedIngIds) as $ingId) {
                $calc->recalculateAll($ingId);
            }

            $session->remove('invoice_import');

            $msg = sprintf('%d prix enregistré(s)', $pricesCreated);
            if ($ingsCreated > 0) {
                $msg .= sprintf(' dont %d nouvel(aux) ingrédient(s) créé(s)', $ingsCreated);
            }
            $msg .= '. Coûts des recettes recalculés.';
            $this->addFlash('success', $msg);

            return $this->redirectToRoute('app_ingredient_index');
        }

        $stats = [
            'total'     => count($invoice['lines']),
            'mapped'    => count(array_filter($invoice['lines'], fn($l) => $l['match_source'] === 'mapping')),
            'fuzzy'     => count(array_filter($invoice['lines'], fn($l) => $l['match_source'] === 'fuzzy')),
            'unmatched' => count(array_filter($invoice['lines'], fn($l) => $l['match_source'] === 'none')),
        ];

        return $this->render('invoice/confirm.html.twig', [
            'invoice'     => $invoice,
            'ingredients' => $ingredients,
            'categories'  => $categories,
            'stats'       => $stats,
        ]);
    }

    #[Route('/factures/annuler', name: 'app_invoice_cancel')]
    public function cancel(SessionInterface $session): Response
    {
        $session->remove('invoice_import');
        $this->addFlash('info', 'Import annulé.');
        return $this->redirectToRoute('app_invoice_import');
    }
}