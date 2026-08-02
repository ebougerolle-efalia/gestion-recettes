<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Référence des jetons de conception.
 *
 * Page interne, sans lien depuis la navigation : elle sert à juger une
 * décision graphique sur de vrais composants plutôt que sur un nuancier
 * abstrait, et à comparer l'existant au proposé avant de toucher aux 21
 * templates de l'application.
 *
 * Elle reste après la refonte : c'est l'endroit où l'on vérifie qu'un nouveau
 * jeton tient ses promesses de contraste avant de l'employer.
 */
#[IsGranted('ROLE_ADMIN')]
class DesignController extends AbstractController
{
    #[Route('/_jetons', name: 'app_design_jetons', methods: ['GET'])]
    public function jetons(): Response
    {
        return $this->render('_design/jetons.html.twig', [
            /**
             * Rapports calculés, pas estimés. La formule est celle du WCAG 2.1 :
             * (L_clair + 0,05) / (L_sombre + 0,05), sur la luminance relative.
             * Seuils : 4,5 pour du texte courant, 3 pour du grand texte ou un
             * élément d'interface.
             */
            'contrastes' => [
                ['Encre actuelle sur fond actuel', '#1c2434', '#f1f5f9', 14.19],
                ['Laiton actuel sur blanc',        '#E3B558', '#FFFFFF',  1.91],
                ['Gris secondaire actuel',         '#9ca3af', '#FFFFFF',  2.54],
                ['Ardoise sur craie',              '#16202B', '#FBFCFB', 16.01],
                ['Ardoise sur inox',               '#16202B', '#E8EBE9', 13.71],
                ['Laiton patiné sur craie',        '#7E5B1E', '#FBFCFB',  6.00],
                ['Laiton sur ardoise',             '#C9973F', '#16202B',  6.26],
                ['Sang sur craie',                 '#A8342A', '#FBFCFB',  6.40],
                ['Pousse sur craie',               '#3F6B52', '#FBFCFB',  5.94],
            ],
        ]);
    }
}
