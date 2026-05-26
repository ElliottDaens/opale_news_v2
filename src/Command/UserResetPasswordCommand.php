<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/*
UserResetPasswordCommand

QUOI : Réinitialisation autonome du mot de passe d'un utilisateur existant en ligne de commande.

COMMENT : Récupère l'utilisateur par email (argument ou prompt), demande le nouveau mot de passe en saisie masquée (`askHidden`), hashe via `UserPasswordHasherInterface` et flush.

OÙ : `bin/console app:user:reset-password [email]` — utile en prod si l'admin perd son mot de passe.

POURQUOI : Éviter de recréer un compte complet via `app:user:create` pour un simple oubli.
*/

#[AsCommand(name: 'app:user:reset-password', description: 'Réinitialise le mot de passe d\'un utilisateur existant.')]
final class UserResetPasswordCommand extends Command
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
        $this->addArgument('email', InputArgument::OPTIONAL, 'Email du compte à réinitialiser');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        if ($email === '') {
            $email = (string) $io->ask('Email du compte');
        }
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Email invalide.');

            return Command::FAILURE;
        }

        $user = $this->userRepo->findOneByEmail($email);
        if ($user === null) {
            $io->error("Aucun utilisateur trouvé avec l'email $email.");

            return Command::FAILURE;
        }

        $password = (string) $io->askHidden('Nouveau mot de passe (saisie masquée)', function (?string $value): string {
            $value = (string) $value;
            if (strlen($value) < 8) {
                throw new \RuntimeException('Mot de passe trop court (8 caractères minimum).');
            }

            return $value;
        });

        $confirm = (string) $io->askHidden('Confirmer le mot de passe');
        if ($confirm !== $password) {
            $io->error('Les deux saisies ne correspondent pas.');

            return Command::FAILURE;
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->flush();

        $io->success(sprintf('Mot de passe réinitialisé pour %s (id=%d).', $user->getEmail(), $user->getId()));

        return Command::SUCCESS;
    }
}
