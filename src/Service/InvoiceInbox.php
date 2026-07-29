<?php
namespace App\Service;

use App\Entity\Ingredient;
use App\Entity\IngredientPrice;
use App\Entity\PurchaseInvoice;
use App\Entity\PurchaseInvoiceLine;
use App\Entity\Supplier;
use App\Repository\IngredientCategoryRepository;
use App\Repository\IngredientRepository;
use App\Repository\PurchaseInvoiceRepository;
use App\Repository\SupplierRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Boîte de réception des factures d'achat.
 *
 * Sépare deux temps qui étaient confondus :
 *   - la RÉCEPTION, sans utilisateur : analyse, identification du fournisseur,
 *     enregistrement de la facture et des correspondances proposées ;
 *   - la VALIDATION, avec un utilisateur : arbitrage ligne par ligne, création
 *     des prix, recalcul des recettes.
 *
 * Cette séparation est ce qui permettra à une facture d'arriver seule (boîte de
 * réception dédiée, plateforme agréée) : le canal d'entrée n'a plus qu'à appeler
 * receive(), la file d'attente fait le reste.
 */
class InvoiceInbox
{
    public function __construct(
        private EntityManagerInterface $em,
        private FacturXParser $parser,
        private InvoiceLinesMatcher $matcher,
        private PurchaseInvoiceRepository $invoices,
        private SupplierRepository $suppliers,
        private IngredientRepository $ingredients,
        private IngredientCategoryRepository $categories,
        private CostCalculator $calc,
        private UnitConverter $units,
    ) {}

    /**
     * Prix de la ligne ramené à l'unité de base de l'ingrédient.
     *
     * Une facture au gramme ou au colis ne parle pas la même unité que le
     * catalogue : sans cette conversion, 0,0084 €/g s'enregistrerait comme
     * 0,0084 €/kg. Null quand aucune conversion n'est possible — il faut alors
     * un prix saisi à la main, pas un chiffre inventé.
     */
    public function convertedPrice(PurchaseInvoiceLine $line, Ingredient $ingredient): ?float
    {
        return $this->units->convertPrice(
            $line->getPriceHt(),
            $line->getUnit(),
            $ingredient->getBaseUnit(),
            $ingredient->getUnitWeightG()
        );
    }

    /** Statut de conversion d'une ligne vers l'unité de son ingrédient. */
    public function conversionStatus(PurchaseInvoiceLine $line, Ingredient $ingredient): string
    {
        return $this->units->status(
            $line->getUnit(),
            $ingredient->getBaseUnit(),
            $ingredient->getUnitWeightG()
        );
    }

    /**
     * Enregistre une facture entrante et propose des correspondances.
     *
     * @return array{invoice: PurchaseInvoice|null, duplicate: bool, error: string|null}
     */
    public function receive(string $payload, string $mime, string $source = PurchaseInvoice::SOURCE_MANUAL): array
    {
        $parsed = $this->parser->parse($payload, $mime);

        if (!$parsed) {
            return ['invoice' => null, 'duplicate' => false, 'error' => 'Format illisible : PDF Factur-X ou XML CII/EN 16931 attendu.'];
        }
        if (empty($parsed['lines'])) {
            return ['invoice' => null, 'duplicate' => false, 'error' => sprintf('Facture « %s » lue, mais aucune ligne exploitable.', $parsed['invoice_id'])];
        }

        $supplier = $this->resolveSupplier($parsed);

        // Le fournisseur doit exister en base avant la recherche de doublon.
        $this->em->flush();

        if ($existing = $this->invoices->findDuplicate($supplier, $parsed['invoice_id'])) {
            return ['invoice' => $existing, 'duplicate' => true, 'error' => null];
        }

        $invoice = new PurchaseInvoice();
        $invoice->setSupplier($supplier);
        $invoice->setInvoiceId($parsed['invoice_id']);
        $invoice->setInvoiceDate(new \DateTime($parsed['issue_date']));
        $invoice->setTotalHt($parsed['total_ht'] ?? null);
        $invoice->setTotalTtc($parsed['total_ttc'] ?? null);
        $invoice->setStatus(PurchaseInvoice::STATUS_PENDING);
        $invoice->setSource($source);
        // Seul le XML est conservé : un PDF porteur pèse lourd pour rien, et
        // c'est le XML qui porte les données rejouables.
        $invoice->setRawPayload(str_starts_with(ltrim($payload), '<?xml') ? $payload : null);
        $this->em->persist($invoice);

        foreach ($this->matcher->matchLines($parsed['lines']) as $parsedLine) {
            $line = new PurchaseInvoiceLine();
            $line->setSupplierRef($parsedLine['supplier_ref'] ?? null);
            $line->setDescription($parsedLine['name']);
            $line->setQty((float) ($parsedLine['qty_billed'] ?? 0));
            $line->setUnitCode($parsedLine['unit_code'] ?? 'KGM');
            $line->setUnit($parsedLine['unit'] ?? 'kg');
            $line->setPriceHt((float) $parsedLine['price_ht']);
            $line->setVatRate((float) ($parsedLine['vat_rate'] ?? 5.5));
            $line->setLineTotal((float) ($parsedLine['line_total'] ?? 0));
            $line->setIngredient($parsedLine['matched_ingredient']);
            $line->setMatchSource($parsedLine['match_source']);
            $line->setMatchScore((int) $parsedLine['match_score']);

            $invoice->addLine($line);
            $this->em->persist($line);
        }

        $this->em->flush();

        return ['invoice' => $invoice, 'duplicate' => false, 'error' => null];
    }

    /**
     * Applique les décisions prises sur une facture en attente.
     *
     * @param array<int,array<string,mixed>> $decisions décisions indexées comme les lignes affichées
     * @return array{prices: int, ingredients: int, skipped: int}
     */
    public function apply(PurchaseInvoice $invoice, array $decisions): array
    {
        $lines       = array_values($invoice->getLines()->toArray());
        $operations  = [];
        $ingsCreated = 0;

        // ── Passe 1 : résoudre ou créer les ingrédients ──────────────────────
        foreach ($lines as $i => $line) {
            $decision = $decisions[$i] ?? [];
            if (empty($decision['confirmed'])) {
                continue;
            }

            $mode       = $decision['mode'] ?? 'existing';
            $ingredient = null;

            if ($mode === 'create') {
                $name = trim((string) ($decision['new_ingredient_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $ingredient = new Ingredient();
                $ingredient->setName($name);
                $ingredient->setBaseUnit($decision['new_ingredient_unit'] ?? $line->getUnit());
                $ingredient->setVatRate((float) ($decision['new_ingredient_vat'] ?? $line->getVatRate()));
                $ingredient->setDefaultSupplier($invoice->getSupplier()?->getName());

                $catId = (int) ($decision['new_ingredient_category_id'] ?? 0);
                if ($catId && $cat = $this->categories->find($catId)) {
                    $ingredient->setCategory($cat);
                } else {
                    $ingredient->setCategory($this->categories->findOneBy(['name' => 'Autres']));
                }

                $this->em->persist($ingredient);
                $ingsCreated++;
            } elseif ($mode === 'existing') {
                $ingredient = $this->ingredients->find((int) ($decision['ingredient_id'] ?? 0));
            }

            if (!$ingredient) {
                continue;
            }

            $operations[] = ['line' => $line, 'ingredient' => $ingredient, 'decision' => $decision];
        }

        if (!$operations) {
            return ['prices' => 0, 'ingredients' => 0, 'skipped' => count($lines), 'unit_issues' => []];
        }

        // Les nouveaux ingrédients doivent avoir un id avant d'être référencés.
        $this->em->flush();

        // ── Passe 2 : prix, mémorisation, traçabilité ────────────────────────
        $affected   = [];
        $applied    = 0;
        $unitIssues = [];

        foreach ($operations as $op) {
            /** @var PurchaseInvoiceLine $line */
            $line       = $op['line'];
            $ingredient = $op['ingredient'];

            // Le formulaire affiche déjà des prix ramenés à l'unité de base :
            // une valeur saisie est donc prise telle quelle. En son absence
            // (canal automatique), la conversion est faite ici.
            $submitted = $op['decision']['price_ht'] ?? null;
            $priceHt   = ($submitted !== null && $submitted !== '')
                ? (float) $submitted
                : $this->convertedPrice($line, $ingredient);

            if ($priceHt === null) {
                // Unité inconvertible et aucun prix fourni : mieux vaut ne rien
                // enregistrer qu'un prix faux d'un facteur mille.
                $unitIssues[] = sprintf(
                    '%s (%s → %s)',
                    $line->getDescription(),
                    $line->getUnit(),
                    $ingredient->getBaseUnit()
                );
                continue;
            }

            $line->setIngredient($ingredient);
            $line->setApplied(true);

            $price = new IngredientPrice();
            $price->setIngredient($ingredient);
            $price->setPriceHt($priceHt);
            $price->setSupplierEntity($invoice->getSupplier());
            $price->setPurchaseInvoice($invoice);
            $price->setEffectiveDate(new \DateTime(
                $op['decision']['effective_date'] ?? $invoice->getInvoiceDate()->format('Y-m-d')
            ));
            $this->em->persist($price);

            // Mémorise le libellé fournisseur : le prochain import reconnaîtra seul.
            $this->matcher->saveMapping($line->getDescription(), $ingredient);

            $affected[] = $ingredient->getId();
            $applied++;
        }

        if ($applied === 0) {
            // Rien n'a pu être enregistré : la facture reste en attente pour que
            // l'utilisateur corrige, au lieu d'être classée comme traitée à vide.
            $this->em->flush();

            return ['prices' => 0, 'ingredients' => $ingsCreated, 'skipped' => count($lines), 'unit_issues' => $unitIssues];
        }

        $invoice->setStatus(PurchaseInvoice::STATUS_APPLIED);
        $invoice->setAppliedAt(new \DateTimeImmutable());

        $skipped = $invoice->getPendingLineCount();
        $notes   = [];
        if ($skipped > 0) {
            $notes[] = sprintf('%d ligne(s) non reprise(s) à la validation.', $skipped);
        }
        if ($unitIssues) {
            $notes[] = 'Unité inconvertible, prix non enregistré : ' . implode(', ', $unitIssues);
        }
        $invoice->setNote($notes ? implode(' ', $notes) : null);

        $this->em->flush();

        foreach (array_unique(array_filter($affected)) as $ingredientId) {
            $this->calc->recalculateAll($ingredientId);
        }

        return ['prices' => $applied, 'ingredients' => $ingsCreated, 'skipped' => $skipped, 'unit_issues' => $unitIssues];
    }

    public function reject(PurchaseInvoice $invoice, ?string $reason = null): void
    {
        $invoice->setStatus(PurchaseInvoice::STATUS_REJECTED);
        $invoice->setNote($reason);
        $this->em->flush();
    }

    /**
     * Retrouve le fournisseur de la facture, l'enrichit ou le crée.
     * Le SIRET fait foi ; le nom sert de repli.
     */
    private function resolveSupplier(array $parsed): Supplier
    {
        $supplier = $this->suppliers->findByIdentifier(
            $parsed['seller_name'],
            $parsed['seller_siret'] ?? null
        );

        if (!$supplier) {
            $supplier = new Supplier();
            $supplier->setName($parsed['seller_name']);
            $supplier->setSiret($parsed['seller_siret'] ?? null);
            $supplier->setVatNumber($parsed['seller_vat'] ?? null);
            $supplier->setAddressLine($parsed['seller_address'] ?? null);
            $supplier->setPostcode($parsed['seller_postcode'] ?? null);
            $supplier->setCity($parsed['seller_city'] ?? null);
            $supplier->setCountry($parsed['seller_country'] ?? null);
            $this->em->persist($supplier);
        } else {
            if (!$supplier->getSiret() && !empty($parsed['seller_siret'])) {
                $supplier->setSiret($parsed['seller_siret']);
            }
            if (!$supplier->getCity() && !empty($parsed['seller_city'])) {
                $supplier->setCity($parsed['seller_city']);
                $supplier->setPostcode($parsed['seller_postcode'] ?? null);
            }
        }

        $invoiceDate = new \DateTimeImmutable($parsed['issue_date']);
        if (!$supplier->getLastInvoiceDate() || $supplier->getLastInvoiceDate() < $invoiceDate) {
            $supplier->setLastInvoiceDate($invoiceDate);
        }

        return $supplier;
    }
}
