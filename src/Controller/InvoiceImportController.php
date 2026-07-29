<?php
namespace App\Controller;

use App\Entity\PurchaseInvoice;
use App\Entity\PurchaseInvoiceLine;
use App\Repository\IngredientCategoryRepository;
use App\Repository\IngredientPriceRepository;
use App\Repository\IngredientRepository;
use App\Repository\PurchaseInvoiceRepository;
use App\Service\InvoiceInbox;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Factures d'achat : réception, file d'attente, validation.
 *
 * Les factures sont persistées dès la réception. Rien ne vit en session : une
 * facture peut arriver sans personne devant l'écran, et une validation
 * interrompue se reprend là où elle en était.
 */
#[IsGranted('ROLE_EDITOR')]
class InvoiceImportController extends AbstractController
{
    // ─── File d'attente ──────────────────────────────────────────────────────

    #[Route('/factures', name: 'app_invoice_index', methods: ['GET'])]
    public function index(PurchaseInvoiceRepository $invoices): Response
    {
        return $this->render('invoice/index.html.twig', [
            'pending'   => $invoices->findPending(),
            'processed' => $invoices->findProcessed(),
        ]);
    }

    // ─── Réception (dépôt manuel) ────────────────────────────────────────────

    #[Route('/factures/importer', name: 'app_invoice_import', methods: ['GET', 'POST'])]
    public function import(Request $request, InvoiceInbox $inbox): Response
    {
        if ($request->isMethod('GET')) {
            return $this->render('invoice/import.html.twig');
        }

        $file = $request->files->get('invoice_file');
        if (!$file || !$file->isValid()) {
            $this->addFlash('danger', 'Aucun fichier valide reçu.');
            return $this->render('invoice/import.html.twig');
        }

        $ext  = strtolower($file->getClientOriginalExtension());
        $mime = match ($ext) {
            'xml'   => 'application/xml',
            'pdf'   => 'application/pdf',
            default => $file->getMimeType() ?? '',
        };

        $result = $inbox->receive(file_get_contents($file->getPathname()), $mime);

        if ($result['error']) {
            $this->addFlash('danger', $result['error']);
            return $this->render('invoice/import.html.twig');
        }

        $invoice = $result['invoice'];

        if ($result['duplicate']) {
            $this->addFlash('warning', sprintf(
                'Facture %s de %s déjà reçue le %s — aucun doublon créé.',
                $invoice->getInvoiceId(),
                $invoice->getSupplier()->getName(),
                $invoice->getImportedAt()->format('d/m/Y')
            ));

            return $this->redirectToRoute(
                $invoice->isPending() ? 'app_invoice_review' : 'app_invoice_index',
                $invoice->isPending() ? ['id' => $invoice->getId()] : []
            );
        }

        $this->addFlash('info', sprintf(
            'Facture %s de %s : %d ligne(s) détectée(s).',
            $invoice->getInvoiceId(),
            $invoice->getSupplier()->getName(),
            count($invoice->getLines())
        ));

        return $this->redirectToRoute('app_invoice_review', ['id' => $invoice->getId()]);
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    #[Route('/factures/{id}', name: 'app_invoice_review', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function review(
        PurchaseInvoice $invoice,
        IngredientRepository $ingRepo,
        IngredientCategoryRepository $catRepo,
        IngredientPriceRepository $priceRepo,
    ): Response {
        return $this->render('invoice/confirm.html.twig', [
            'purchaseInvoice' => $invoice,
            'invoice'         => $this->buildViewModel($invoice, $priceRepo),
            'ingredients'     => $ingRepo->findBy([], ['name' => 'ASC']),
            'categories'      => $catRepo->findBy([], ['name' => 'ASC']),
            'stats'           => $this->buildStats($invoice),
        ]);
    }

    #[Route('/factures/{id}/valider', name: 'app_invoice_apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function apply(PurchaseInvoice $invoice, Request $request, InvoiceInbox $inbox): Response
    {
        if (!$invoice->isPending()) {
            $this->addFlash('warning', 'Cette facture a déjà été traitée.');
            return $this->redirectToRoute('app_invoice_index');
        }

        $result = $inbox->apply($invoice, $request->request->all('lines') ?? []);

        if ($result['prices'] === 0) {
            $this->addFlash('warning', 'Aucune ligne confirmée : la facture reste en attente.');
            return $this->redirectToRoute('app_invoice_review', ['id' => $invoice->getId()]);
        }

        $msg = sprintf('%d prix enregistré(s)', $result['prices']);
        if ($result['ingredients'] > 0) {
            $msg .= sprintf(' · %d ingrédient(s) créé(s)', $result['ingredients']);
        }
        if ($result['skipped'] > 0) {
            $msg .= sprintf(' · %d ligne(s) laissée(s) de côté', $result['skipped']);
        }
        $this->addFlash('success', $msg . '.');

        return $this->redirectToRoute('app_invoice_index');
    }

    #[Route('/factures/{id}/ecarter', name: 'app_invoice_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(PurchaseInvoice $invoice, Request $request, InvoiceInbox $inbox): Response
    {
        $inbox->reject($invoice, $request->request->get('reason') ?: null);
        $this->addFlash('info', sprintf('Facture %s écartée.', $invoice->getInvoiceId()));

        return $this->redirectToRoute('app_invoice_index');
    }

    // ─── Modèle de vue ───────────────────────────────────────────────────────

    /**
     * Reprend la forme de tableau attendue par le template, en y ajoutant
     * l'écart avec le dernier prix connu — l'information qui permet de repérer
     * d'un coup d'œil un changement de conditionnement ou une erreur de saisie
     * fournisseur avant qu'elle ne contamine les marges.
     */
    private function buildViewModel(PurchaseInvoice $invoice, IngredientPriceRepository $priceRepo): array
    {
        $lines = [];

        foreach ($invoice->getLines() as $line) {
            $previous = null;
            $delta    = null;

            if ($ingredient = $line->getIngredient()) {
                $known = $priceRepo->findBy(
                    ['ingredient' => $ingredient],
                    ['effectiveDate' => 'DESC', 'id' => 'DESC'],
                    1
                );
                if ($known) {
                    $previous = $known[0]->getPriceHt();
                    if ($previous > 0) {
                        $delta = round((($line->getPriceHt() - $previous) / $previous) * 100, 1);
                    }
                }
            }

            $lines[] = [
                'name'               => $line->getDescription(),
                'supplier_ref'       => $line->getSupplierRef(),
                'qty_billed'         => $line->getQty(),
                'unit_code'          => $line->getUnitCode(),
                'unit'               => $line->getUnit(),
                'price_ht'           => $line->getPriceHt(),
                'vat_rate'           => $line->getVatRate(),
                'line_total'         => $line->getLineTotal(),
                'matched_ingredient' => $line->getIngredient(),
                'match_score'        => $line->getMatchScore(),
                'match_source'       => $line->getMatchSource(),
                'applied'            => $line->isApplied(),
                'previous_price'     => $previous,
                'delta_percent'      => $delta,
            ];
        }

        return [
            'invoice_id'  => $invoice->getInvoiceId(),
            'seller_name' => $invoice->getSupplier()?->getName() ?? 'Fournisseur inconnu',
            'issue_date'  => $invoice->getInvoiceDate()->format('Y-m-d'),
            'total_ht'    => $invoice->getTotalHt(),
            'total_ttc'   => $invoice->getTotalTtc(),
            'lines'       => $lines,
        ];
    }

    private function buildStats(PurchaseInvoice $invoice): array
    {
        $count = fn (string $source) => count(array_filter(
            $invoice->getLines()->toArray(),
            fn (PurchaseInvoiceLine $l) => $l->getMatchSource() === $source
        ));

        return [
            'total'     => count($invoice->getLines()),
            'mapped'    => $count('mapping'),
            'fuzzy'     => $count('fuzzy'),
            'unmatched' => $count('none'),
        ];
    }
}
