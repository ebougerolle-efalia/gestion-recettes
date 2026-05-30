<?php
namespace App\Service;

/**
 * Extrait et parse les données structurées d'une facture électronique.
 *
 * Formats supportés :
 *   - PDF Factur-X  → XML CII embarqué en FlateDecode dans les EmbeddedFiles du PDF
 *   - XML CII pur   → UN/CEFACT CrossIndustryInvoice (EN16931)
 *
 * Fix v2 :
 *   - Extraction PDF : cherche /EmbeddedFile|/text#2Fxml + lit exactement /Length octets
 *     avant gzuncompress() — évite le problème de rtrim sur stream compressé
 *   - Parsing XML : DOMDocument + DOMXPath à la place de SimpleXML
 *     — namespace-safe, attribute XPath fonctionnel
 */
class FacturXParser
{
    // ─── Point d'entrée ──────────────────────────────────────────────────────

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
        // Stratégie 1 : XML non compressé directement dans les octets du PDF
        //               (certains générateurs n'appliquent pas FlateDecode)
        if ($found = $this->scanRawBytes($pdfContent)) {
            return $found;
        }

        // Stratégie 2 : EmbeddedFile FlateDecode — lit exactement /Length octets
        //               puis gzuncompress() (header zlib 0x78…)
        if ($found = $this->extractEmbeddedStream($pdfContent)) {
            return $found;
        }

        // Stratégie 3 : pdfdetach (poppler-utils) si disponible sur le serveur
        return $this->extractViaPdfdetach($pdfContent);
    }

    /**
     * Cherche <?xml directement dans les octets bruts du PDF.
     * Fonctionne si le PDF stocke l'attachment sans compression.
     */
    private function scanRawBytes(string $pdfContent): ?string
    {
        $pos = strpos($pdfContent, '<?xml');
        if ($pos === false) return null;

        // Extraire jusqu'au tag racine de fermeture
        $candidates = [
            '</rsm:CrossIndustryInvoice>',
            '</Invoice>',
            '</CII:CrossIndustryInvoice>',
        ];
        foreach ($candidates as $closeTag) {
            $end = strpos($pdfContent, $closeTag, $pos);
            if ($end !== false) {
                $candidate = substr($pdfContent, $pos, $end - $pos + strlen($closeTag));
                if ($this->looksLikeInvoiceXml($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Extrait le flux FlateDecode d'un EmbeddedFile dans le PDF.
     *
     * Algorithme :
     *  1. Localise /EmbeddedFile ou /text#2Fxml dans le PDF binaire
     *  2. Lit /Length N dans le dictionnaire de flux à proximité
     *  3. Avance jusqu'à stream\n, lit exactement N octets
     *  4. Tente gzuncompress() (zlib deflate avec header 0x78xx)
     *     puis gzinflate() (deflate brut sans header) en fallback
     */
    private function extractEmbeddedStream(string $pdfContent): ?string
    {
        // Marqueurs qui identifient un flux EmbeddedFile dans le dictionnaire PDF
        $markers = ['/EmbeddedFile', '/text#2Fxml', '/text/xml'];

        foreach ($markers as $marker) {
            $dictPos = strpos($pdfContent, $marker);
            if ($dictPos === false) continue;

            // Chercher /Length dans les ~400 octets suivants le marqueur
            $vicinity = substr($pdfContent, $dictPos, 400);
            if (!preg_match('/\/Length\s+(\d+)/', $vicinity, $lm)) continue;
            $streamLen = (int) $lm[1];

            if ($streamLen < 50 || $streamLen > 5_000_000) continue;

            // Trouver le prochain mot-clé `stream` après le dictionnaire
            $streamKw = strpos($pdfContent, 'stream', $dictPos);
            if ($streamKw === false) continue;

            // Avancer après 'stream' + éventuel \r\n
            $dataStart = $streamKw + 6;
            if (isset($pdfContent[$dataStart]) && $pdfContent[$dataStart] === "\r") $dataStart++;
            if (isset($pdfContent[$dataStart]) && $pdfContent[$dataStart] === "\n") $dataStart++;

            $compressed = substr($pdfContent, $dataStart, $streamLen);

            // gzuncompress : zlib avec header (0x78 0x9C ou similaire) — le plus courant
            $decompressed = @gzuncompress($compressed);

            // gzinflate : deflate brut sans header — fallback
            if ($decompressed === false) {
                $decompressed = @gzinflate($compressed);
            }

            if ($decompressed !== false && $this->looksLikeInvoiceXml($decompressed)) {
                return $decompressed;
            }
        }

        return null;
    }

    /** Utilise pdfdetach (poppler-utils) si installé sur le serveur */
    private function extractViaPdfdetach(string $pdfContent): ?string
    {
        if (!$this->commandExists('pdfdetach')) return null;

        $tmpPdf = tempnam(sys_get_temp_dir(), 'facturx_') . '.pdf';
        $tmpDir = sys_get_temp_dir() . '/facturx_' . uniqid();

        try {
            file_put_contents($tmpPdf, $pdfContent);
            mkdir($tmpDir, 0700, true);
            exec(sprintf(
                'pdfdetach -saveall -savepath %s %s 2>/dev/null',
                escapeshellarg($tmpDir),
                escapeshellarg($tmpPdf)
            ));
            foreach (glob($tmpDir . '/*.xml') ?: [] as $xmlFile) {
                $content = file_get_contents($xmlFile);
                if ($this->looksLikeInvoiceXml($content)) return $content;
            }
        } finally {
            @unlink($tmpPdf);
            if (is_dir($tmpDir)) {
                array_map('unlink', glob($tmpDir . '/*') ?: []);
                @rmdir($tmpDir);
            }
        }

        return null;
    }

    // ─── Parsing XML avec DOMDocument ────────────────────────────────────────

    /**
     * Parse un XML CII/EN16931 et retourne les données structurées.
     *
     * Utilise DOMDocument + DOMXPath (namespace-safe)
     * à la place de SimpleXML qui échoue silencieusement
     * sur l'accès aux enfants d'éléments namespaced.
     */
    private function parseXml(string $xmlContent): ?array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($xmlContent)) return null;

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $xpath->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

        // Helper : retourne la valeur texte du premier nœud correspondant
        $get = fn(\DOMNode $ctx, string $path): string
        => trim((string) $xpath->evaluate("string($path)", $ctx));

        // ── Métadonnées ────────────────────────────────────────────────────
        $invoiceId  = $get($dom, '//rsm:ExchangedDocument/ram:ID');
        $issueDate  = $get($dom, '//rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString');
        $sellerName = $get($dom, '//ram:SellerTradeParty/ram:Name');
        $buyerName  = $get($dom, '//ram:BuyerTradeParty/ram:Name');

        // Convertir YYYYMMDD → Y-m-d
        if ($issueDate && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $issueDate, $m)) {
            $issueDate = "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        // ── Lignes de facture ─────────────────────────────────────────────
        $lines = [];
        $items = $xpath->query('//ram:IncludedSupplyChainTradeLineItem');

        foreach ($items as $item) {
            $name     = $get($item, 'ram:SpecifiedTradeProduct/ram:Name');
            $ref      = $get($item, 'ram:SpecifiedTradeProduct/ram:SellerAssignedID');
            $priceHt  = (float) $get($item, 'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount');
            $unitCode = $get($item, 'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity/@unitCode');
            $qtyBilled = (float) $get($item, 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity');
            $billedUnit = $get($item, 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode');
            $vatRate   = (float) $get($item, 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent');
            $lineTotal = (float) $get($item, 'ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount');

            if (empty($name)) continue;

            $uc = $unitCode ?: $billedUnit;
            $lines[] = [
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

        return [
            'invoice_id'  => $invoiceId  ?: 'N/A',
            'issue_date'  => $issueDate  ?: date('Y-m-d'),
            'seller_name' => $sellerName ?: 'Fournisseur inconnu',
            'buyer_name'  => $buyerName  ?: '',
            'lines'       => $lines,
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Mappe les codes unité UN/CEFACT vers les unités de l'appli */
    private function mapUnitCode(string $code): string
    {
        return match(strtoupper(trim($code))) {
            'KGM'                    => 'kg',
            'GRM', 'MGM'             => 'g',
            'LTR', 'MLT', 'CLT', 'MTQ' => 'litre',
            'PCE', 'H87', 'EA', 'C62', 'NAR' => 'piece',
            default                  => 'kg',
        };
    }

    /** Détecte le type de fichier depuis le contenu et le mime type */
    private function guessExtension(string $content, string $mime): string
    {
        if (str_starts_with($content, '%PDF')) return 'pdf';
        if (str_contains($mime, 'pdf'))        return 'pdf';
        if (str_starts_with(ltrim($content), '<?xml')) return 'xml';
        if (str_contains($mime, 'xml'))        return 'xml';
        return 'unknown';
    }

    /** Vérifie qu'un contenu ressemble à un XML de facture CII */
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