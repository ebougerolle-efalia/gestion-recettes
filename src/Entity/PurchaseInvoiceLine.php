<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Ligne d'une facture d'achat — conserve le détail brut de la facture fournisseur */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_invoice_lines')]
class PurchaseInvoiceLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseInvoice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseInvoice $invoice = null;

    /** Ingrédient associé — null si non reconnu ou non confirmé */
    #[ORM\ManyToOne(targetEntity: Ingredient::class)]
    #[ORM\JoinColumn(name: 'ingredient_id', nullable: true, onDelete: 'SET NULL')]
    private ?Ingredient $ingredient = null;

    /** Référence fournisseur brute (ex: PORC-GORGE-001) */
    #[ORM\Column(name: 'supplier_ref', length: 100, nullable: true)]
    private ?string $supplierRef = null;

    /** Désignation brute telle qu'elle figure sur la facture */
    #[ORM\Column(length: 500)]
    private string $description = '';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    private string $qty = '0.0000';

    /** Code unité UN/CEFACT (KGM, LTR, PCE…) */
    #[ORM\Column(name: 'unit_code', length: 10)]
    private string $unitCode = 'KGM';

    /** Unité appli (kg, litre, piece…) */
    #[ORM\Column(length: 20)]
    private string $unit = 'kg';

    #[ORM\Column(name: 'price_ht', type: 'decimal', precision: 10, scale: 4)]
    private string $priceHt = '0.0000';

    #[ORM\Column(name: 'vat_rate', type: 'decimal', precision: 5, scale: 2)]
    private string $vatRate = '5.50';

    #[ORM\Column(name: 'line_total', type: 'decimal', precision: 12, scale: 2)]
    private string $lineTotal = '0.00';

    public function getId(): ?int { return $this->id; }

    public function getInvoice(): ?PurchaseInvoice { return $this->invoice; }
    public function setInvoice(?PurchaseInvoice $v): static { $this->invoice = $v; return $this; }

    public function getIngredient(): ?Ingredient { return $this->ingredient; }
    public function setIngredient(?Ingredient $v): static { $this->ingredient = $v; return $this; }

    public function getSupplierRef(): ?string { return $this->supplierRef; }
    public function setSupplierRef(?string $v): static { $this->supplierRef = $v; return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $v): static { $this->description = $v; return $this; }

    public function getQty(): float { return (float) $this->qty; }
    public function setQty(float $v): static { $this->qty = number_format($v, 4, '.', ''); return $this; }

    public function getUnitCode(): string { return $this->unitCode; }
    public function setUnitCode(string $v): static { $this->unitCode = $v; return $this; }

    public function getUnit(): string { return $this->unit; }
    public function setUnit(string $v): static { $this->unit = $v; return $this; }

    public function getPriceHt(): float { return (float) $this->priceHt; }
    public function setPriceHt(float $v): static { $this->priceHt = number_format($v, 4, '.', ''); return $this; }

    public function getVatRate(): float { return (float) $this->vatRate; }
    public function setVatRate(float $v): static { $this->vatRate = number_format($v, 2, '.', ''); return $this; }

    public function getLineTotal(): float { return (float) $this->lineTotal; }
    public function setLineTotal(float $v): static { $this->lineTotal = number_format($v, 2, '.', ''); return $this; }
}
