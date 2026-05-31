<?php
namespace App\Repository;

use App\Entity\CiqualFood;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CiqualFood>
 */
class CiqualFoodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CiqualFood::class);
    }

    /**
     * Recherche par nom (insensible à la casse), pour l'autocomplétion.
     * @return CiqualFood[]
     */
    public function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->where('LOWER(c.nom) LIKE :q')
            ->setParameter('q', '%' . mb_strtolower($q) . '%')
            ->orderBy('c.nom', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
