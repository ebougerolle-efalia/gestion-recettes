<?php
namespace App\Tests\Unit;

use App\Service\UnitConverter;
use PHPUnit\Framework\TestCase;

/**
 * Verrouille les conversions d'unités.
 *
 * Le cas du prix est celui qui a fait entrer un beurre à 0,0084 €/kg au lieu de
 * 8,40 € : le facteur d'un prix est l'inverse de celui d'une quantité.
 */
class UnitConverterTest extends TestCase
{
    private UnitConverter $c;

    protected function setUp(): void
    {
        $this->c = new UnitConverter();
    }

    public function testUnPrixSeConvertitALInverseDUneQuantite(): void
    {
        // 1 g vaut 0,001 kg, mais 0,0084 €/g vaut 8,40 €/kg.
        self::assertSame(0.001, $this->c->factor('g', 'kg'));
        self::assertEqualsWithDelta(8.40, $this->c->convertPrice(0.0084, 'g', 'kg'), 0.0001);
        self::assertEqualsWithDelta(0.0084, $this->c->convertPrice(8.40, 'kg', 'g'), 0.0001);
    }

    public function testUneConversionImpossibleRenvoieNullPlutotQueZero(): void
    {
        // Une crème facturée au colis n'a pas d'équivalent en litres : il faut
        // exiger une saisie, surtout pas fabriquer un prix.
        self::assertNull($this->c->convertPrice(27.30, 'piece', 'litre'));
        self::assertSame(UnitConverter::NONE, $this->c->status('piece', 'litre'));
    }

    public function testLePontPieceMasseExigeUnPoidsUnitaire(): void
    {
        self::assertSame(UnitConverter::NONE, $this->c->status('piece', 'kg'));
        self::assertSame(UnitConverter::OK, $this->c->status('piece', 'kg', 55.0));
        self::assertEqualsWithDelta(0.055, $this->c->factor('piece', 'kg', 55.0), 0.0001);
    }

    public function testLaConversionLitreKiloEstSignaleeCommeApproximative(): void
    {
        self::assertSame(UnitConverter::APPROX, $this->c->status('litre', 'kg'));
        self::assertSame(1.0, $this->c->factor('litre', 'kg'));
    }

    public function testUneQuantiteInconvertibleVautZeroEtNonUneEstimation(): void
    {
        self::assertSame(0.0, $this->c->convertQty(4.0, 'piece', 'litre'));
        self::assertSame(2.5, $this->c->convertQty(2500.0, 'g', 'kg'));
    }
}
