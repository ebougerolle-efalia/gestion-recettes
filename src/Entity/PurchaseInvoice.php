<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Facture d'achat importée depuis Factur-X.
 *
 * Persistée dès la réception, avant toute validation : une facture peut arriver
 * seule (boîte de réception, plateforme agréée) sans personne devant l'écran.
 * Elle attend alors dans la file en statut « à valider », avec les
 * correspondances proposées ligne par ligne, jusqu'à ce qu'un humain tranche.
 *
 * Conserve l'historique complet des achats par fournisseur.
 */
#[ORM\Entity(repositoryClass: \App\Repository\PurchaseInvoiceRepository::class)]
#[ORM\Table(name: 'purchase_invoices')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_per_supplier', columns: ['supplier_id', 'invoice_id'])]
class PurchaseInvoice
{
    /** Reçue, correspondances proposées, en attente d'un arbitrage humain. */
    public const STATUS_PENDING = 'pending';
    /** Validée : les prix ont été créés et les recettes recalculées. */
    public const STATUS_APPLIED = 'applied';
    /** Écartée volontairement (doublon, hors périmètre, erreur fournisseur). */
    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_MANUAL   = 'manual';
    public const SOURCE_EMAIL    = 'email';
    public const SOURCE_PLATFORM = 'platform';

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

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    /** Comment la facture est entrée : dépôt manuel, boîte de réception, plateforme. */
    #[ORM\Column(length: 20, options: ['default' => self::SOURCE_MANUAL])]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\Column(name: 'applied_at', nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    /** Motif de rejet, ou trace d'un traitement partiel. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /**
     * XML CII d'origine. Conservé pour pouvoir rejouer l'analyse après une
     * correction du parseur, et pour l'audit : sans lui, une facture mal lue est
     * définitivement perdue.
     */
    #[ORM\Column(name: 'raw_payload', type: 'text', nullable: true)]
    private ?string $rawPayload = null;

    #[ORM\OneToMany(targetEntity: PurchaseInvoiceLine::class, mappedBy: 'invoice', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
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

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $v): static { $this->source = $v; return $this; }

    public function getAppliedAt(): ?\DateTimeImmutable { return $this->appliedAt; }
    public function setAppliedAt(?\DateTimeImmutable $v): static { $this->appliedAt = $v; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $v): static { $this->note = $v; return $this; }

    public function getRawPayload(): ?string { return $this->rawPayload; }
    public function setRawPayload(?string $v): static { $this->rawPayload = $v; return $this; }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isApplied(): bool { return $this->status === self::STATUS_APPLIED; }

    public function getLines(): Collection { return $this->lines; }

    public function addLine(PurchaseInvoiceLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setInvoice($this);
        }
        return $this;
    }

    /** Lignes restant à traiter (ni appliquées, ni écartées). */
    public function getPendingLineCount(): int
    {
        return count(array_filter($this->lines->toArray(), fn (PurchaseInvoiceLine $l) => !$l->isApplied()));
    }

    public function getLabel(): string
    {
        return ($this->supplier?->getName() ?? 'Fournisseur inconnu') . ' · ' . $this->invoiceId;
    }
}
