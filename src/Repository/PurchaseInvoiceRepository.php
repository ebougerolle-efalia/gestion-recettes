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

    /** File d'attente : les factures lues, dont les lignes attendent un arbitrage. */
    public function findPending(): array
    {
        return $this->findBy(
            ['status' => PurchaseInvoice::STATUS_PENDING],
            ['invoiceDate' => 'DESC', 'id' => 'DESC']
        );
    }

    /**
     * Factures reçues mais illisibles pour le moteur, en attente de saisie.
     *
     * Les plus anciennes d'abord : contrairement à la file de validation, ici
     * c'est l'ancienneté qui est le problème — une facture en quarantaine depuis
     * trois semaines est un prix qui manque au calcul des marges.
     */
    public function findToCapture(): array
    {
        return $this->findBy(
            ['status' => PurchaseInvoice::STATUS_TO_CAPTURE],
            ['importedAt' => 'ASC', 'id' => 'ASC']
        );
    }

    /** Factures déjà traitées (validées ou écartées), les plus récentes d'abord. */
    public function findProcessed(int $limit = 50): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.status NOT IN (:open)')
            ->setParameter('open', [PurchaseInvoice::STATUS_PENDING, PurchaseInvoice::STATUS_TO_CAPTURE])
            ->orderBy('i.invoiceDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPending(): int
    {
        return $this->countByStatus(PurchaseInvoice::STATUS_PENDING);
    }

    public function countToCapture(): int
    {
        return $this->countByStatus(PurchaseInvoice::STATUS_TO_CAPTURE);
    }

    private function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Doublon de fichier : même document reçu deux fois.
     *
     * Complète la détection par (fournisseur, numéro), inopérante tant qu'une
     * facture illisible n'a ni l'un ni l'autre. Rend la relève rejouable.
     */
    public function findByPayloadHash(string $hash): ?PurchaseInvoice
    {
        return $this->findOneBy(['payloadHash' => $hash]);
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
