<?php
namespace App\Controller;

use App\Entity\{Ingredient, IngredientPrice, PurchaseInvoice, PurchaseInvoiceLine, Supplier};
use App\Repository\{IngredientCategoryRepository, IngredientRepository, SupplierRepository};
use App\Service\{CostCalculator, FacturXParser, InvoiceLinesMatcher};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
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

        $invoice = $parser->parse(file_get_contents($file->getPathname()), $mime);

        if (!$invoice) {
            $this->addFlash('danger', 'Impossible d\'extraire les données. Vérifiez qu\'il s\'agit bien d\'un PDF Factur-X ou XML CII/EN16931.');
            return $this->render('invoice/import.html.twig');
        }

        if (empty($invoice['lines'])) {
            $this->addFlash('warning', "Facture « {$invoice['invoice_id']} » lue mais aucune ligne trouvée.");
            return $this->render('invoice/import.html.twig');
        }

        $invoice['lines'] = $matcher->matchLines($invoice['lines']);
        $session->set('invoice_import', $invoice);

        $this->addFlash('info', sprintf('Facture %s de %s : %d ligne(s) détectée(s).',
            $invoice['invoice_id'], $invoice['seller_name'], count($invoice['lines'])));

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
        SupplierRepository           $supplierRepo,
        EntityManagerInterface       $em,
        CostCalculator               $calc,
    ): Response {
        $invoice = $session->get('invoice_import');
        if (!$invoice) {
            $this->addFlash('warning', 'Session expirée. Veuillez ré-importer la facture.');
            return $this->redirectToRoute('app_invoice_import');
        }

        if ($request->isMethod('POST')) {
            $this->processConfirmation($request, $invoice, $ingRepo, $catRepo, $supplierRepo, $matcher, $em, $calc, $session);
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
            'ingredients' => $ingRepo->findBy([], ['name' => 'ASC']),
            'categories'  => $catRepo->findBy([], ['name' => 'ASC']),
            'stats'       => $stats,
        ]);
    }

    // ─── Traitement ──────────────────────────────────────────────────────────

    private function processConfirmation(
        Request                      $request,
        array                        $invoice,
        IngredientRepository         $ingRepo,
        IngredientCategoryRepository $catRepo,
        SupplierRepository           $supplierRepo,
        InvoiceLinesMatcher          $matcher,
        EntityManagerInterface       $em,
        CostCalculator               $calc,
        SessionInterface             $session,
    ): void {
        $formLines   = $request->request->all('lines') ?? [];
        $operations  = [];
        $ingsCreated = 0;

        // ── PASSE 1 : résoudre/créer les ingrédients ─────────────────────────
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

        // ── FLUSH 1 : IDs des nouveaux ingrédients ───────────────────────────
        $em->flush();

        // ── Créer ou retrouver le Supplier ───────────────────────────────────
        $supplier = $supplierRepo->findByIdentifier(
            $invoice['seller_name'],
            $invoice['seller_siret'] ?? null
        );

        if (!$supplier) {
            $supplier = new Supplier();
            $supplier->setName($invoice['seller_name']);
            $supplier->setSiret($invoice['seller_siret'] ?? null);
            $supplier->setVatNumber($invoice['seller_vat'] ?? null);
            $supplier->setAddressLine($invoice['seller_address'] ?? null);
            $supplier->setPostcode($invoice['seller_postcode'] ?? null);
            $supplier->setCity($invoice['seller_city'] ?? null);
            $supplier->setCountry($invoice['seller_country'] ?? null);
            $em->persist($supplier);
        } else {
            // Enrichir les données manquantes
            if (!$supplier->getSiret() && !empty($invoice['seller_siret'])) {
                $supplier->setSiret($invoice['seller_siret']);
            }
            if (!$supplier->getCity() && !empty($invoice['seller_city'])) {
                $supplier->setCity($invoice['seller_city']);
                $supplier->setPostcode($invoice['seller_postcode'] ?? null);
            }
        }

        // Mettre à jour la date de dernière facture
        $invoiceDate = new \DateTimeImmutable($invoice['issue_date']);
        if (!$supplier->getLastInvoiceDate() || $supplier->getLastInvoiceDate() < $invoiceDate) {
            $supplier->setLastInvoiceDate($invoiceDate);
        }

        // ── Créer la PurchaseInvoice ──────────────────────────────────────────
        $purchaseInvoice = new PurchaseInvoice();
        $purchaseInvoice->setSupplier($supplier);
        $purchaseInvoice->setInvoiceId($invoice['invoice_id']);
        $purchaseInvoice->setInvoiceDate(new \DateTime($invoice['issue_date']));
        $purchaseInvoice->setTotalHt($invoice['total_ht'] ?? null);
        $purchaseInvoice->setTotalTtc($invoice['total_ttc'] ?? null);
        $em->persist($purchaseInvoice);

        // ── PASSE 2 : prix + lignes facture + mappings ────────────────────────
        $pricesCreated  = 0;
        $affectedIngIds = [];

        foreach ($operations as $op) {
            $ingredient = $op['ingredient'];
            $line       = $op['line'];
            $formLine   = $op['formLine'];

            // PurchaseInvoiceLine (trace brute)
            $invoiceLine = new PurchaseInvoiceLine();
            $invoiceLine->setInvoice($purchaseInvoice);
            $invoiceLine->setIngredient($ingredient);
            $invoiceLine->setSupplierRef($line['supplier_ref'] ?? null);
            $invoiceLine->setDescription($line['name']);
            $invoiceLine->setQty($line['qty_billed'] ?? 0);
            $invoiceLine->setUnitCode($line['unit_code'] ?? 'KGM');
            $invoiceLine->setUnit($line['unit'] ?? 'kg');
            $invoiceLine->setPriceHt((float) ($formLine['price_ht'] ?? $line['price_ht']));
            $invoiceLine->setVatRate($line['vat_rate'] ?? 5.5);
            $invoiceLine->setLineTotal($line['line_total'] ?? 0);
            $em->persist($invoiceLine);

            // IngredientPrice lié au fournisseur et à la facture
            $price = new IngredientPrice();
            $price->setIngredient($ingredient);
            $price->setPriceHt((float) ($formLine['price_ht'] ?? $line['price_ht']));
            $price->setSupplierEntity($supplier);   // FK Supplier + sync champ texte
            $price->setPurchaseInvoice($purchaseInvoice);
            $price->setEffectiveDate(new \DateTime($formLine['effective_date'] ?? $invoice['issue_date']));
            $em->persist($price);

            $matcher->saveMapping($line['name'], $ingredient);

            $affectedIngIds[] = $ingredient->getId();
            $pricesCreated++;
        }

        // ── FLUSH 2 : tout sauvegarder ────────────────────────────────────────
        $em->flush();

        // ── Recalcul des coûts ────────────────────────────────────────────────
        foreach (array_unique(array_filter($affectedIngIds)) as $ingId) {
            $calc->recalculateAll($ingId);
        }

        $session->remove('invoice_import');

        $msg = sprintf('%d prix enregistré(s)', $pricesCreated);
        if ($ingsCreated > 0) $msg .= sprintf(' · %d ingrédient(s) créé(s)', $ingsCreated);
        $msg .= '. Fournisseur « ' . $supplier->getName() . ' » enregistré.';
        $this->addFlash('success', $msg);
    }

    #[Route('/factures/annuler', name: 'app_invoice_cancel')]
    public function cancel(SessionInterface $session): Response
    {
        $session->remove('invoice_import');
        $this->addFlash('info', 'Import annulé.');
        return $this->redirectToRoute('app_invoice_import');
    }
}
