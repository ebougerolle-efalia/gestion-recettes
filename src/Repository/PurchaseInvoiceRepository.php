<?php
namespace App\Repository;

use App\Entity\PurchaseInvoice;
use App\Entity\Supplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseInvoice>
 */
class PurchaseInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseInvoice::class);
    }

    /** File d'attente : les factures reçues qui attendent un arbitrage. */
    public function findPending(): array
    {
        return $this->findBy(
            ['status' => PurchaseInvoice::STATUS_PENDING],
            ['invoiceDate' => 'DESC', 'id' => 'DESC']
        );
    }

    /** Factures déjà traitées (validées ou écartées), les plus récentes d'abord. */
    public function findProcessed(int $limit = 50): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.status != :pending')
            ->setParameter('pending', PurchaseInvoice::STATUS_PENDING)
            ->orderBy('i.invoiceDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.status = :pending')
            ->setParameter('pending', PurchaseInvoice::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Détection de doublon : un fournisseur ne facture pas deux fois sous le même
     * numéro. C'est la garantie qu'une facture reçue deux fois (mail transféré,
     * relance fournisseur) ne crée pas deux prix.
     */
    public function findDuplicate(Supplier $supplier, string $invoiceId): ?PurchaseInvoice
    {
        return $this->findOneBy(['supplier' => $supplier, 'invoiceId' => $invoiceId]);
    }
}
