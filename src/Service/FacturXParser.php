<?php
namespace App\Service;

/**
 * Extrait et parse les données structurées d'une facture électronique.
 *
 * Formats supportés :
 *   - PDF Factur-X (XML CII embarqué en pièce jointe)
 *   - XML CII pur (UN/CEFACT CrossIndustryInvoice)
 *   - UBL 2.1 (format alternatif, support basique)
 *
 * Usage :
 *   $invoice = $parser->parse($fileContent, $mimeType);
 *   // $invoice = ['invoice_id', 'issue_date', 'seller_name', 'lines' => [...]]
 */
class FacturXParser
{
    // ─── Point d'entrée principal ────────────────────────────────────────────

    /**
     * Parse un fichier facture (PDF Factur-X ou XML pur).
     *
     * @param string $fileContent  Contenu binaire du fichier
     * @param string $mimeType     'application/pdf' | 'application/xml' | 'text/xml'
     * @return array|null          Données structurées ou null si non parseable
     */
    public function parse(string $fileContent, string $mimeType): ?array
    {
        $xml = match(true) {
            str_contains($mimeType, 'pdf')                     => $this->extractXmlFromPdf($fileContent),
            str_contains($mimeType, 'xml'), str_starts_with(ltrim($fileContent), '<?xml') => $fileContent,
            default                                            => null,
        };

        if (!$xml) return null;

        return $this->parseXml(trim($xml));
    }

    // ─── Extraction XML depuis un PDF Factur-X ───────────────────────────────

    /**
     * Extrait le fichier factur-x.xml embarqué dans un PDF/A-3.
     *
     * Stratégie 1 : commande shell `pdfdetach` (poppler-utils) — le plus fiable
     * Stratégie 2 : scan des flux compressés du PDF en PHP pur — fallback universel
     */
    private function extractXmlFromPdf(string $pdfContent): ?string
    {
        // Stratégie 1 : pdfdetach (poppler-utils)
        $xml = $this->extractViaPdfdetach($pdfContent);
        if ($xml) return $xml;

        // Stratégie 2 : scan PHP des flux FlateDecode
        return $this->extractViaStreamScan($pdfContent);
    }

    /** Utilise pdfdetach du paquet poppler-utils si disponible */
    private function extractViaPdfdetach(string $pdfContent): ?string
    {
        if (!$this->commandExists('pdfdetach')) return null;

        $tmpPdf = tempnam(sys_get_temp_dir(), 'facturx_') . '.pdf';
        $tmpDir = sys_get_temp_dir() . '/facturx_' . uniqid();

        try {
            file_put_contents($tmpPdf, $pdfContent);
            mkdir($tmpDir, 0700, true);

            exec(sprintf('pdfdetach -saveall -savepath %s %s 2>/dev/null',
                escapeshellarg($tmpDir),
                escapeshellarg($tmpPdf)
            ));

            // Chercher le fichier factur-x.xml dans le dossier d'extraction
            foreach (glob($tmpDir . '/*.xml') as $xmlFile) {
                $content = file_get_contents($xmlFile);
                if ($this->looksLikeInvoiceXml($content)) {
                    return $content;
                }
            }
        } finally {
            @unlink($tmpPdf);
            if (is_dir($tmpDir)) {
                array_map('unlink', glob($tmpDir . '/*'));
                @rmdir($tmpDir);
            }
        }

        return null;
    }

    /**
     * Scan PHP pur : parcourt tous les flux compressés du PDF,
     * décompresse chacun et cherche le XML de facture.
     * Fonctionne sans dépendance système.
     */
    private function extractViaStreamScan(string $pdfContent): ?string
    {
        $offset = 0;
        $pdfLen = strlen($pdfContent);

        while ($offset < $pdfLen) {
            $streamPos = strpos($pdfContent, 'stream', $offset);
            if ($streamPos === false) break;

            // Avancer après le "stream\r\n" ou "stream\n"
            $nl = strpos($pdfContent, "\n", $streamPos);
            if ($nl === false) { $offset = $streamPos + 6; continue; }
            $dataStart = $nl + 1;

            $endPos = strpos($pdfContent, 'endstream', $dataStart);
            if ($endPos === false) { $offset = $dataStart; continue; }

            $raw = substr($pdfContent, $dataStart, $endPos - $dataStart);
            $raw = rtrim($raw, "\r\n");

            // Essayer les deux variantes de décompression gzip/deflate
            foreach ([
                         fn($d) => @gzuncompress($d),
                         fn($d) => @gzinflate($d),
                         fn($d) => @gzdecode($d),
                     ] as $decompress) {
                $data = $decompress($raw);
                if ($data !== false && $this->looksLikeInvoiceXml($data)) {
                    return $data;
                }
            }

            $offset = $endPos + 9;
        }

        return null;
    }

    // ─── Parsing XML CII (CrossIndustryInvoice) ──────────────────────────────

    private function parseXml(string $xmlContent): ?array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        if (!$xml) return null;

        $ns = [
            'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
            'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
            'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
        ];

        foreach ($ns as $prefix => $uri) {
            $xml->registerXPathNamespace($prefix, $uri);
        }

        // Métadonnées facture
        $invoiceId  = $this->xval($xml, '//rsm:ExchangedDocument/ram:ID');
        $issueDate  = $this->xval($xml, '//rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString');
        $sellerName = $this->xval($xml, '//ram:SellerTradeParty/ram:Name');
        $buyerName  = $this->xval($xml, '//ram:BuyerTradeParty/ram:Name');

        // Formater la date YYYYMMDD → Y-m-d
        if ($issueDate && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $issueDate, $m)) {
            $issueDate = "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        // Lignes de facture
        $lines = [];
        foreach ($xml->xpath('//ram:IncludedSupplyChainTradeLineItem') as $item) {
            $lines[] = $this->parseLineItem($item);
        }

        return [
            'invoice_id'  => $invoiceId  ?: 'N/A',
            'issue_date'  => $issueDate  ?: date('Y-m-d'),
            'seller_name' => $sellerName ?: 'Fournisseur inconnu',
            'buyer_name'  => $buyerName  ?: '',
            'lines'       => array_filter($lines, fn($l) => !empty($l['name'])),
        ];
    }

    private function parseLineItem(\SimpleXMLElement $item): array
    {
        $product = $item->SpecifiedTradeProduct ?? null;
        $agreement = $item->SpecifiedLineTradeAgreement ?? null;
        $delivery  = $item->SpecifiedLineTradeDelivery  ?? null;
        $settlement= $item->SpecifiedLineTradeSettlement ?? null;

        $name = trim((string) ($product->Name ?? ''));
        $ref  = trim((string) ($product->SellerAssignedID ?? ''));

        // Prix unitaire HT
        $priceEl   = $agreement->NetPriceProductTradePrice ?? null;
        $priceHt   = $priceEl ? (float) ($priceEl->ChargeAmount ?? 0) : 0.0;
        $basisQty  = $priceEl->BasisQuantity ?? null;
        $unitCode  = $basisQty ? (string) $basisQty->attributes()->unitCode : '';

        // Quantité facturée
        $billedEl  = $delivery->BilledQuantity ?? null;
        $qtyBilled = $billedEl ? (float) $billedEl : 0.0;
        if (empty($unitCode)) {
            $unitCode = $billedEl ? (string) $billedEl->attributes()->unitCode : '';
        }

        // TVA
        $taxEl = $settlement->ApplicableTradeTax ?? null;
        $vat   = $taxEl ? (float) ($taxEl->RateApplicablePercent ?? 0) : 0.0;

        // Total ligne
        $lineTotal = 0.0;
        if ($settlement) {
            $sumEl = $settlement->SpecifiedTradeSettlementLineMonetarySummation ?? null;
            $lineTotal = $sumEl ? (float) ($sumEl->LineTotalAmount ?? 0) : $priceHt * $qtyBilled;
        }

        return [
            'name'         => $name,
            'supplier_ref' => $ref,
            'price_ht'     => round($priceHt, 4),
            'unit_code'    => strtoupper($unitCode),               // ex: KGM, LTR, PCE
            'unit'         => $this->mapUnitCode($unitCode),       // ex: kg, litre, piece
            'qty_billed'   => $qtyBilled,
            'vat_rate'     => $vat,
            'line_total'   => $lineTotal,
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Mappe les codes unité UN/CEFACT vers les unités de l'appli */
    private function mapUnitCode(string $code): string
    {
        return match(strtoupper($code)) {
            'KGM'        => 'kg',
            'GRM', 'GRM' => 'g',
            'LTR', 'MTQ' => 'litre',
            'PCE', 'H87', 'EA', 'C62' => 'piece',
            default      => 'kg',
        };
    }

    /** XPath helper → première valeur trouvée en string */
    private function xval(\SimpleXMLElement $xml, string $xpath): string
    {
        $res = $xml->xpath($xpath);
        return $res ? trim((string) $res[0]) : '';
    }

    /** Vérifie qu'un contenu décompressé ressemble à un XML de facture */
    private function looksLikeInvoiceXml(string $data): bool
    {
        return (str_contains($data, 'CrossIndustryInvoice') || str_contains($data, 'UBL:Invoice'))
            && (str_contains($data, '<?xml') || str_starts_with(ltrim($data), '<'));
    }

    private function commandExists(string $cmd): bool
    {
        return !empty(shell_exec("which $cmd 2>/dev/null"));
    }
}