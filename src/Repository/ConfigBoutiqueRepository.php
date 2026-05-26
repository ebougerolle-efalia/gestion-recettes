<?php
namespace App\Repository;

use App\Entity\ConfigBoutique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfigBoutique>
 */
class ConfigBoutiqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfigBoutique::class);
    }

    /**
     * Renvoie l'unique configuration de l'établissement.
     * La crée avec des valeurs par défaut au premier appel.
     */
    public function getConfig(): ConfigBoutique
    {
        $config = $this->findOneBy([]);

        if (!$config) {
            $config = new ConfigBoutique();
            $em = $this->getEntityManager();
            $em->persist($config);
            $em->flush();
        }

        return $config;
    }
}