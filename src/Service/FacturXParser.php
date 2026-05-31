<?php
namespace App\Service;

/**
 * FacturXParser v3 — extrait et parse les factures Factur-X / CII EN16931.
 *
 * Nouveautés v3 :
 *   - Extraction complète des données SellerTradeParty (SIRET, TVA, adresse)
 *   - Extraction des totaux facture (total_ht, total_ttc)
 *   - Retour enrichi utilisé pour créer les entités Supplier + PurchaseInvoice
 */
class FacturXParser
{
    public function parse(string $fileContent, string $mimeType): ?array
    {
        $ext = $this->guessExtension($fileContent, $mimeType);

        $xml = match($ext) {
            'pdf' => $this->extractXmlFromPdf($fileContent),
            'xml' => $fileContent,
            default => null,
        };

        if (!$xml || !$this->looksLikeInvoiceXml($xml)) return null;

        return $this->parseXml(trim($xml));
    }

    // ─── Extraction XML depuis PDF ────────────────────────────────────────────

    private function extractXmlFromPdf(string $pdfContent): ?string
    {
        if ($found = $this->scanRawBytes($pdfContent))      return $found;
        if ($found = $this->extractEmbeddedStream($pdfContent)) return $found;
        return $this->extractViaPdfdetach($pdfContent);
    }

    private function scanRawBytes(string $pdfContent): ?string
    {
        $pos = strpos($pdfContent, '<?xml');
        if ($pos === false) return null;
        foreach (['</rsm:CrossIndustryInvoice>', '</Invoice>', '</CII:CrossIndustryInvoice>'] as $tag) {
            $end = strpos($pdfContent, $tag, $pos);
            if ($end !== false) {
                $candidate = substr($pdfContent, $pos, $end - $pos + strlen($tag));
                if ($this->looksLikeInvoiceXml($candidate)) return $candidate;
            }
        }
        return null;
    }

    private function extractEmbeddedStream(string $pdfContent): ?string
    {
        foreach (['/EmbeddedFile', '/text#2Fxml', '/text/xml'] as $marker) {
            $dictPos = strpos($pdfContent, $marker);
            if ($dictPos === false) continue;
            $vicinity = substr($pdfContent, $dictPos, 400);
            if (!preg_match('/\/Length\s+(\d+)/', $vicinity, $lm)) continue;
            $streamLen = (int) $lm[1];
            if ($streamLen < 50 || $streamLen > 5_000_000) continue;
            $streamKw = strpos($pdfContent, 'stream', $dictPos);
            if ($streamKw === false) continue;
            $dataStart = $streamKw + 6;
            if (isset($pdfContent[$dataStart]) && $pdfContent[$dataStart] === "\r") $dataStart++;
            if (isset($pdfContent[$dataStart]) && $pdfContent[$dataStart] === "\n") $dataStart++;
            $compressed   = substr($pdfContent, $dataStart, $streamLen);
            $decompressed = @gzuncompress($compressed) ?: @gzinflate($compressed) ?: false;
            if ($decompressed !== false && $this->looksLikeInvoiceXml($decompressed)) return $decompressed;
        }
        return null;
    }

    private function extractViaPdfdetach(string $pdfContent): ?string
    {
        if (!$this->commandExists('pdfdetach')) return null;
        $tmpPdf = tempnam(sys_get_temp_dir(), 'facturx_') . '.pdf';
        $tmpDir = sys_get_temp_dir() . '/facturx_' . uniqid();
        try {
            file_put_contents($tmpPdf, $pdfContent);
            mkdir($tmpDir, 0700, true);
            exec(sprintf('pdfdetach -saveall -savepath %s %s 2>/dev/null', escapeshellarg($tmpDir), escapeshellarg($tmpPdf)));
            foreach (glob($tmpDir . '/*.xml') ?: [] as $xmlFile) {
                $content = file_get_contents($xmlFile);
                if ($this->looksLikeInvoiceXml($content)) return $content;
            }
        } finally {
            @unlink($tmpPdf);
            if (is_dir($tmpDir)) { array_map('unlink', glob($tmpDir . '/*') ?: []); @rmdir($tmpDir); }
        }
        return null;
    }

    // ─── Parsing XML CII ─────────────────────────────────────────────────────

    private function parseXml(string $xmlContent): ?array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($xmlContent)) return null;

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $xpath->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

        $get = fn(\DOMNode $ctx, string $path): string
            => trim((string) $xpath->evaluate("string($path)", $ctx));

        // ── Métadonnées facture ────────────────────────────────────────────
        $invoiceId = $get($dom, '//rsm:ExchangedDocument/ram:ID');
        $issueDate = $get($dom, '//rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString');
        if ($issueDate && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $issueDate, $m)) {
            $issueDate = "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        // ── Données fournisseur (SellerTradeParty) — NOUVEAU ──────────────
        $sellerName    = $get($dom, '//ram:SellerTradeParty/ram:Name');
        $sellerSiret   = $get($dom, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID');
        $sellerVat     = $get($dom, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID');
        $sellerAddr    = $get($dom, '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineOne');
        $sellerPostcode= $get($dom, '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode');
        $sellerCity    = $get($dom, '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CityName');
        $sellerCountry = $get($dom, '//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID');

        // ── Données acheteur ───────────────────────────────────────────────
        $buyerName = $get($dom, '//ram:BuyerTradeParty/ram:Name');

        // ── Totaux facture — NOUVEAU ───────────────────────────────────────
        $totalHt  = (float) $get($dom, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxBasisTotalAmount');
        $totalTtc = (float) $get($dom, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount');

        // ── Lignes ────────────────────────────────────────────────────────
        $lines = [];
        foreach ($xpath->query('//ram:IncludedSupplyChainTradeLineItem') as $item) {
            $lines[] = $this->parseLineItem($item, $xpath, $get);
        }

        return [
            // Facture
            'invoice_id'      => $invoiceId  ?: 'N/A',
            'issue_date'      => $issueDate  ?: date('Y-m-d'),
            'total_ht'        => $totalHt,
            'total_ttc'       => $totalTtc,
            // Fournisseur
            'seller_name'     => $sellerName    ?: 'Fournisseur inconnu',
            'seller_siret'    => $sellerSiret   ?: null,
            'seller_vat'      => $sellerVat     ?: null,
            'seller_address'  => $sellerAddr    ?: null,
            'seller_postcode' => $sellerPostcode?: null,
            'seller_city'     => $sellerCity    ?: null,
            'seller_country'  => $sellerCountry ?: null,
            // Acheteur
            'buyer_name'      => $buyerName,
            // Lignes
            'lines'           => array_values(array_filter($lines, fn($l) => !empty($l['name']))),
        ];
    }

    private function parseLineItem(\DOMNode $item, \DOMXPath $xpath, callable $get): array
    {
        $name      = $get($item, 'ram:SpecifiedTradeProduct/ram:Name');
        $ref       = $get($item, 'ram:SpecifiedTradeProduct/ram:SellerAssignedID');
        $priceHt   = (float) $get($item, 'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount');
        $unitCode  = $get($item, 'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity/@unitCode');
        $qtyBilled = (float) $get($item, 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity');
        $billedUnit= $get($item, 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode');
        $vatRate   = (float) $get($item, 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent');
        $lineTotal = (float) $get($item, 'ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount');
        $uc = $unitCode ?: $billedUnit;

        return [
            'name'         => $name,
            'supplier_ref' => $ref,
            'price_ht'     => round($priceHt, 4),
            'unit_code'    => strtoupper($uc),
            'unit'         => $this->mapUnitCode($uc),
            'qty_billed'   => $qtyBilled,
            'vat_rate'     => $vatRate,
            'line_total'   => $lineTotal ?: round($priceHt * $qtyBilled, 2),
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function mapUnitCode(string $code): string
    {
        return match(strtoupper(trim($code))) {
            'KGM'                        => 'kg',
            'GRM', 'MGM'                 => 'g',
            'LTR', 'MLT', 'CLT', 'MTQ'  => 'litre',
            'PCE', 'H87', 'EA', 'C62', 'NAR' => 'piece',
            default                      => 'kg',
        };
    }

    private function guessExtension(string $content, string $mime): string
    {
        if (str_starts_with($content, '%PDF'))               return 'pdf';
        if (str_contains($mime, 'pdf'))                      return 'pdf';
        if (str_starts_with(ltrim($content), '<?xml'))       return 'xml';
        if (str_contains($mime, 'xml'))                      return 'xml';
        return 'unknown';
    }

    private function looksLikeInvoiceXml(string $data): bool
    {
        return str_contains($data, 'CrossIndustryInvoice')
            || str_contains($data, '<Invoice ')
            || str_contains($data, 'SupplyChainTradeTransaction');
    }

    private function commandExists(string $cmd): bool
    {
        return !empty(shell_exec("which $cmd 2>/dev/null"));
    }
}
