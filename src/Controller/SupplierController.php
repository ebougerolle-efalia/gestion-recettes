<?php
namespace App\Controller;

use App\Repository\SupplierRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_EDITOR')]
class SupplierController extends AbstractController
{
    #[Route('/fournisseurs', name: 'app_supplier_index')]
    public function index(SupplierRepository $repo): Response
    {
        return $this->render('supplier/index.html.twig', [
            'suppliers' => $repo->findAllWithStats(),
        ]);
    }

    #[Route('/fournisseurs/{id}', name: 'app_supplier_show', requirements: ['id' => '\d+'])]
    public function show(int $id, SupplierRepository $repo): Response
    {
        $supplier = $repo->find($id);
        if (!$supplier) throw $this->createNotFoundException('Fournisseur introuvable');

        $stats = $repo->findDetailStats($id);

        return $this->render('supplier/show.html.twig', [
            'supplier' => $supplier,
            'invoices' => $stats['invoices'],
            'products' => $stats['products'],
            'history'  => $stats['history_by_ingredient'],
        ]);
    }
}
