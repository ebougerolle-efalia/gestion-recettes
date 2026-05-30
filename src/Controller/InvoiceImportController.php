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
        Request                      $request,
        SessionInterface             $session,
        InvoiceLinesMatcher          $matcher,
        IngredientRepository         $ingRepo,
        IngredientCategoryRepository $catRepo,
        EntityManagerInterface       $em,
        CostCalculator               $calc,
    ): Response {
        $invoice = $session->get('invoice_import');
        if (!$invoice) {
            $this->addFlash('warning', 'Session expirée. Veuillez ré-importer la facture.');
            return $this->redirectToRoute('app_invoice_import');
        }

        $ingredients = $ingRepo->findBy([], ['name' => 'ASC']);
        $categories  = $catRepo->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            $this->processConfirmation(
                $request, $invoice, $ingRepo, $catRepo, $matcher, $em, $calc, $session
            );
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

    // ─── Traitement de la confirmation ───────────────────────────────────────

    /**
     * Logique en deux passes pour éviter les conflits de flush :
     *
     * Passe 1 — résoudre/créer les entités Ingredient (persist uniquement)
     * Flush 1 — un seul flush pour assigner les IDs aux nouveaux ingrédients
     * Passe 2 — créer les IngredientPrice + sauvegarder les mappings
     * Flush 2 — flush final pour les prix et les mappings
     */
    private function processConfirmation(
        Request                      $request,
        array                        $invoice,
        IngredientRepository         $ingRepo,
        IngredientCategoryRepository $catRepo,
        InvoiceLinesMatcher          $matcher,
        EntityManagerInterface       $em,
        CostCalculator               $calc,
        SessionInterface             $session,
    ): void {
        $formLines  = $request->request->all('lines') ?? [];
        $operations = []; // [{ingredient, line, formLine, isNew}]
        $ingsCreated = 0;

        // ── PASSE 1 : résoudre les ingrédients ───────────────────────────────
        foreach ($invoice['lines'] as $i => $line) {
            $formLine = $formLines[$i] ?? [];
            if (empty($formLine['confirmed'])) continue;

            $mode = $formLine['mode'] ?? 'existing';

            if ($mode === 'create') {
                $newName = trim($formLine['new_ingredient_name'] ?? '');
                if (empty($newName)) continue;

                $ingredient = new Ingredient();
                $ingredient->setName($newName);
                $ingredient->setBaseUnit($formLine['new_ingredient_unit'] ?? $line['unit'] ?? 'kg');
                $ingredient->setVatRate((float) ($formLine['new_ingredient_vat'] ?? 5.5));
                $ingredient->setDefaultSupplier($invoice['seller_name']);

                $catId = (int) ($formLine['new_ingredient_category_id'] ?? 0);
                if ($catId && ($cat = $catRepo->find($catId))) {
                    $ingredient->setCategory($cat);
                }

                $em->persist($ingredient);
                $ingsCreated++;

            } elseif ($mode === 'existing') {
                $ingId = (int) ($formLine['ingredient_id'] ?? 0);
                if (!$ingId) continue;
                $ingredient = $ingRepo->find($ingId);
                if (!$ingredient) continue;

            } else {
                // mode = 'ignore' ou inconnu
                continue;
            }

            $operations[] = [
                'ingredient' => $ingredient,
                'line'       => $line,
                'formLine'   => $formLine,
                'isNew'      => ($mode === 'create'),
            ];
        }

        if (empty($operations)) {
            $this->addFlash('warning', 'Aucune ligne confirmée.');
            $session->remove('invoice_import');
            return;
        }

        // ── FLUSH 1 : assigner les IDs aux nouveaux ingrédients ─────────────
        $em->flush();

        // ── PASSE 2 : créer les prix et les mappings ─────────────────────────
        $pricesCreated  = 0;
        $affectedIngIds = [];

        foreach ($operations as $op) {
            $price = new IngredientPrice();
            $price->setIngredient($op['ingredient']);
            $price->setPriceHt((float) ($op['formLine']['price_ht'] ?? $op['line']['price_ht']));
            $price->setSupplier($op['formLine']['supplier'] ?? $invoice['seller_name']);
            $price->setEffectiveDate(
                new \DateTime($op['formLine']['effective_date'] ?? $invoice['issue_date'])
            );
            $em->persist($price);

            $matcher->saveMapping($op['line']['name'], $op['ingredient']);

            $affectedIngIds[] = $op['ingredient']->getId();
            $pricesCreated++;
        }

        // ── FLUSH 2 : prix + mappings ────────────────────────────────────────
        $em->flush();

        // ── Recalcul des coûts ───────────────────────────────────────────────
        foreach (array_unique(array_filter($affectedIngIds)) as $ingId) {
            $calc->recalculateAll($ingId);
        }

        $session->remove('invoice_import');

        $msg = sprintf('%d prix enregistré(s)', $pricesCreated);
        if ($ingsCreated > 0) {
            $msg .= sprintf(' · %d nouvel(aux) ingrédient(s) créé(s)', $ingsCreated);
        }
        $msg .= '. Coûts recalculés.';
        $this->addFlash('success', $msg);
    }

    // ─── Annulation ──────────────────────────────────────────────────────────

    #[Route('/factures/annuler', name: 'app_invoice_cancel')]
    public function cancel(SessionInterface $session): Response
    {
        $session->remove('invoice_import');
        $this->addFlash('info', 'Import annulé.');
        return $this->redirectToRoute('app_invoice_import');
    }
}