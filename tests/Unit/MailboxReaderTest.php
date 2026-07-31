<?php
namespace App\Tests\Unit;

use App\Service\MailboxReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Lecture du DSN de la boîte de réception.
 *
 * C'est le seul endroit du canal automatique qu'on puisse éprouver sans serveur
 * IMAP en face, et c'est aussi celui où une erreur coûte le plus cher : un DSN
 * mal lu, et la relève tourne dans le vide sur un déploiement client sans que
 * personne ne s'en aperçoive avant de chercher ses factures.
 */
class MailboxReaderTest extends TestCase
{
    private function reader(string $dsn): MailboxReader
    {
        return new MailboxReader($dsn, new NullLogger());
    }

    public function testUnDsnVideDesactiveLeCanal(): void
    {
        self::assertFalse($this->reader('')->isConfigured());
        self::assertFalse($this->reader('   ')->isConfigured());
        self::assertSame('aucune boîte configurée', $this->reader('')->describe());
    }

    public function testLeDsnCompletEstRelu(): void
    {
        $reader = $this->reader('imap://factures%40quintalis.fr:secret@mail.example.net:993?encryption=ssl&folder=INBOX&archive=Traitees');

        self::assertTrue($reader->isConfigured());
        self::assertSame('factures@quintalis.fr@mail.example.net:993 (ssl), dossier INBOX', $reader->describe());
    }

    /**
     * La description s'affiche dans l'interface et dans les journaux : elle ne
     * doit jamais laisser filtrer le mot de passe de la boîte.
     */
    public function testLaDescriptionNeMontreJamaisLeMotDePasse(): void
    {
        $description = $this->reader('imap://compta:MotDePasseTresSecret@mail.example.net:993')->describe();

        self::assertStringNotContainsString('MotDePasseTresSecret', $description);
    }

    public function testLePortDependDuChiffrementQuandIlNEstPasPrecise(): void
    {
        self::assertStringContainsString(':993', $this->reader('imap://u:p@h?encryption=ssl')->describe());
        self::assertStringContainsString(':143', $this->reader('imap://u:p@h?encryption=tls')->describe());
    }

    public function testUnDsnIllisibleEstSignaleAvantTouteConnexion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/INVOICE_MAILBOX_DSN/');

        $this->reader('pas-du-tout-un-dsn')->describe();
    }

    public function testUnChiffrementInconnuEstRefuse(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ssl, tls ou none/');

        $this->reader('imap://u:p@h?encryption=rot13')->describe();
    }

    public function testLaReleveNEchouePasQuandAucuneBoiteNEstConfiguree(): void
    {
        $stats = $this->reader('')->fetch(fn () => true);

        self::assertSame(0, $stats['messages']);
        self::assertNotEmpty($stats['errors']);
    }
}
