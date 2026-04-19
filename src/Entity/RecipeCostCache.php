<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'recipe_cost_cache')]
class RecipeCostCache
{
    #[ORM\Id, ORM\OneToOne(targetEntity: Recipe::class, inversedBy: 'costCache')]
    #[ORM\JoinColumn(name: 'recipe_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Recipe $recipe = null;

    #[ORM\Column(name: 'computed_at')]
    private ?\DateTimeImmutable $computedAt = null;

    #[ORM\Column(name: 'material_cost_ht', type: 'decimal', precision: 10, scale: 2)]
    private string $materialCostHt = '0.00';

    #[ORM\Column(name: 'total_cost_ht', type: 'decimal', precision: 10, scale: 2)]
    private string $totalCostHt = '0.00';

    #[ORM\Column(name: 'cost_per_output_ht', type: 'decimal', precision: 10, scale: 2)]
    private string $costPerOutputHt = '0.00';

    #[ORM\Column(name: 'advised_sell_ht', type: 'decimal', precision: 10, scale: 2)]
    private string $advisedSellHt = '0.00';

    #[ORM\Column(name: 'advised_sell_ttc', type: 'decimal', precision: 10, scale: 2)]
    private string $advisedSellTtc = '0.00';

    #[ORM\Column(name: 'margin_ht', type: 'decimal', precision: 10, scale: 2)]
    private string $marginHt = '0.00';

    #[ORM\Column(name: 'margin_percent', type: 'decimal', precision: 6, scale: 2)]
    private string $marginPercent = '0.00';

    public function getRecipe(): ?Recipe { return $this->recipe; }
    public function setRecipe(?Recipe $v): static { $this->recipe = $v; return $this; }
    public function getComputedAt(): ?\DateTimeImmutable { return $this->computedAt; }
    public function setComputedAt(\DateTimeImmutable $v): static { $this->computedAt = $v; return $this; }

    public function getMaterialCostHt(): float { return (float) $this->materialCostHt; }
    public function setMaterialCostHt(float $v): static { $this->materialCostHt = number_format($v, 2, '.', ''); return $this; }

    public function getTotalCostHt(): float { return (float) $this->totalCostHt; }
    public function setTotalCostHt(float $v): static { $this->totalCostHt = number_format($v, 2, '.', ''); return $this; }

    public function getCostPerOutputHt(): float { return (float) $this->costPerOutputHt; }
    public function setCostPerOutputHt(float $v): static { $this->costPerOutputHt = number_format($v, 2, '.', ''); return $this; }

    public function getAdvisedSellHt(): float { return (float) $this->advisedSellHt; }
    public function setAdvisedSellHt(float $v): static { $this->advisedSellHt = number_format($v, 2, '.', ''); return $this; }

    public function getAdvisedSellTtc(): float { return (float) $this->advisedSellTtc; }
    public function setAdvisedSellTtc(float $v): static { $this->advisedSellTtc = number_format($v, 2, '.', ''); return $this; }

    public function getMarginHt(): float { return (float) $this->marginHt; }
    public function setMarginHt(float $v): static { $this->marginHt = number_format($v, 2, '.', ''); return $this; }

    public function getMarginPercent(): float { return (float) $this->marginPercent; }
    public function setMarginPercent(float $v): static { $this->marginPercent = number_format($v, 2, '.', ''); return $this; }
}
