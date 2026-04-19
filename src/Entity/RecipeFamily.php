<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\RecipeFamilyRepository;

#[ORM\Entity(repositoryClass: RecipeFamilyRepository::class)]
#[ORM\Table(name: 'recipe_families')]
class RecipeFamily
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $name = '';

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }
}
