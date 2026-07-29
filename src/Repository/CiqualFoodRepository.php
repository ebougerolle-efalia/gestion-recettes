<?php
namespace App\Repository;

use App\Entity\CiqualFood;
use App\Service\CiqualMatcher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CiqualFood>
 */
class CiqualFoodRepository extends ServiceEntityRepository
{
    private ?bool $trigramAvailable = null;

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
     * Sur PostgreSQL, on ajoute les aliments trigramme-proches (pg_trgm), ce qui
     * rattrape les variantes et les fautes de frappe qu'un LIKE strict laisse
     * passer. Ailleurs, seul le LIKE s'applique : le résultat est un
     * sur-ensemble de candidats, le score final ne dépend pas du moteur.
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

        $candidates = $qb->where($or)->setMaxResults($max)->getQuery()->getResult();

        if ($this->supportsTrigram()) {
            $candidates = $this->mergeTrigramCandidates($candidates, $tokens, $max);
        }

        return $candidates;
    }

    /**
     * Complète la présélection par similarité trigramme, sans doublonner ce que
     * le LIKE a déjà trouvé.
     *
     * @param CiqualFood[] $candidates
     * @param string[]     $tokens
     * @return CiqualFood[]
     */
    private function mergeTrigramCandidates(array $candidates, array $tokens, int $max): array
    {
        $known = [];
        foreach ($candidates as $food) {
            $known[$food->getCode()] = true;
        }

        $codes = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT code FROM ciqual_foods
              WHERE nom_norm % :q
              ORDER BY similarity(nom_norm, :q) DESC
              LIMIT :max',
            ['q' => implode(' ', $tokens), 'max' => $max],
            ['max' => \Doctrine\DBAL\ParameterType::INTEGER]
        );

        $missing = array_values(array_filter($codes, fn ($code) => !isset($known[$code])));
        if (!$missing) {
            return $candidates;
        }

        return array_merge($candidates, $this->findBy(['code' => $missing]));
    }

    /** Vrai si le moteur est PostgreSQL avec pg_trgm installé. */
    private function supportsTrigram(): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        if (!$conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return false;
        }

        // Mis en cache : appelé une fois par ingrédient lors d'un appariement en masse.
        return $this->trigramAvailable ??= (bool) $conn->fetchOne(
            "SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'"
        );
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
