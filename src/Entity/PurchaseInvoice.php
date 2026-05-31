<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Facture d'achat importée depuis Factur-X.
 * Conserve l'historique complet des achats par fournisseur.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_invoices')]
class PurchaseInvoice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class, inversedBy: 'invoices')]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: false, onDelete: 'CASCADE')]
    private ?Supplier $supplier = null;

    /** Numéro de facture tel qu'il apparaît dans le XML (ex: FAC-2026-00412) */
    #[ORM\Column(name: 'invoice_id', length: 100)]
    private string $invoiceId = '';

    #[ORM\Column(name: 'invoice_date', type: 'date')]
    private ?\DateTimeInterface $invoiceDate = null;

    #[ORM\Column(name: 'total_ht', type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $totalHt = null;

    #[ORM\Column(name: 'total_ttc', type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $totalTtc = null;

    #[ORM\Column(name: 'imported_at')]
    private \DateTimeImmutable $importedAt;

    #[ORM\OneToMany(targetEntity: PurchaseInvoiceLine::class, mappedBy: 'invoice', cascade: ['persist', 'remove'])]
    private Collection $lines;

    public function __construct()
    {
        $this->importedAt = new \DateTimeImmutable();
        $this->lines      = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getSupplier(): ?Supplier { return $this->supplier; }
    public function setSupplier(?Supplier $v): static { $this->supplier = $v; return $this; }

    public function getInvoiceId(): string { return $this->invoiceId; }
    public function setInvoiceId(string $v): static { $this->invoiceId = $v; return $this; }

    public function getInvoiceDate(): ?\DateTimeInterface { return $this->invoiceDate; }
    public function setInvoiceDate(?\DateTimeInterface $v): static { $this->invoiceDate = $v; return $this; }

    public function getTotalHt(): ?float { return $this->totalHt !== null ? (float) $this->totalHt : null; }
    public function setTotalHt(?float $v): static { $this->totalHt = $v !== null ? number_format($v, 2, '.', '') : null; return $this; }

    public function getTotalTtc(): ?float { return $this->totalTtc !== null ? (float) $this->totalTtc : null; }
    public function setTotalTtc(?float $v): static { $this->totalTtc = $v !== null ? number_format($v, 2, '.', '') : null; return $this; }

    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }

    public function getLines(): Collection { return $this->lines; }
}
