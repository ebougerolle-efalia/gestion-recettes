<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ConfigBoutiqueRepository;

/**
 * Configuration de l'établissement (singleton : un seul enregistrement, id = 1).
 *
 * Sert à dépersonnaliser l'application : nom du labo, coordonnées et logo
 * affichés dans l'interface et sur les fiches PDF, plus quelques valeurs
 * métier par défaut pour pré-remplir les nouvelles recettes.
 */
#[ORM\Entity(repositoryClass: ConfigBoutiqueRepository::class)]
#[ORM\Table(name: 'config_boutique')]
class ConfigBoutique
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    // --- Identité de l'établissement -----------------------------------------

    #[ORM\Column(length: 150)]
    private string $nomEtablissement = 'Mon établissement';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sousTitre = null; // ex : "Charcutier-Traiteur"

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(name: 'code_postal', length: 10, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $siret = null;

    /** Chemin relatif (depuis public/) du logo, ex : "uploads/boutique/logo-xxxx.png" */
    #[ORM\Column(name: 'logo_path', length: 255, nullable: true)]
    private ?string $logoPath = null;

    /** Mention libre affichée en pied des fiches PDF (mentions légales, etc.) */
    #[ORM\Column(name: 'mention_pied', type: 'text', nullable: true)]
    private ?string $mentionPied = null;

    // --- Valeurs métier par défaut -------------------------------------------

    #[ORM\Column(name: 'tva_defaut', type: 'decimal', precision: 5, scale: 2)]
    private string $tvaDefaut = '5.50';

    #[ORM\Column(name: 'coef_defaut', type: 'decimal', precision: 10, scale: 3)]
    private string $coefDefaut = '3.000';

    // --- Getters / setters ----------------------------------------------------

    public function getId(): ?int { return $this->id; }

    public function getNomEtablissement(): string { return $this->nomEtablissement; }
    public function setNomEtablissement(string $v): static { $this->nomEtablissement = $v; return $this; }

    public function getSousTitre(): ?string { return $this->sousTitre; }
    public function setSousTitre(?string $v): static { $this->sousTitre = $v; return $this; }

    public function getAdresse(): ?string { return $this->adresse; }
    public function setAdresse(?string $v): static { $this->adresse = $v; return $this; }

    public function getCodePostal(): ?string { return $this->codePostal; }
    public function setCodePostal(?string $v): static { $this->codePostal = $v; return $this; }

    public function getVille(): ?string { return $this->ville; }
    public function setVille(?string $v): static { $this->ville = $v; return $this; }

    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(?string $v): static { $this->telephone = $v; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): static { $this->email = $v; return $this; }

    public function getSiret(): ?string { return $this->siret; }
    public function setSiret(?string $v): static { $this->siret = $v; return $this; }

    public function getLogoPath(): ?string { return $this->logoPath; }
    public function setLogoPath(?string $v): static { $this->logoPath = $v; return $this; }

    public function getMentionPied(): ?string { return $this->mentionPied; }
    public function setMentionPied(?string $v): static { $this->mentionPied = $v; return $this; }

    public function getTvaDefaut(): float { return (float) $this->tvaDefaut; }
    public function setTvaDefaut(float $v): static { $this->tvaDefaut = number_format($v, 2, '.', ''); return $this; }

    public function getCoefDefaut(): float { return (float) $this->coefDefaut; }
    public function setCoefDefaut(float $v): static { $this->coefDefaut = number_format($v, 3, '.', ''); return $this; }

    /** Adresse complète sur une ligne, pratique pour les en-têtes PDF. */
    public function getAdresseComplete(): string
    {
        $parts = array_filter([
            $this->adresse,
            trim(($this->codePostal ?? '') . ' ' . ($this->ville ?? '')),
        ]);
        return implode(' — ', $parts);
    }
}