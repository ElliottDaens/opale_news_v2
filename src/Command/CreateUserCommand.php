<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/*
CreateUserCommand

QUOI : Création d’un compte utilisateur en ligne de commande (mot de passe hashé, rôle paramétrable).

COMMENT : Valide email et longueur du mot de passe, vérifie l’unicité, persiste via Doctrine.

OÙ : `bin/console app:user:create` pour bootstrap admin sans formulaire.

POURQUOI : Provisionner le premier administrateur ou des comptes techniques hors UI.
*/

#[AsCommand(name: 'app:user:create', description: 'Crée un utilisateur (admin par défaut).')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email du compte')
            ->addArgument('password', InputArgument::REQUIRED, 'Mot de passe')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom affiché')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Rôle principal', 'ROLE_ADMIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getArgument('email')));
        $password = (string) $input->getArgument('password');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Email invalide.');

            return Command::FAILURE;
        }

        if (strlen($password) < 8) {
            $io->error('Mot de passe trop court (8 caractères minimum).');

            return Command::FAILURE;
        }

        if ($this->userRepo->findOneByEmail($email) !== null) {
            $io->error("Un utilisateur existe déjà avec l'email $email.");

            return Command::FAILURE;
        }

        $user = (new User())
            ->setEmail($email)
            ->setDisplayName($input->getOption('name'))
            ->setRoles([(string) $input->getOption('role')]);

        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Utilisateur créé : %s (id=%d, rôle=%s)', $email, $user->getId(), $input->getOption('role')));

        return Command::SUCCESS;
    }
}
