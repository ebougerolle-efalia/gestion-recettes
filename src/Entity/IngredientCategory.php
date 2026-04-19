<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\IngredientCategoryRepository;

#[ORM\Entity(repositoryClass: IngredientCategoryRepository::class)]
#[ORM\Table(name: 'ingredient_categories')]
class IngredientCategory
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $name = '';

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    #[ORM\OneToMany(targetEntity: Ingredient::class, mappedBy: 'category')]
    private Collection $ingredients;

    public function __construct() { $this->ingredients = new ArrayCollection(); }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }
    public function getIngredients(): Collection { return $this->ingredients; }
}
