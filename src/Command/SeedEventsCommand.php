<?php

namespace App\Command;

use App\DataFixture\OpaleEventsFixture;
use App\Entity\Event;
use App\Enum\EventStatus;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/*
SeedEventsCommand

QUOI : Commande console pour injecter les 50 événements de démo (`OpaleEventsFixture`) en base.

COMMENT : Option `--reset` purge la table (filtre soft-delete désactivé) puis insert avec statut `Approved`.

OÙ : `bin/console app:seed-events` en environnement de développement ou démo.

POURQUOI : Remplir rapidement les jeux de test pour Pinecone et l’UI sans saisie manuelle.
*/

#[AsCommand(name: 'app:seed-events', description: 'Insère 50 événements réalistes de la Côte d\'Opale.')]
final class SeedEventsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Supprime tous les événements existants avant de seeder.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('reset')) {
            $this->em->getFilters()->disable('soft_deleted');
            $deleted = $this->em->createQuery('DELETE FROM ' . Event::class . ' e')->execute();
            $this->em->getFilters()->enable('soft_deleted');
            $io->note("Reset : $deleted événement(s) supprimé(s).");
        }

        $existingCount = count($this->repository->findAll());
        if ($existingCount > 0 && !$input->getOption('reset')) {
            $io->warning("$existingCount événement(s) déjà en base. Utilisez --reset pour repartir de zéro.");

            return Command::SUCCESS;
        }

        $events = OpaleEventsFixture::all();
        $io->progressStart(count($events));

        foreach ($events as $row) {
            $event = (new Event())
                ->setTitre($row['titre'])
                ->setNomOrganisateur($row['nomOrganisateur'])
                ->setEmailContact($row['emailContact'])
                ->setPrix($row['prix'])
                ->setCategorie($row['categorie'])
                ->setStartDate(new \DateTimeImmutable($row['startDate']))
                ->setEndDate(isset($row['endDate']) ? new \DateTimeImmutable($row['endDate']) : null)
                ->setStartTime(isset($row['startTime']) ? new \DateTimeImmutable($row['startTime']) : null)
                ->setEndTime(isset($row['endTime']) ? new \DateTimeImmutable($row['endTime']) : null)
                ->setAdresse($row['adresse'])
                ->setVille($row['ville'])
                ->setCodePostal($row['codePostal'])
                ->setLatitude($row['latitude'])
                ->setLongitude($row['longitude'])
                ->setDescription($row['description'])
                ->setStatus(EventStatus::Approved);

            $this->em->persist($event);
            $io->progressAdvance();
        }

        $this->em->flush();
        $io->progressFinish();

        $io->success(sprintf('%d événements ajoutés en base (status = Approved).', count($events)));
        $io->note('Lancez `bin/console app:radar:reindex --clean` pour pousser les vecteurs sur Pinecone.');

        return Command::SUCCESS;
    }
}
