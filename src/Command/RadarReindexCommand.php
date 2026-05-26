<?php

namespace App\Command;

use App\Repository\EventRepository;
use App\Service\EventIndexer;
use App\Service\PineconeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/*
RadarReindexCommand

QUOI : Indexe ou ré-indexe les événements approuvés dans Pinecone depuis la CLI (cron-friendly).

COMMENT : `--clean` purge l'index distant et remet `indexed = false` ; `--all` force la ré-indexation de tous les events approuvés ; sinon ne traite que les en attente.

OÙ : `bin/console app:radar:reindex` — remplace l'ancienne route HTTP `/init-radar` (mutation d'état via URL publique supprimée).

POURQUOI : Couper toute exposition HTTP d'une opération destructrice et permettre un pilotage planifié (cron).
*/

#[AsCommand(name: 'app:radar:reindex', description: 'Indexe ou ré-indexe les événements approuvés dans Pinecone.')]
final class RadarReindexCommand extends Command
{
    public function __construct(
        private readonly EventIndexer $indexer,
        private readonly PineconeService $pinecone,
        private readonly EventRepository $events,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('clean', null, InputOption::VALUE_NONE, "Purge l'index Pinecone et remet tous les événements à indexed=false avant de ré-indexer.")
            ->addOption('all', null, InputOption::VALUE_NONE, 'Force la ré-indexation de tous les événements approuvés (sinon : uniquement les en attente).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clean = (bool) $input->getOption('clean');
        $forceAll = (bool) $input->getOption('all');

        if ($clean) {
            $this->pinecone->deleteAll();
            foreach ($this->events->findAllOrdered() as $e) {
                $e->setIndexed(false);
            }
            $this->em->flush();
            $io->note('Index Pinecone purgé et flags indexed remis à false.');
        }

        $events = $forceAll || $clean
            ? $this->events->findAllOrdered()
            : $this->events->findPendingIndexation();

        if ($events === []) {
            $io->success('Aucun nouvel événement à indexer (utilisez --all pour tout ré-indexer ou --clean pour purger Pinecone d\'abord).');

            return Command::SUCCESS;
        }

        $io->progressStart(count($events));
        $count = 0;
        foreach ($events as $event) {
            $this->indexer->index($event);
            $count++;
            $io->progressAdvance();
        }
        $io->progressFinish();

        $io->success(sprintf(
            '%s%d événement(s) %sindexé(s) sur le radar Pinecone.',
            $clean ? 'Index purgé puis ' : '',
            $count,
            $forceAll && !$clean ? 'ré-' : '',
        ));

        return Command::SUCCESS;
    }
}
