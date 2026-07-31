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
    /**
     * Reçue mais illisible pour le moteur : PDF sans Factur-X, le cas encore
     * majoritaire aujourd'hui. Le fichier est conservé, la facture attend une
     * saisie manuelle de ses lignes. Rien n'est perdu, rien n'est inventé.
     */
    public const STATUS_TO_CAPTURE = 'to_capture';
    /** Validée : les prix ont été créés et les recettes recalculées. */
    public const STATUS_APPLIED = 'applied';
    /** Écartée volontairement (doublon, hors périmètre, erreur fournisseur). */
    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_MANUAL   = 'manual';
    public const SOURCE_EMAIL    = 'email';
    public const SOURCE_PLATFORM = 'platform';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /**
     * Facultatif depuis l'ouverture du canal courriel : un PDF nu venu d'une
     * adresse inconnue est une facture bien réelle, qu'on refuse de perdre au
     * motif qu'on ne sait pas encore de qui elle vient. Un humain la rattache.
     */
    #[ORM\ManyToOne(targetEntity: Supplier::class, inversedBy: 'invoices')]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: true, onDelete: 'CASCADE')]
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

    /**
     * Empreinte SHA-256 du fichier reçu.
     *
     * Le couple (fournisseur, numéro) ne suffit plus : un PDF nu n'a ni l'un ni
     * l'autre tant qu'un humain ne les a pas saisis. L'empreinte, elle, existe
     * toujours et rend la relève rejouable sans créer de doublon — une boîte
     * relevée deux fois, un message renvoyé par le fournisseur, un retour de
     * sauvegarde.
     */
    #[ORM\Column(name: 'payload_hash', length: 64, nullable: true, unique: true)]
    private ?string $payloadHash = null;

    /** Chemin du fichier d'origine conservé sous var/invoices, relatif à ce dossier. */
    #[ORM\Column(name: 'attachment_path', length: 255, nullable: true)]
    private ?string $attachmentPath = null;

    #[ORM\Column(name: 'attachment_name', length: 255, nullable: true)]
    private ?string $attachmentName = null;

    #[ORM\Column(name: 'attachment_mime', length: 100, nullable: true)]
    private ?string $attachmentMime = null;

    #[ORM\Column(name: 'attachment_size', nullable: true)]
    private ?int $attachmentSize = null;

    /** Adresse d'expédition, seul indice d'origine quand la pièce est illisible. */
    #[ORM\Column(name: 'sender_email', length: 200, nullable: true)]
    private ?string $senderEmail = null;

    #[ORM\Column(name: 'mail_subject', length: 500, nullable: true)]
    private ?string $mailSubject = null;

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

    public function getPayloadHash(): ?string { return $this->payloadHash; }
    public function setPayloadHash(?string $v): static { $this->payloadHash = $v; return $this; }

    public function getAttachmentPath(): ?string { return $this->attachmentPath; }
    public function setAttachmentPath(?string $v): static { $this->attachmentPath = $v; return $this; }

    public function getAttachmentName(): ?string { return $this->attachmentName; }
    public function setAttachmentName(?string $v): static { $this->attachmentName = $v; return $this; }

    public function getAttachmentMime(): ?string { return $this->attachmentMime; }
    public function setAttachmentMime(?string $v): static { $this->attachmentMime = $v; return $this; }

    public function getAttachmentSize(): ?int { return $this->attachmentSize; }
    public function setAttachmentSize(?int $v): static { $this->attachmentSize = $v; return $this; }

    public function hasAttachment(): bool { return $this->attachmentPath !== null; }

    public function getSenderEmail(): ?string { return $this->senderEmail; }
    public function setSenderEmail(?string $v): static { $this->senderEmail = $v ? strtolower(trim($v)) : null; return $this; }

    public function getMailSubject(): ?string { return $this->mailSubject; }
    public function setMailSubject(?string $v): static { $this->mailSubject = $v; return $this; }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isApplied(): bool { return $this->status === self::STATUS_APPLIED; }
    public function isToCapture(): bool { return $this->status === self::STATUS_TO_CAPTURE; }

    /** Attend encore un geste : validation des lignes, ou saisie complète. */
    public function isOpen(): bool
    {
        return $this->isPending() || $this->isToCapture();
    }

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
        return ($this->supplier?->getName() ?? 'Fournisseur inconnu') . ' · ' . $this->getDisplayReference();
    }

    /**
     * Référence affichable.
     *
     * Une facture en attente de saisie n'a pas encore de numéro : on montre le
     * nom du fichier reçu plutôt qu'un identifiant technique, parce que c'est ce
     * que l'utilisateur reconnaîtra en ouvrant la pièce jointe.
     */
    public function getDisplayReference(): string
    {
        if ($this->isToCapture() && $this->attachmentName) {
            return $this->attachmentName;
        }

        return $this->invoiceId;
    }
}
