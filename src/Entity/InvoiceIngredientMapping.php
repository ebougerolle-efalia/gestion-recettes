<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Mémorise l'association entre un libellé de facture fournisseur
 * et un ingrédient du catalogue.
 *
 * Ex : "Gorge de porc 1er choix — réfrigérée" → Ingredient#1 (Gorge de porc)
 *
 * Cette table s'enrichit à chaque import confirmé : l'appli apprend
 * les noms propres à chaque fournisseur et pré-sélectionne l'ingrédient
 * lors des prochains imports.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_ingredient_mappings')]
#[ORM\Index(columns: ['invoice_label'], name: 'idx_invoice_label')]
class InvoiceIngredientMapping
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** Libellé normalisé tel qu'il apparaît sur la facture fournisseur */
    #[ORM\Column(length: 500)]
    private string $invoiceLabel = '';

    /** Ingrédient correspondant dans le catalogue */
    #[ORM\ManyToOne(targetEntity: Ingredient::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ingredient $ingredient = null;

    /** Nombre de fois que cette association a été confirmée (confiance) */
    #[ORM\Column]
    private int $confirmCount = 1;

    /** Date de la dernière confirmation */
    #[ORM\Column]
    private \DateTimeImmutable $lastSeen;

    public function __construct()
    {
        $this->lastSeen = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getInvoiceLabel(): string { return $this->invoiceLabel; }
    public function setInvoiceLabel(string $v): static { $this->invoiceLabel = $v; return $this; }

    public function getIngredient(): ?Ingredient { return $this->ingredient; }
    public function setIngredient(?Ingredient $v): static { $this->ingredient = $v; return $this; }

    public function getConfirmCount(): int { return $this->confirmCount; }
    public function setConfirmCount(int $v): static { $this->confirmCount = $v; return $this; }

    public function getLastSeen(): \DateTimeImmutable { return $this->lastSeen; }
    public function setLastSeen(\DateTimeImmutable $v): static { $this->lastSeen = $v; return $this; }
}