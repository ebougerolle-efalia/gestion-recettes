<?php
namespace App\Twig;

use App\Entity\ConfigBoutique;
use App\Repository\ConfigBoutiqueRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose la configuration de l'établissement à tous les templates Twig.
 *
 * Usage dans n'importe quel template :
 *   {{ boutique().nomEtablissement }}
 *   {{ boutique().adresseComplete }}
 *   {% if boutique().logoPath %}<img src="{{ asset(boutique().logoPath) }}">{% endif %}
 *
 * Auto-enregistré comme service grâce à autoconfigure dans services.yaml.
 */
class BoutiqueExtension extends AbstractExtension
{
    private ?ConfigBoutique $cache = null;

    public function __construct(private ConfigBoutiqueRepository $repo) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('boutique', [$this, 'getBoutique']),
        ];
    }

    public function getBoutique(): ConfigBoutique
    {
        // Mise en cache pour ne charger qu'une fois par requête.
        return $this->cache ??= $this->repo->getConfig();
    }
}