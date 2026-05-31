<?php
namespace App\Twig;

use App\Entity\ConfigBoutique;
use App\Repository\ConfigBoutiqueRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose la configuration de l'établissement à tous les templates Twig.
 *
 * Usage :
 *   {{ boutique().nomEtablissement }}
 *   {{ boutique().adresseComplete }}
 *   {% if logo_base64() %}<img src="{{ logo_base64() }}">{% endif %}
 *
 * logo_base64() renvoie le logo encodé en data-URI base64 — indispensable pour
 * les PDF générés par Gotenberg, qui ne peuvent pas charger un chemin relatif.
 */
class BoutiqueExtension extends AbstractExtension
{
    /**
     * Les 14 allergènes à déclaration obligatoire (Annexe II du règlement UE
     * 1169/2011 dit « INCO »). code => libellé affiché.
     */
    public const ALLERGENES = [
        'gluten'         => 'Gluten',
        'crustaces'      => 'Crustacés',
        'oeufs'          => 'Œufs',
        'poissons'       => 'Poissons',
        'arachides'      => 'Arachides',
        'soja'           => 'Soja',
        'lait'           => 'Lait',
        'fruits_a_coque' => 'Fruits à coque',
        'celeri'         => 'Céleri',
        'moutarde'       => 'Moutarde',
        'sesame'         => 'Sésame',
        'sulfites'       => 'Sulfites',
        'lupin'          => 'Lupin',
        'mollusques'     => 'Mollusques',
    ];

    private ?ConfigBoutique $cache = null;
    private bool $logoResolved = false;
    private ?string $logoData = null;

    public function __construct(
        private ConfigBoutiqueRepository $repo,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('boutique', [$this, 'getBoutique']),
            new TwigFunction('logo_base64', [$this, 'getLogoBase64']),
            new TwigFunction('allergenes', [$this, 'getAllergenes']),
            new TwigFunction('allergen_label', [$this, 'getAllergenLabel']),
        ];
    }

    /** Map complète code => libellé des 14 allergènes. */
    public function getAllergenes(): array
    {
        return self::ALLERGENES;
    }

    /** Libellé d'un code allergène (renvoie le code brut si inconnu). */
    public function getAllergenLabel(string $code): string
    {
        return self::ALLERGENES[$code] ?? $code;
    }

    public function getBoutique(): ConfigBoutique
    {
        return $this->cache ??= $this->repo->getConfig();
    }

    /** Logo encodé en data-URI base64, ou null si absent / illisible. */
    public function getLogoBase64(): ?string
    {
        if ($this->logoResolved) {
            return $this->logoData;
        }
        $this->logoResolved = true;

        $path = $this->getBoutique()->getLogoPath();
        if (!$path) {
            return null;
        }

        $abs = $this->projectDir . '/public/' . ltrim($path, '/');
        if (!is_file($abs)) {
            return null;
        }

        $data = @file_get_contents($abs);
        if ($data === false) {
            return null;
        }

        $mime = match (strtolower(pathinfo($abs, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            default       => 'image/png',
        };

        return $this->logoData = 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}