<?php
namespace App\Controller;

use App\Entity\PurchaseInvoice;
use App\Entity\PurchaseInvoiceLine;
use App\Repository\IngredientCategoryRepository;
use App\Repository\IngredientPriceRepository;
use App\Repository\IngredientRepository;
use App\Repository\PurchaseInvoiceRepository;
use App\Repository\SupplierRepository;
use App\Service\InvoiceArchive;
use App\Service\InvoiceInbox;
use App\Service\MailboxReader;
use App\Service\UnitConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, Request, Response, ResponseHeaderBag};
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
    public function index(PurchaseInvoiceRepository $invoices, MailboxReader $mailbox): Response
    {
        return $this->render('invoice/index.html.twig', [
            'pending'    => $invoices->findPending(),
            'to_capture' => $invoices->findToCapture(),
            'processed'  => $invoices->findProcessed(),
            'mailbox'    => $mailbox->isConfigured() ? $mailbox->describe() : null,
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

        $result = $inbox->receive(
            file_get_contents($file->getPathname()),
            $mime,
            PurchaseInvoice::SOURCE_MANUAL,
            ['filename' => $file->getClientOriginalName()]
        );

        if ($result['error']) {
            $this->addFlash('danger', $result['error']);
            return $this->render('invoice/import.html.twig');
        }

        $invoice = $result['invoice'];

        if ($result['duplicate']) {
            $this->addFlash('warning', sprintf(
                'Facture %s de %s déjà reçue le %s — aucun doublon créé.',
                $invoice->getDisplayReference(),
                $invoice->getSupplier()?->getName() ?? 'fournisseur inconnu',
                $invoice->getImportedAt()->format('d/m/Y')
            ));

            return $this->redirectToRoute(
                $invoice->isOpen() ? $this->reviewRoute($invoice) : 'app_invoice_index',
                $invoice->isOpen() ? ['id' => $invoice->getId()] : []
            );
        }

        if ($result['captured']) {
            $this->addFlash('warning', 'Fichier conservé, mais aucune donnée Factur-X à l\'intérieur : les lignes sont à saisir.');

            return $this->redirectToRoute('app_invoice_capture', ['id' => $invoice->getId()]);
        }

        $this->addFlash('info', sprintf(
            'Facture %s de %s : %d ligne(s) détectée(s).',
            $invoice->getInvoiceId(),
            $invoice->getSupplier()->getName(),
            count($invoice->getLines())
        ));

        return $this->redirectToRoute('app_invoice_review', ['id' => $invoice->getId()]);
    }

    // ─── Quarantaine : saisie manuelle d'une facture illisible ───────────────

    #[Route('/factures/{id}/saisir', name: 'app_invoice_capture', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function capture(
        PurchaseInvoice $invoice,
        Request $request,
        InvoiceInbox $inbox,
        SupplierRepository $supplierRepo,
    ): Response {
        if (!$invoice->isToCapture()) {
            return $this->redirectToRoute($this->reviewRoute($invoice), ['id' => $invoice->getId()]);
        }

        if ($request->isMethod('GET')) {
            return $this->render('invoice/capture.html.twig', [
                'purchaseInvoice' => $invoice,
                'suppliers'       => $supplierRepo->findBy([], ['name' => 'ASC']),
            ]);
        }

        // Le fournisseur peut manquer : il est demandé dans le même formulaire,
        // et l'adresse d'expédition est retenue pour les factures suivantes.
        $supplierId = (int) $request->request->get('supplier_id');
        if ($supplierId && $supplier = $supplierRepo->find($supplierId)) {
            $conflict = $inbox->assignSupplier($invoice, $supplier, $request->request->get('invoice_id'));

            if ($conflict) {
                $this->addFlash('danger', sprintf(
                    'La facture %s de %s est déjà enregistrée (reçue le %s). Cette pièce en est un double : écarte-la, ou saisis le bon numéro.',
                    $conflict->getInvoiceId(),
                    $supplier->getName(),
                    $conflict->getImportedAt()->format('d/m/Y')
                ));

                return $this->redirectToRoute('app_invoice_capture', ['id' => $invoice->getId()]);
            }
        }

        if (!$invoice->getSupplier()) {
            $this->addFlash('danger', 'Choisis le fournisseur : sans lui, les prix ne peuvent être rattachés à personne.');
            return $this->redirectToRoute('app_invoice_capture', ['id' => $invoice->getId()]);
        }

        if ($date = $request->request->get('invoice_date')) {
            $invoice->setInvoiceDate(new \DateTime($date));
        }

        $count = $inbox->capture($invoice, $request->request->all('lines'));

        if ($count === 0) {
            $this->addFlash('warning', 'Aucune ligne saisie : il faut au moins un libellé et un prix.');
            return $this->redirectToRoute('app_invoice_capture', ['id' => $invoice->getId()]);
        }

        $this->addFlash('info', sprintf('%d ligne(s) saisie(s) — à confirmer comme une facture lue.', $count));

        return $this->redirectToRoute('app_invoice_review', ['id' => $invoice->getId()]);
    }

    /**
     * Fichier d'origine de la facture.
     *
     * Servi par le contrôleur et non depuis la racine web : une facture
     * fournisseur ne doit pas être atteignable par une URL devinée.
     */
    #[Route('/factures/{id}/piece', name: 'app_invoice_attachment', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function attachment(PurchaseInvoice $invoice, InvoiceArchive $archive): Response
    {
        $path = $invoice->getAttachmentPath();

        if (!$path || !$archive->exists($path)) {
            throw $this->createNotFoundException('Le fichier d\'origine de cette facture n\'a pas été conservé.');
        }

        return new BinaryFileResponse(
            $archive->absolutePath($path),
            Response::HTTP_OK,
            ['Content-Type' => $invoice->getAttachmentMime() ?? 'application/octet-stream'],
            false,
            // En ligne : on la lit à côté du formulaire de saisie, on ne la
            // télécharge pas pour la ranger dans un dossier.
            ResponseHeaderBag::DISPOSITION_INLINE
        );
    }

    /** Une facture en quarantaine se saisit ; une facture lue se valide. */
    private function reviewRoute(PurchaseInvoice $invoice): string
    {
        return $invoice->isToCapture() ? 'app_invoice_capture' : 'app_invoice_review';
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    #[Route('/factures/{id}', name: 'app_invoice_review', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function review(
        PurchaseInvoice $invoice,
        IngredientRepository $ingRepo,
        IngredientCategoryRepository $catRepo,
        IngredientPriceRepository $priceRepo,
        InvoiceInbox $inbox,
    ): Response {
        return $this->render('invoice/confirm.html.twig', [
            'purchaseInvoice' => $invoice,
            'invoice'         => $this->buildViewModel($invoice, $priceRepo, $inbox),
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
    private function buildViewModel(PurchaseInvoice $invoice, IngredientPriceRepository $priceRepo, InvoiceInbox $inbox): array
    {
        $lines = [];

        foreach ($invoice->getLines() as $line) {
            $previous   = null;
            $delta      = null;
            $targetUnit = $line->getUnit();
            $converted  = $line->getPriceHt();
            $conversion = UnitConverter::OK;

            if ($ingredient = $line->getIngredient()) {
                // Le prix affiché est celui qui sera enregistré : ramené à
                // l'unité de base de l'ingrédient, pas celui de la facture.
                $targetUnit = $ingredient->getBaseUnit();
                $conversion = $inbox->conversionStatus($line, $ingredient);
                $converted  = $inbox->convertedPrice($line, $ingredient);

                $known = $priceRepo->findBy(
                    ['ingredient' => $ingredient],
                    ['effectiveDate' => 'DESC', 'id' => 'DESC'],
                    1
                );
                if ($known) {
                    $previous = $known[0]->getPriceHt();
                    if ($previous > 0 && $converted !== null) {
                        $delta = round((($converted - $previous) / $previous) * 100, 1);
                    }
                }
            }

            $lines[] = [
                'name'               => $line->getDescription(),
                'supplier_ref'       => $line->getSupplierRef(),
                'qty_billed'         => $line->getQty(),
                'unit_code'          => $line->getUnitCode(),
                // « unit » est l'unité dans laquelle le prix sera enregistré ;
                // l'unité facturée reste visible via billed_unit.
                'unit'               => $targetUnit,
                'billed_unit'        => $line->getUnit(),
                'billed_price'       => $line->getPriceHt(),
                'conversion'         => $conversion,
                'price_ht'           => $converted,
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
