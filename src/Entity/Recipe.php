<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\RecipeRepository;

#[ORM\Entity(repositoryClass: RecipeRepository::class)]
#[ORM\Table(name: 'recipes')]
class Recipe
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $name = '';

    #[ORM\Column(length: 100)]
    private string $family = '';

    #[ORM\Column(name: 'output_type', length: 20)]
    private string $outputType = 'weight'; // weight, portion

    #[ORM\Column(name: 'output_value', type: 'decimal', precision: 10, scale: 3)]
    private string $outputValue = '1.000';

    #[ORM\Column(name: 'loss_percent', type: 'decimal', precision: 5, scale: 2)]
    private string $lossPercent = '0.00';

    #[ORM\Column(name: 'yield_percent', type: 'decimal', precision: 5, scale: 2)]
    private string $yieldPercent = '100.00';

    #[ORM\Column(name: 'product_vat_rate', type: 'decimal', precision: 5, scale: 2)]
    private string $productVatRate = '5.50';

    /** Durée de main d'œuvre en minutes (saisie utilisateur). */
    #[ORM\Column(name: 'labor_minutes', type: 'integer', options: ['default' => 0])]
    private int $laborMinutes = 0;

    /** Coût de main d'œuvre en € HT, calculé = laborMinutes / 60 × taux horaire. */
    #[ORM\Column(name: 'labor_cost_ht', type: 'decimal', precision: 10, scale: 2)]
    private string $laborCostHt = '0.00';

    #[ORM\Column(name: 'packaging_cost_ht', type: 'decimal', precision: 10, scale: 2)]
    private string $packagingCostHt = '0.00';

    #[ORM\Column(name: 'pricing_mode', length: 20)]
    private string $pricingMode = 'coef'; // coef, margin

    #[ORM\Column(name: 'pricing_value', type: 'decimal', precision: 10, scale: 3)]
    private string $pricingValue = '1.000';

    #[ORM\OneToMany(targetEntity: RecipeLine::class, mappedBy: 'recipe', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    #[ORM\OneToOne(targetEntity: RecipeCostCache::class, mappedBy: 'recipe', cascade: ['persist', 'remove'])]
    private ?RecipeCostCache $costCache = null;

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getFamily(): string { return $this->family; }
    public function setFamily(string $v): static { $this->family = $v; return $this; }

    public function getOutputType(): string { return $this->outputType; }
    public function setOutputType(string $v): static { $this->outputType = $v; return $this; }

    public function getOutputValue(): float { return (float) $this->outputValue; }
    public function setOutputValue(float $v): static { $this->outputValue = number_format($v, 3, '.', ''); return $this; }

    public function getLossPercent(): float { return (float) $this->lossPercent; }
    public function setLossPercent(float $v): static { $this->lossPercent = number_format($v, 2, '.', ''); return $this; }

    public function getYieldPercent(): float { return (float) $this->yieldPercent; }
    public function setYieldPercent(float $v): static { $this->yieldPercent = number_format($v, 2, '.', ''); return $this; }

    public function getProductVatRate(): float { return (float) $this->productVatRate; }
    public function setProductVatRate(float $v): static { $this->productVatRate = number_format($v, 2, '.', ''); return $this; }

    public function getLaborMinutes(): int { return $this->laborMinutes; }
    public function setLaborMinutes(int $v): static { $this->laborMinutes = max(0, $v); return $this; }

    public function getLaborCostHt(): float { return (float) $this->laborCostHt; }
    public function setLaborCostHt(float $v): static { $this->laborCostHt = number_format($v, 2, '.', ''); return $this; }

    public function getPackagingCostHt(): float { return (float) $this->packagingCostHt; }
    public function setPackagingCostHt(float $v): static { $this->packagingCostHt = number_format($v, 2, '.', ''); return $this; }

    public function getPricingMode(): string { return $this->pricingMode; }
    public function setPricingMode(string $v): static { $this->pricingMode = $v; return $this; }

    public function getPricingValue(): float { return (float) $this->pricingValue; }
    public function setPricingValue(float $v): static { $this->pricingValue = number_format($v, 3, '.', ''); return $this; }

    public function getLines(): Collection { return $this->lines; }

    public function addLine(RecipeLine $l): static
    {
        if (!$this->lines->contains($l)) {
            $this->lines->add($l);
            $l->setRecipe($this);
        }
        return $this;
    }

    public function removeLine(RecipeLine $l): static
    {
        $this->lines->removeElement($l);
        return $this;
    }

    public function getCostCache(): ?RecipeCostCache { return $this->costCache; }
    public function setCostCache(?RecipeCostCache $v): static { $this->costCache = $v; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getOutputUnitLabel(): string
    {
        return $this->outputType === 'weight' ? 'kg' : 'portion';
    }
}