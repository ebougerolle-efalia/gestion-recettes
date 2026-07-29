<?php
namespace App\Twig;

use App\Repository\PurchaseInvoiceRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Compteur de factures en attente, pour la pastille de navigation.
 *
 * Sert à rendre visible ce qui arrive tout seul : une facture reçue sans action
 * de l'utilisateur doit se signaler, sinon elle dort dans la file.
 */
class InvoiceExtension extends AbstractExtension
{
    private ?int $cache = null;

    public function __construct(private PurchaseInvoiceRepository $invoices) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('invoice_pending_count', [$this, 'getPendingCount']),
        ];
    }

    /** Compté une seule fois par requête, la navigation étant rendue sur chaque page. */
    public function getPendingCount(): int
    {
        return $this->cache ??= $this->invoices->countPending();
    }
}
