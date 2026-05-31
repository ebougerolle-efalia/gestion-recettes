<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\IngredientPriceRepository;

#[ORM\Entity(repositoryClass: IngredientPriceRepository::class)]
#[ORM\Table(name: 'ingredient_prices')]
class IngredientPrice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ingredient::class, inversedBy: 'prices')]
    #[ORM\JoinColumn(name: 'ingredient_id', nullable: false, onDelete: 'CASCADE')]
    private ?Ingredient $ingredient = null;

    #[ORM\Column(name: 'price_ht', type: 'decimal', precision: 10, scale: 4)]
    private string $priceHt = '0.0000';

    /** Nom fournisseur en texte libre (rétrocompat + saisie manuelle) */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $supplier = null;

    /** Lien vers l'entité Supplier (rempli lors d'un import Factur-X) */
    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: true, onDelete: 'SET NULL')]
    private ?Supplier $supplierEntity = null;

    /** Facture d'origine (rempli lors d'un import Factur-X) */
    #[ORM\ManyToOne(targetEntity: PurchaseInvoice::class)]
    #[ORM\JoinColumn(name: 'purchase_invoice_id', nullable: true, onDelete: 'SET NULL')]
    private ?PurchaseInvoice $purchaseInvoice = null;

    #[ORM\Column(name: 'effective_date', type: 'date')]
    private ?\DateTimeInterface $effectiveDate = null;

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->effectiveDate = new \DateTime();
        $this->createdAt     = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getIngredient(): ?Ingredient { return $this->ingredient; }
    public function setIngredient(?Ingredient $v): static { $this->ingredient = $v; return $this; }

    public function getPriceHt(): float { return (float) $this->priceHt; }
    public function setPriceHt(float $v): static { $this->priceHt = number_format($v, 4, '.', ''); return $this; }

    public function getSupplier(): ?string { return $this->supplier; }
    public function setSupplier(?string $v): static { $this->supplier = $v; return $this; }

    public function getSupplierEntity(): ?Supplier { return $this->supplierEntity; }
    public function setSupplierEntity(?Supplier $v): static
    {
        $this->supplierEntity = $v;
        // Synchronise le champ texte pour compatibilité avec le code existant
        if ($v) $this->supplier = $v->getName();
        return $this;
    }

    public function getPurchaseInvoice(): ?PurchaseInvoice { return $this->purchaseInvoice; }
    public function setPurchaseInvoice(?PurchaseInvoice $v): static { $this->purchaseInvoice = $v; return $this; }

    public function getEffectiveDate(): ?\DateTimeInterface { return $this->effectiveDate; }
    public function setEffectiveDate(?\DateTimeInterface $v): static { $this->effectiveDate = $v; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
