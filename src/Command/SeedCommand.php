<?php
namespace App\Command;

use App\Entity\{User, IngredientCategory, RecipeFamily};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:seed', description: 'Créer l\'admin initial et les données par défaut')]
class SeedCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('admin-password', null, InputOption::VALUE_OPTIONAL, 'Mot de passe admin', 'admin123');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userRepo = $this->em->getRepository(User::class);
        $catRepo = $this->em->getRepository(IngredientCategory::class);
        $famRepo = $this->em->getRepository(RecipeFamily::class);

        // Admin user
        if (!$userRepo->findOneBy(['username' => 'admin'])) {
            $user = new User();
            $user->setUsername('admin');
            $user->setRole('admin');
            $user->setPassword($this->hasher->hashPassword($user, $input->getOption('admin-password')));
            $this->em->persist($user);
            $output->writeln('<info>✓ Utilisateur admin créé (mot de passe: ' . $input->getOption('admin-password') . ')</info>');
        } else {
            $output->writeln('  Utilisateur admin existe déjà.');
        }

        // Default categories
        $cats = ['Viande' => 1, 'Epices' => 2, 'Emballage' => 3, 'Autres' => 99];
        foreach ($cats as $name => $order) {
            if (!$catRepo->findOneBy(['name' => $name])) {
                $c = new IngredientCategory();
                $c->setName($name);
                $c->setSortOrder($order);
                $this->em->persist($c);
                $output->writeln("<info>✓ Catégorie « $name »</info>");
            }
        }

        // Default families
        $fams = ['Terrine' => 1, 'Pâté' => 2, 'Saucisse' => 3, 'Jambon' => 4, 'Cuit' => 5, 'Sec' => 6, 'Autres' => 99];
        foreach ($fams as $name => $order) {
            if (!$famRepo->findOneBy(['name' => $name])) {
                $f = new RecipeFamily();
                $f->setName($name);
                $f->setSortOrder($order);
                $this->em->persist($f);
                $output->writeln("<info>✓ Famille « $name »</info>");
            }
        }

        $this->em->flush();
        $output->writeln('<info>Seed terminé.</info>');

        return Command::SUCCESS;
    }
}
