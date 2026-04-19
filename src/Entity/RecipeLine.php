<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\RecipeLineRepository;

#[ORM\Entity(repositoryClass: RecipeLineRepository::class)]
#[ORM\Table(name: 'recipe_lines')]
class RecipeLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Recipe::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'recipe_id', nullable: false, onDelete: 'CASCADE')]
    private ?Recipe $recipe = null;

    #[ORM\ManyToOne(targetEntity: Ingredient::class)]
    #[ORM\JoinColumn(name: 'ingredient_id', nullable: true)]
    private ?Ingredient $ingredient = null;

    #[ORM\ManyToOne(targetEntity: Recipe::class)]
    #[ORM\JoinColumn(name: 'sub_recipe_id', nullable: true)]
    private ?Recipe $subRecipe = null;

    #[ORM\Column(name: 'qty_brute', type: 'decimal', precision: 10, scale: 4)]
    private string $qtyBrute = '0.0000';

    #[ORM\Column(length: 20)]
    private string $unit = 'kg';

    #[ORM\Column(name: 'loss_percent', type: 'decimal', precision: 5, scale: 2)]
    private string $lossPercent = '0.00';

    #[ORM\Column(name: 'yield_percent', type: 'decimal', precision: 5, scale: 2)]
    private string $yieldPercent = '100.00';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }

    public function getRecipe(): ?Recipe { return $this->recipe; }
    public function setRecipe(?Recipe $v): static { $this->recipe = $v; return $this; }

    public function getIngredient(): ?Ingredient { return $this->ingredient; }
    public function setIngredient(?Ingredient $v): static { $this->ingredient = $v; return $this; }

    public function getSubRecipe(): ?Recipe { return $this->subRecipe; }
    public function setSubRecipe(?Recipe $v): static { $this->subRecipe = $v; return $this; }

    public function getQtyBrute(): float { return (float) $this->qtyBrute; }
    public function setQtyBrute(float $v): static { $this->qtyBrute = number_format($v, 4, '.', ''); return $this; }

    public function getUnit(): string { return $this->unit; }
    public function setUnit(string $v): static { $this->unit = $v; return $this; }

    public function getLossPercent(): float { return (float) $this->lossPercent; }
    public function setLossPercent(float $v): static { $this->lossPercent = number_format($v, 2, '.', ''); return $this; }

    public function getYieldPercent(): float { return (float) $this->yieldPercent; }
    public function setYieldPercent(float $v): static { $this->yieldPercent = number_format($v, 2, '.', ''); return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $v): static { $this->note = $v; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }

    public function isSubRecipeLine(): bool { return $this->subRecipe !== null; }
    public function isIngredientLine(): bool { return $this->ingredient !== null; }

    public function getDisplayName(): string
    {
        if ($this->ingredient) return $this->ingredient->getName();
        if ($this->subRecipe) return '[SR] ' . $this->subRecipe->getName();
        return '?';
    }
}
