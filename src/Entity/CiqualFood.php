<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\CiqualFoodRepository;

/**
 * Un aliment de la table Ciqual (ANSES) — valeurs nutritionnelles pour 100 g.
 * Source : Anses. Table Ciqual de composition nutritionnelle des aliments.
 */
#[ORM\Entity(repositoryClass: CiqualFoodRepository::class)]
#[ORM\Table(name: 'ciqual_foods')]
// Index déclaré ici uniquement pour que doctrine:migrations:diff cesse de
// proposer sa suppression. Sa vraie définition est un GIN trigramme
// (Version20260729200500) que les métadonnées ORM ne savent pas exprimer :
// ne jamais le laisser recréer en B-tree, la recherche floue s'effondrerait.
#[ORM\Index(name: 'idx_ciqual_nom_norm_trgm', columns: ['nom_norm'])]
class CiqualFood
{
    #[ORM\Id, ORM\Column(length: 20)]
    private string $code = '';

    #[ORM\Column(length: 255)]
    private string $nom = '';

    /**
     * Nom réduit en ASCII minuscule (accents et ligatures dépliés), rempli à
     * l'import. Sert aux recherches et à l'appariement automatique : LOWER()
     * de SQLite/MySQL ne replie ni « Œ » ni « É », une comparaison sur `nom`
     * échouerait sur ces caractères.
     */
    #[ORM\Column(name: 'nom_norm', length: 255, nullable: true)]
    private ?string $nomNorm = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $groupe = null;

    // Les 7 nutriments obligatoires (INCO art. 30), pour 100 g. Null = non renseigné.
    #[ORM\Column(nullable: true)] private ?float $energieKj = null;
    #[ORM\Column(nullable: true)] private ?float $energieKcal = null;
    #[ORM\Column(nullable: true)] private ?float $proteines = null;
    #[ORM\Column(nullable: true)] private ?float $glucides = null;
    #[ORM\Column(nullable: true)] private ?float $lipides = null;
    #[ORM\Column(nullable: true)] private ?float $sucres = null;
    #[ORM\Column(nullable: true)] private ?float $agSatures = null;
    #[ORM\Column(nullable: true)] private ?float $sel = null;

    public function getCode(): string { return $this->code; }
    public function setCode(string $v): static { $this->code = $v; return $this; }

    public function getNom(): string { return $this->nom; }
    public function setNom(string $v): static
    {
        $this->nom     = $v;
        $this->nomNorm = \App\Service\CiqualMatcher::normalize($v);
        return $this;
    }

    public function getNomNorm(): ?string { return $this->nomNorm; }

    public function getGroupe(): ?string { return $this->groupe; }
    public function setGroupe(?string $v): static { $this->groupe = $v; return $this; }

    public function getEnergieKj(): ?float { return $this->energieKj; }
    public function setEnergieKj(?float $v): static { $this->energieKj = $v; return $this; }

    public function getEnergieKcal(): ?float { return $this->energieKcal; }
    public function setEnergieKcal(?float $v): static { $this->energieKcal = $v; return $this; }

    public function getProteines(): ?float { return $this->proteines; }
    public function setProteines(?float $v): static { $this->proteines = $v; return $this; }

    public function getGlucides(): ?float { return $this->glucides; }
    public function setGlucides(?float $v): static { $this->glucides = $v; return $this; }

    public function getLipides(): ?float { return $this->lipides; }
    public function setLipides(?float $v): static { $this->lipides = $v; return $this; }

    public function getSucres(): ?float { return $this->sucres; }
    public function setSucres(?float $v): static { $this->sucres = $v; return $this; }

    public function getAgSatures(): ?float { return $this->agSatures; }
    public function setAgSatures(?float $v): static { $this->agSatures = $v; return $this; }

    public function getSel(): ?float { return $this->sel; }
    public function setSel(?float $v): static { $this->sel = $v; return $this; }

    /** Renvoie les 7 valeurs sous forme de tableau associatif (pour 100 g). */
    public function toArray(): array
    {
        return [
            'energie_kj'   => $this->energieKj,
            'energie_kcal' => $this->energieKcal,
            'proteines'    => $this->proteines,
            'glucides'     => $this->glucides,
            'lipides'      => $this->lipides,
            'sucres'       => $this->sucres,
            'ag_satures'   => $this->agSatures,
            'sel'          => $this->sel,
        ];
    }
}
