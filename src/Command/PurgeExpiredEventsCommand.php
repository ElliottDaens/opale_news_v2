<?php

namespace App\Command;

use App\Repository\EventRepository;
use App\Service\PineconeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/*
PurgeExpiredEventsCommand

QUOI : Purge les événements dont la date de fin est dépassée depuis plus de N jours : retrait de l'index Pinecone et soft-delete en base.

COMMENT : `EventRepository::findExpiredEvents` puis pour chaque event : `PineconeService::deleteById` AVANT `Event::softDelete` (Pinecone idempotent, retry safe en cas d'échec réseau).

OÙ : `bin/console app:radar:purge-expired` — destiné à un cron quotidien.

POURQUOI : Empêcher la pollution de l'index vectoriel par des manifestations terminées, tout en conservant l'historique en base via la suppression logique.
*/

#[AsCommand(name: 'app:radar:purge-expired', description: 'Désindexe de Pinecone et archive (soft-delete) les événements dont la date de fin est dépassée.')]
final class PurgeExpiredEventsCommand extends Command
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly PineconeService $pinecone,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Nombre de jours de grâce après la date de fin avant purge.', 2)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste les événements à purger sans rien modifier.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(0, (int) $input->getOption('days'));
        $dryRun = (bool) $input->getOption('dry-run');

        $expired = $this->events->findExpiredEvents($days);

        if ($expired === []) {
            $io->success(sprintf('Aucun événement expiré depuis plus de %d jour(s).', $days));

            return Command::SUCCESS;
        }

        $io->note(sprintf('%d événement(s) expiré(s) depuis plus de %d jour(s).', count($expired), $days));

        if ($dryRun) {
            $io->table(
                ['ID', 'Titre', 'Fin', 'Indexé'],
                array_map(
                    static fn ($e) => [
                        $e->getId(),
                        $e->getTitre(),
                        ($e->getEndDate() ?? $e->getStartDate())->format('Y-m-d'),
                        $e->isIndexed() ? 'oui' : 'non',
                    ],
                    $expired,
                ),
            );
            $io->warning('Dry-run : aucune modification appliquée.');

            return Command::SUCCESS;
        }

        $purged = 0;
        $failed = 0;
        $io->progressStart(count($expired));

        foreach ($expired as $event) {
            try {
                if ($event->isIndexed()) {
                    $this->pinecone->deleteById((string) $event->getId());
                }

                $event->softDelete();
                $event->setIndexed(false);
                $purged++;
            } catch (\Throwable $e) {
                $failed++;
                $io->warning(sprintf('Échec pour l\'event #%d : %s', $event->getId(), $e->getMessage()));
            }

            $io->progressAdvance();
        }

        $this->em->flush();
        $io->progressFinish();

        if ($failed > 0) {
            $io->warning(sprintf('%d événement(s) purgé(s), %d en échec (seront retentés au prochain run).', $purged, $failed));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d événement(s) désindexé(s) de Pinecone et archivé(s).', $purged));

        return Command::SUCCESS;
    }
}
