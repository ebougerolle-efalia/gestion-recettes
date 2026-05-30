<?php
namespace App\Controller;

use App\Entity\IngredientPrice;
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

    /**
     * Affiche le formulaire d'upload et traite le fichier soumis.
     * Accepte : PDF Factur-X ou XML CII brut.
     */
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

        // ── Validation du fichier ────────────────────────────────────────────
        $file = $request->files->get('invoice_file');

        if (!$file || !$file->isValid()) {
            $this->addFlash('danger', 'Aucun fichier valide reçu.');
            return $this->render('invoice/import.html.twig');
        }

        $allowed = ['application/pdf', 'application/xml', 'text/xml', 'application/octet-stream'];
        $mime    = $file->getMimeType() ?? '';
        $ext     = strtolower($file->getClientOriginalExtension());

        if (!in_array($mime, $allowed) && !in_array($ext, ['pdf', 'xml'])) {
            $this->addFlash('danger', 'Format non supporté. Envoyez un PDF Factur-X ou un fichier XML.');
            return $this->render('invoice/import.html.twig');
        }

        // Forcer le mime type selon l'extension si détection auto incorrecte
        if ($ext === 'xml') $mime = 'application/xml';
        if ($ext === 'pdf') $mime = 'application/pdf';

        // ── Parsing ──────────────────────────────────────────────────────────
        $fileContent = file_get_contents($file->getPathname());
        $invoice     = $parser->parse($fileContent, $mime);

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

        // ── Matching ingrédients ─────────────────────────────────────────────
        $invoice['lines'] = $matcher->matchLines($invoice['lines']);

        // ── Stocker en session et rediriger vers la confirmation ─────────────
        $session->set('invoice_import', $invoice);

        $this->addFlash('info',
            sprintf(
                'Facture %s de %s : %d ligne(s) trouvée(s).',
                $invoice['invoice_id'],
                $invoice['seller_name'],
                count($invoice['lines'])
            )
        );

        return $this->redirectToRoute('app_invoice_confirm');
    }

    // ─── Étape 2 : Confirmation ──────────────────────────────────────────────

    /**
     * Affiche le tableau de confirmation des correspondances.
     * L'utilisateur peut corriger chaque association et valider les prix.
     */
    #[Route('/factures/confirmer', name: 'app_invoice_confirm', methods: ['GET', 'POST'])]
    public function confirm(
        Request             $request,
        SessionInterface    $session,
        InvoiceLinesMatcher $matcher,
        IngredientRepository $ingRepo,
        EntityManagerInterface $em,
        CostCalculator      $calc,
    ): Response {
        $invoice = $session->get('invoice_import');

        if (!$invoice) {
            $this->addFlash('warning', 'Session expirée. Veuillez ré-importer la facture.');
            return $this->redirectToRoute('app_invoice_import');
        }

        $ingredients = $ingRepo->findBy([], ['name' => 'ASC']);

        // ── Traitement de la confirmation ────────────────────────────────────
        if ($request->isMethod('POST')) {
            $formLines = $request->request->all('lines') ?? [];
            $pricesCreated = 0;
            $affectedIngIds = [];

            foreach ($invoice['lines'] as $i => $line) {
                $formLine = $formLines[$i] ?? [];

                // Ignorer les lignes non cochées
                if (empty($formLine['confirmed'])) continue;

                $ingId = (int) ($formLine['ingredient_id'] ?? 0);
                if (!$ingId) continue;

                $ingredient = $ingRepo->find($ingId);
                if (!$ingredient) continue;

                // Créer l'IngredientPrice
                $priceHt   = (float) ($formLine['price_ht']     ?? $line['price_ht']);
                $effDate   = $formLine['effective_date']         ?? $invoice['issue_date'];
                $supplier  = $formLine['supplier']               ?? $invoice['seller_name'];

                $price = new IngredientPrice();
                $price->setIngredient($ingredient);
                $price->setPriceHt($priceHt);
                $price->setSupplier($supplier);
                $price->setEffectiveDate(new \DateTime($effDate));
                $em->persist($price);

                // Mémoriser l'association pour les prochains imports
                $matcher->saveMapping($line['name'], $ingredient);

                $affectedIngIds[] = $ingId;
                $pricesCreated++;
            }

            $em->flush();

            // Recalculer les coûts des recettes impactées
            foreach (array_unique($affectedIngIds) as $ingId) {
                $calc->recalculateAll($ingId);
            }

            $session->remove('invoice_import');

            $this->addFlash('success', sprintf(
                '%d prix enregistré(s) depuis la facture %s. Les coûts des recettes ont été mis à jour.',
                $pricesCreated,
                $invoice['invoice_id']
            ));

            return $this->redirectToRoute('app_ingredient_index');
        }

        // ── Statistiques d'affichage ─────────────────────────────────────────
        $stats = [
            'total'    => count($invoice['lines']),
            'mapped'   => count(array_filter($invoice['lines'], fn($l) => $l['match_source'] === 'mapping')),
            'fuzzy'    => count(array_filter($invoice['lines'], fn($l) => $l['match_source'] === 'fuzzy')),
            'unmatched'=> count(array_filter($invoice['lines'], fn($l) => $l['match_source'] === 'none')),
        ];

        return $this->render('invoice/confirm.html.twig', [
            'invoice'     => $invoice,
            'ingredients' => $ingredients,
            'stats'       => $stats,
        ]);
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