<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\IngredientRepository;

#[ORM\Entity(repositoryClass: IngredientRepository::class)]
#[ORM\Table(name: 'ingredients')]
class Ingredient
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $name = '';

    #[ORM\ManyToOne(targetEntity: IngredientCategory::class, inversedBy: 'ingredients')]
    #[ORM\JoinColumn(name: 'category_id', nullable: false)]
    private ?IngredientCategory $category = null;

    #[ORM\Column(name: 'base_unit', length: 20)]
    private string $baseUnit = 'kg'; // kg, g, piece, litre

    #[ORM\Column(name: 'vat_rate', type: 'decimal', precision: 5, scale: 2)]
    private string $vatRate = '5.50';

    #[ORM\Column(name: 'default_supplier', length: 200, nullable: true)]
    private ?string $defaultSupplier = null;

    /** Code de l'aliment Ciqual associé (pour les valeurs nutritionnelles). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $ciqualCode = null;

    /**
     * Vrai tant que le rattachement Ciqual vient d'un appariement automatique
     * non validé. Les valeurs nutritionnelles finissent en déclaration INCO :
     * elles doivent avoir été confirmées par un humain.
     */
    #[ORM\Column(name: 'ciqual_auto', type: 'boolean', options: ['default' => false])]
    private bool $ciqualAuto = false;

    // Poids d'une pièce en grammes (uniquement utile si base_unit = piece) :
    // permet de convertir pièce ↔ grammes pour le coût ET la nutrition.
    #[ORM\Column(name: 'unit_weight_g', type: 'float', nullable: true)]
    private ?float $unitWeightG = null;

    /** Allergènes présents (codes parmi les 14 réglementaires — règlement INCO 1169/2011). */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allergens = [];

    /** Traces éventuelles (« peut contenir »), juridiquement distinctes des allergènes présents. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $traces = [];

    #[ORM\OneToMany(targetEntity: IngredientPrice::class, mappedBy: 'ingredient', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['effectiveDate' => 'DESC', 'id' => 'DESC'])]
    private Collection $prices;

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->prices = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getCategory(): ?IngredientCategory { return $this->category; }
    public function setCategory(?IngredientCategory $v): static { $this->category = $v; return $this; }
    public function getBaseUnit(): string { return $this->baseUnit; }
    public function setBaseUnit(string $v): static { $this->baseUnit = $v; return $this; }
    public function getVatRate(): float { return (float) $this->vatRate; }
    public function setVatRate(float $v): static { $this->vatRate = number_format($v, 2, '.', ''); return $this; }
    public function getDefaultSupplier(): ?string { return $this->defaultSupplier; }
    public function setDefaultSupplier(?string $v): static { $this->defaultSupplier = $v; return $this; }

    public function getAllergens(): array { return $this->allergens ?? []; }
    public function setAllergens(array $v): static { $this->allergens = array_values($v); return $this; }

    public function getCiqualCode(): ?string { return $this->ciqualCode; }
    public function setCiqualCode(?string $v): static { $this->ciqualCode = ($v === '' ? null : $v); return $this; }

    public function isCiqualAuto(): bool { return $this->ciqualAuto && $this->ciqualCode !== null; }
    public function setCiqualAuto(bool $v): static { $this->ciqualAuto = $v; return $this; }

    /** Rattachement validé par un utilisateur (ou saisi à la main). */
    public function confirmCiqual(): static { $this->ciqualAuto = false; return $this; }

    public function getUnitWeightG(): ?float { return $this->unitWeightG; }
    public function setUnitWeightG(?float $v): static { $this->unitWeightG = ($v !== null && $v > 0) ? $v : null; return $this; }

    public function getTraces(): array { return $this->traces ?? []; }
    public function setTraces(array $v): static { $this->traces = array_values($v); return $this; }

    /** Vrai si l'allergénicité a déjà été renseignée (présents ou traces). */
    public function hasAllergenInfo(): bool
    {
        return !empty($this->allergens) || !empty($this->traces);
    }
    public function getPrices(): Collection { return $this->prices; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function addPrice(IngredientPrice $p): static
    {
        if (!$this->prices->contains($p)) {
            $this->prices->add($p);
            $p->setIngredient($this);
        }
        return $this;
    }

    /** Get latest price entry */
    public function getLatestPrice(): ?IngredientPrice
    {
        return $this->prices->first() ?: null;
    }

    public function getLatestPriceHt(): ?float
    {
        $p = $this->getLatestPrice();
        return $p ? $p->getPriceHt() : null;
    }
}