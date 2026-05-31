<?php
namespace App\Entity;

use App\Repository\SupplierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fournisseur extrait automatiquement des factures Factur-X.
 * Identifié de façon unique par son SIRET (si présent) ou son nom.
 */
#[ORM\Entity(repositoryClass: SupplierRepository::class)]
#[ORM\Table(name: 'suppliers')]
class Supplier
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 300)]
    private string $name = '';

    /** SIRET (14 chiffres) — identifiant unique fournisseur */
    #[ORM\Column(length: 20, nullable: true, unique: true)]
    private ?string $siret = null;

    /** Numéro de TVA intracommunautaire */
    #[ORM\Column(name: 'vat_number', length: 30, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(name: 'address_line', length: 300, nullable: true)]
    private ?string $addressLine = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $postcode = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_invoice_date', nullable: true)]
    private ?\DateTimeImmutable $lastInvoiceDate = null;

    #[ORM\OneToMany(targetEntity: PurchaseInvoice::class, mappedBy: 'supplier', cascade: ['remove'])]
    #[ORM\OrderBy(['invoiceDate' => 'DESC'])]
    private Collection $invoices;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->invoices  = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getSiret(): ?string { return $this->siret; }
    public function setSiret(?string $v): static { $this->siret = $v ? preg_replace('/\s/', '', $v) : null; return $this; }

    public function getVatNumber(): ?string { return $this->vatNumber; }
    public function setVatNumber(?string $v): static { $this->vatNumber = $v; return $this; }

    public function getAddressLine(): ?string { return $this->addressLine; }
    public function setAddressLine(?string $v): static { $this->addressLine = $v; return $this; }

    public function getPostcode(): ?string { return $this->postcode; }
    public function setPostcode(?string $v): static { $this->postcode = $v; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): static { $this->city = $v; return $this; }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $v): static { $this->country = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getLastInvoiceDate(): ?\DateTimeImmutable { return $this->lastInvoiceDate; }
    public function setLastInvoiceDate(?\DateTimeImmutable $v): static { $this->lastInvoiceDate = $v; return $this; }

    public function getInvoices(): Collection { return $this->invoices; }

    /** Adresse postale complète formatée */
    public function getFullAddress(): string
    {
        $parts = array_filter([$this->addressLine, $this->postcode . ' ' . $this->city]);
        return implode(', ', $parts);
    }
}
