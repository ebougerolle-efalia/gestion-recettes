<?php
namespace App\Tests\Functional;

use App\Entity\PurchaseInvoice;
use App\Repository\UserRepository;
use App\Service\InvoiceInbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Réception d'une pièce que le moteur ne sait pas lire.
 *
 * C'est le cas majoritaire du canal courriel jusqu'en septembre 2027 : un PDF
 * ordinaire, sans Factur-X à l'intérieur. Il ne doit jamais être refusé — une
 * facture perdue en silence est pire qu'une facture à saisir à la main.
 */
class InvoiceQuarantineTest extends WebTestCase
{
    /** PDF minimal mais valide, sans la moindre donnée structurée. */
    private const PDF_NU = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

    private function connecte(): KernelBrowser
    {
        $client = static::createClient();
        $admin  = static::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']);

        if (!$admin) {
            self::markTestSkipped('Base de test non peuplée : voir la préparation décrite dans .env.test');
        }

        $client->loginUser($admin);

        return $client;
    }

    /** Contenu unique par exécution, sinon la déduplication ferait échouer le second passage. */
    private function pdfUnique(): string
    {
        return self::PDF_NU . "\n% " . bin2hex(random_bytes(8));
    }

    public function testUnPdfSansFacturXEstConserveEtNonRefuse(): void
    {
        $this->connecte();
        $inbox = static::getContainer()->get(InvoiceInbox::class);

        $result = $inbox->receive(
            $this->pdfUnique(),
            'application/pdf',
            PurchaseInvoice::SOURCE_EMAIL,
            ['from' => 'facturation@fournisseur-inconnu-test.fr', 'filename' => 'FA-2026-0001.pdf']
        );

        self::assertNull($result['error'], 'Une pièce illisible ne doit pas produire d\'erreur de réception.');
        self::assertTrue($result['captured']);
        self::assertFalse($result['duplicate']);

        $invoice = $result['invoice'];
        self::assertSame(PurchaseInvoice::STATUS_TO_CAPTURE, $invoice->getStatus());
        self::assertTrue($invoice->hasAttachment(), 'Le fichier reçu doit être conservé.');
        self::assertSame('FA-2026-0001.pdf', $invoice->getDisplayReference());
        self::assertSame('facturation@fournisseur-inconnu-test.fr', $invoice->getSenderEmail());
    }

    /**
     * Une boîte relevée deux fois, un fournisseur qui renvoie son message : le
     * même fichier ne doit pas créer deux factures.
     */
    public function testLeMemeFichierRecuDeuxFoisNeCreeQuUneFacture(): void
    {
        $this->connecte();
        $inbox   = static::getContainer()->get(InvoiceInbox::class);
        $payload = $this->pdfUnique();

        $premier = $inbox->receive($payload, 'application/pdf', PurchaseInvoice::SOURCE_EMAIL);
        $second  = $inbox->receive($payload, 'application/pdf', PurchaseInvoice::SOURCE_EMAIL);

        self::assertFalse($premier['duplicate']);
        self::assertTrue($second['duplicate'], 'La seconde réception doit être reconnue comme doublon.');
        self::assertSame($premier['invoice']->getId(), $second['invoice']->getId());
    }

    public function testLaPageDeSaisieEtLaPieceSontAccessibles(): void
    {
        $client = $this->connecte();
        $inbox  = static::getContainer()->get(InvoiceInbox::class);

        $invoice = $inbox->receive(
            $this->pdfUnique(),
            'application/pdf',
            PurchaseInvoice::SOURCE_EMAIL,
            ['filename' => 'scan-du-livreur.pdf']
        )['invoice'];

        $crawler = $client->request('GET', '/factures/' . $invoice->getId() . '/saisir');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('scan-du-livreur.pdf', $crawler->html());

        $client->request('GET', '/factures/' . $invoice->getId() . '/piece');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));

        // La file d'attente doit l'annoncer, sans quoi elle attendrait sans que
        // personne ne le sache.
        $crawler = $client->request('GET', '/factures');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Reçues, à saisir', $crawler->html());
    }

    /**
     * La saisie manuelle doit produire exactement le même objet qu'une lecture
     * Factur-X : une facture en attente de validation, ses lignes rapprochées.
     */
    public function testLaSaisieManuelleRamèneLaFactureDansLaFileDeValidation(): void
    {
        $client = $this->connecte();
        $c      = static::getContainer();
        $inbox  = $c->get(InvoiceInbox::class);
        $em     = $c->get(EntityManagerInterface::class);

        $invoice  = $inbox->receive($this->pdfUnique(), 'application/pdf', PurchaseInvoice::SOURCE_EMAIL)['invoice'];
        $supplier = $c->get(\App\Repository\SupplierRepository::class)->findOneBy([]);

        if (!$supplier) {
            self::markTestSkipped('Aucun fournisseur en base de test.');
        }

        // Numéro unique par exécution : la contrainte (fournisseur, numéro) est
        // réelle, et c'est justement ce que vérifie le test suivant.
        $numero = 'FA-TEST-' . bin2hex(random_bytes(4));

        $client->request('POST', '/factures/' . $invoice->getId() . '/saisir', [
            'supplier_id'  => $supplier->getId(),
            'invoice_id'   => $numero,
            'invoice_date' => '2026-07-15',
            'lines'        => [
                ['description' => 'Épaule de porc sans os', 'qty' => '12,5', 'unit' => 'kg', 'price_ht' => '7,40', 'vat_rate' => '5,5'],
                ['description' => '', 'qty' => '', 'unit' => 'kg', 'price_ht' => '', 'vat_rate' => '5,5'],
            ],
        ]);

        self::assertResponseRedirects();

        $em->clear();
        $relu = $c->get(\App\Repository\PurchaseInvoiceRepository::class)->find($invoice->getId());

        self::assertSame(PurchaseInvoice::STATUS_PENDING, $relu->getStatus());
        self::assertSame($numero, $relu->getInvoiceId());
        self::assertSame($supplier->getId(), $relu->getSupplier()->getId());
        self::assertCount(1, $relu->getLines(), 'La ligne vide du formulaire ne doit pas être retenue.');

        $ligne = $relu->getLines()->first();
        self::assertSame('Épaule de porc sans os', $ligne->getDescription());
        // La virgule décimale est ce qui sera tapé : elle doit être comprise.
        self::assertEqualsWithDelta(7.40, $ligne->getPriceHt(), 0.001);
        self::assertEqualsWithDelta(12.5, $ligne->getQty(), 0.001);
    }

    /**
     * Saisir un numéro déjà connu pour ce fournisseur.
     *
     * Le cas se produit dès qu'une facture arrive deux fois sous deux formes —
     * une fois en Factur-X, une fois scannée : deux fichiers, deux empreintes,
     * la déduplication par fichier ne peut pas les rapprocher. La contrainte
     * d'unicité, elle, tient — et produisait une erreur 500 avant d'être
     * interceptée.
     */
    public function testUnNumeroDejaConnuEstRefuseSansErreurServeur(): void
    {
        $client = $this->connecte();
        $c      = static::getContainer();
        $inbox  = $c->get(InvoiceInbox::class);

        $supplier = $c->get(\App\Repository\SupplierRepository::class)->findOneBy([]);
        if (!$supplier) {
            self::markTestSkipped('Aucun fournisseur en base de test.');
        }

        $numero  = 'FA-DOUBLE-' . bin2hex(random_bytes(4));
        $premier = $inbox->receive($this->pdfUnique(), 'application/pdf', PurchaseInvoice::SOURCE_EMAIL)['invoice'];
        $inbox->assignSupplier($premier, $supplier, $numero);

        // Même numéro, autre fichier : c'est le même document reçu deux fois.
        $second = $inbox->receive($this->pdfUnique(), 'application/pdf', PurchaseInvoice::SOURCE_EMAIL)['invoice'];

        $client->request('POST', '/factures/' . $second->getId() . '/saisir', [
            'supplier_id' => $supplier->getId(),
            'invoice_id'  => $numero,
            'lines'       => [['description' => 'Peu importe', 'qty' => '1', 'unit' => 'kg', 'price_ht' => '1']],
        ]);

        self::assertResponseRedirects('/factures/' . $second->getId() . '/saisir');

        $crawler = $client->followRedirect();
        self::assertStringContainsString('déjà enregistrée', $crawler->html());

        // La pièce reste en quarantaine : rien n'a été enregistré à moitié.
        self::assertSame(PurchaseInvoice::STATUS_TO_CAPTURE, $second->getStatus());
    }
}
