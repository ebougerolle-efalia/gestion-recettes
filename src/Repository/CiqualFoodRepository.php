<?php
namespace App\Repository;

use App\Entity\CiqualFood;
use App\Service\CiqualMatcher;
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
     * Recherche par nom pour l'autocomplétion.
     *
     * La comparaison porte sur `nom_norm` (ASCII minuscule) et non sur `nom` :
     * LOWER() en SQLite et MySQL ne replie ni les ligatures ni les majuscules
     * accentuées, « Œuf » resterait introuvable.
     *
     * @return CiqualFood[]
     */
    public function search(string $q, int $limit = 20): array
    {
        $q = CiqualMatcher::normalize($q);
        if ($q === '') {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->where('c.nomNorm LIKE :q')
            ->setParameter('q', '%' . $q . '%')
            ->orderBy('c.nom', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Présélection pour l'appariement automatique : tous les aliments partageant
     * au moins un mot avec l'ingrédient. Le classement fin est fait en PHP par
     * CiqualMatcher.
     *
     * @param string[] $tokens mots déjà normalisés
     * @return CiqualFood[]
     */
    public function findCandidatesByTokens(array $tokens, int $max = 500): array
    {
        if (!$tokens) {
            return [];
        }

        $qb = $this->createQueryBuilder('c');
        $or = $qb->expr()->orX();
        foreach (array_values($tokens) as $i => $token) {
            $or->add("c.nomNorm LIKE :t$i");
            $qb->setParameter("t$i", '%' . $token . '%');
        }

        return $qb->where($or)->setMaxResults($max)->getQuery()->getResult();
    }

    /** Nombre d'aliments dont le nom normalisé n'a pas encore été calculé. */
    public function countMissingNorm(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.code)')
            ->where('c.nomNorm IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
