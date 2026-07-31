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
        private InvoiceArchive $archive,
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
     * Une pièce que le moteur ne sait pas lire n'est plus refusée : elle est
     * conservée et mise en attente de saisie. C'est la condition pour ouvrir un
     * canal automatique — jusqu'en septembre 2027, la plupart des fournisseurs
     * enverront un PDF ordinaire, et une réception qui rejette n'aurait fait que
     * perdre des factures sans personne pour s'en apercevoir.
     *
     * @param array{from?: string, subject?: string, date?: ?\DateTimeInterface, filename?: string} $mail
     *
     * @return array{invoice: PurchaseInvoice|null, duplicate: bool, captured: bool, error: string|null}
     */
    public function receive(string $payload, string $mime, string $source = PurchaseInvoice::SOURCE_MANUAL, array $mail = []): array
    {
        $hash = $this->archive->hash($payload);

        // Doublon de fichier : le même document, quel qu'en soit le contenu.
        if ($existing = $this->invoices->findByPayloadHash($hash)) {
            return ['invoice' => $existing, 'duplicate' => true, 'captured' => false, 'error' => null];
        }

        $parsed = $this->parser->parse($payload, $mime);

        if (!$parsed || empty($parsed['lines'])) {
            return $this->quarantine($payload, $mime, $hash, $source, $mail, $parsed);
        }

        $supplier = $this->resolveSupplier($parsed);
        $this->learnSenderAddress($supplier, $mail['from'] ?? null);

        // Le fournisseur doit exister en base avant la recherche de doublon.
        $this->em->flush();

        // Doublon logique : même facture réémise dans un fichier différent.
        if ($existing = $this->invoices->findDuplicate($supplier, $parsed['invoice_id'])) {
            return ['invoice' => $existing, 'duplicate' => true, 'captured' => false, 'error' => null];
        }

        $invoice = new PurchaseInvoice();
        $invoice->setSupplier($supplier);
        $invoice->setInvoiceId($parsed['invoice_id']);
        $invoice->setInvoiceDate(new \DateTime($parsed['issue_date']));
        $invoice->setTotalHt($parsed['total_ht'] ?? null);
        $invoice->setTotalTtc($parsed['total_ttc'] ?? null);
        $invoice->setStatus(PurchaseInvoice::STATUS_PENDING);
        $invoice->setSource($source);
        // Seul le XML est conservé en base : un PDF porteur y pèserait lourd
        // pour rien, et c'est le XML qui porte les données rejouables. Le
        // fichier d'origine, lui, part dans l'archive sur disque.
        $invoice->setRawPayload(str_starts_with(ltrim($payload), '<?xml') ? $payload : null);
        $this->attachFile($invoice, $payload, $mime, $hash, $mail);
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

        return ['invoice' => $invoice, 'duplicate' => false, 'captured' => false, 'error' => null];
    }

    /**
     * Enregistre une pièce que le moteur ne sait pas exploiter.
     *
     * La facture existe, elle est horodatée, son fichier est conservé et elle
     * est rattachée à son fournisseur quand l'expéditeur permet de le
     * reconnaître. Il ne manque que les lignes, qu'un humain saisira. Aucune
     * valeur n'est devinée : mieux vaut un champ vide qu'un montant inventé.
     *
     * @param array{from?: string, subject?: string, date?: ?\DateTimeInterface, filename?: string} $mail
     *
     * @return array{invoice: PurchaseInvoice, duplicate: bool, captured: bool, error: string|null}
     */
    private function quarantine(string $payload, string $mime, string $hash, string $source, array $mail, ?array $parsed): array
    {
        $invoice = new PurchaseInvoice();

        // Un Factur-X lu mais sans ligne exploitable garde tout ce qu'il a livré :
        // ne restent à saisir que les lignes.
        if ($parsed) {
            $invoice->setSupplier($this->resolveSupplier($parsed));
            $invoice->setInvoiceId($parsed['invoice_id']);
            $invoice->setInvoiceDate(new \DateTime($parsed['issue_date']));
            $invoice->setTotalHt($parsed['total_ht'] ?? null);
            $invoice->setTotalTtc($parsed['total_ttc'] ?? null);
            $invoice->setNote('Facture lue, mais aucune ligne exploitable : les lignes restent à saisir.');
        } else {
            $supplier = $this->suppliers->findByEmail($mail['from'] ?? null);
            $invoice->setSupplier($supplier);
            // Pas de numéro connu : une référence dérivée de l'empreinte tient
            // la contrainte d'unicité sans prétendre être le numéro du
            // fournisseur. L'affichage montre le nom du fichier reçu.
            $invoice->setInvoiceId('PJ-' . substr($hash, 0, 12));
            $invoice->setInvoiceDate(\DateTime::createFromInterface(
                $mail['date'] ?? new \DateTimeImmutable()
            ));
            $invoice->setNote($supplier
                ? 'Pièce jointe sans Factur-X : fournisseur reconnu à l\'adresse d\'expédition, lignes à saisir.'
                : 'Pièce jointe sans Factur-X : fournisseur et lignes à saisir.');
        }

        $invoice->setStatus(PurchaseInvoice::STATUS_TO_CAPTURE);
        $invoice->setSource($source);
        $invoice->setRawPayload(str_starts_with(ltrim($payload), '<?xml') ? $payload : null);
        $this->attachFile($invoice, $payload, $mime, $hash, $mail);

        $this->em->persist($invoice);
        $this->em->flush();

        return ['invoice' => $invoice, 'duplicate' => false, 'captured' => true, 'error' => null];
    }

    /**
     * Bascule une facture en attente de saisie vers la file de validation.
     *
     * Les libellés saisis passent par le même rapprochement que ceux d'un
     * Factur-X : la mémoire des correspondances déjà tranchées profite aussi à
     * la saisie manuelle, et une facture saisie se valide exactement comme une
     * facture lue.
     *
     * @param list<array{description?: string, qty?: string, unit?: string, price_ht?: string, vat_rate?: string}> $rows
     *
     * @return int Nombre de lignes retenues.
     */
    public function capture(PurchaseInvoice $invoice, array $rows): int
    {
        $candidates = [];

        foreach ($rows as $row) {
            $description = trim((string) ($row['description'] ?? ''));
            $price       = $this->decimal($row['price_ht'] ?? null);

            // Une ligne sans libellé ni prix est une ligne vide du formulaire.
            if ($description === '' || $price === null) {
                continue;
            }

            $candidates[] = [
                'name'         => $description,
                'supplier_ref' => null,
                'qty_billed'   => $this->decimal($row['qty'] ?? null) ?? 1.0,
                'unit'         => trim((string) ($row['unit'] ?? 'kg')) ?: 'kg',
                'unit_code'    => 'KGM',
                'price_ht'     => $price,
                'vat_rate'     => $this->decimal($row['vat_rate'] ?? null) ?? 5.5,
                'line_total'   => 0.0,
            ];
        }

        if (!$candidates) {
            return 0;
        }

        foreach ($this->matcher->matchLines($candidates) as $parsedLine) {
            $line = new PurchaseInvoiceLine();
            $line->setDescription($parsedLine['name']);
            $line->setQty((float) $parsedLine['qty_billed']);
            $line->setUnit($parsedLine['unit']);
            $line->setUnitCode($parsedLine['unit_code']);
            $line->setPriceHt((float) $parsedLine['price_ht']);
            $line->setVatRate((float) $parsedLine['vat_rate']);
            $line->setLineTotal(round((float) $parsedLine['qty_billed'] * (float) $parsedLine['price_ht'], 2));
            $line->setIngredient($parsedLine['matched_ingredient']);
            $line->setMatchSource($parsedLine['match_source']);
            $line->setMatchScore((int) $parsedLine['match_score']);

            $invoice->addLine($line);
            $this->em->persist($line);
        }

        $invoice->setStatus(PurchaseInvoice::STATUS_PENDING);
        $invoice->setNote(null);
        $this->em->flush();

        return count($candidates);
    }

    /**
     * Rattache une facture en quarantaine à son fournisseur, et retient
     * l'adresse d'expédition pour que les suivantes se rattachent seules.
     *
     * Le numéro saisi peut désigner une facture déjà enregistrée : le même
     * document reçu une fois en Factur-X et une fois en PDF scanné a deux
     * empreintes, donc la déduplication par fichier ne l'a pas vu. On refuse
     * alors le rattachement et on rend la facture en cause, plutôt que de
     * laisser la contrainte d'unicité produire une erreur serveur.
     *
     * @return PurchaseInvoice|null La facture en conflit, ou null si tout va bien.
     */
    public function assignSupplier(PurchaseInvoice $invoice, Supplier $supplier, ?string $invoiceId = null): ?PurchaseInvoice
    {
        $invoiceId = trim((string) $invoiceId);

        if ($invoiceId !== '') {
            $existing = $this->invoices->findDuplicate($supplier, $invoiceId);

            if ($existing && $existing->getId() !== $invoice->getId()) {
                return $existing;
            }

            $invoice->setInvoiceId($invoiceId);
        }

        $invoice->setSupplier($supplier);
        $this->learnSenderAddress($supplier, $invoice->getSenderEmail());
        $this->em->flush();

        return null;
    }

    /**
     * Mémorise l'adresse d'expédition d'un fournisseur qui n'en avait pas.
     *
     * Jamais d'écrasement : une adresse déjà renseignée a été choisie, soit à la
     * main, soit par un rattachement antérieur. Les domaines grand public sont
     * écartés — ils ne désignent personne.
     */
    private function learnSenderAddress(?Supplier $supplier, ?string $email): void
    {
        $email = strtolower(trim((string) $email));

        if (!$supplier || $supplier->getEmail() || $email === '' || !str_contains($email, '@')) {
            return;
        }

        // Le dépôt sait quels domaines n'identifient personne.
        if (!$this->suppliers->isIdentifyingAddress($email)) {
            return;
        }

        $supplier->setEmail($email);
    }

    private function attachFile(PurchaseInvoice $invoice, string $payload, string $mime, string $hash, array $mail): void
    {
        $invoice->setPayloadHash($hash);
        $invoice->setAttachmentPath($this->archive->store($payload, $hash, $mime));
        $invoice->setAttachmentName($mail['filename'] ?? ($hash . ($mime === 'application/pdf' ? '.pdf' : '.xml')));
        $invoice->setAttachmentMime($mime);
        $invoice->setAttachmentSize(strlen($payload));
        $invoice->setSenderEmail($mail['from'] ?? null);
        $invoice->setMailSubject($mail['subject'] ?? null);
    }

    /** Accepte la virgule décimale : c'est ce qui sera tapé. */
    private function decimal(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return (float) str_replace([' ', ','], ['', '.'], $value);
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
