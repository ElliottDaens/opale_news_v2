<?php

namespace App\Command;

use App\Repository\EventRepository;
use App\Service\GeminiService;
use App\Service\LexicalGate;
use App\Service\PineconeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/*
DebugSearchCommand

QUOI : Affiche le top 10 Pinecone d'une requête, avec score sémantique brut, classification (primary/
secondary/rejet) et raison du rejet — outil de validation manuelle de la pertinence.

COMMENT : Re-implémente la même politique de seuillage + filet lexical que `HomeController`, mais sans
les couches HTTP/JSON et le geo-boost. Permet de vérifier qu'aucun faux positif sémantique ne passe.

OÙ : `bin/console app:debug:search "sport" --top=10`. Usage de dev/QA uniquement.

POURQUOI : Le bug "poterie sur sport" venait d'un cocktail de seuils trop bas + geo-boost contaminant.
Sans outil de visualisation, impossible de calibrer les seuils sans regression.
*/
#[AsCommand(
    name: 'app:debug:search',
    description: 'Affiche le top Pinecone et la classification de pertinence pour une requête.',
)]
final class DebugSearchCommand extends Command
{
    // Reprises des constantes de HomeController — gardées synchrones (constantes scalaires recopiées
    // plutôt que dépendance directe au contrôleur, pour rester un outil de debug autonome).
    private const LEXICAL_FLOOR = 0.62;
    private const STRONG_SEMANTIC = 0.80;
    private const PRIMARY_MIN_TOP = 0.70;
    private const PRIMARY_MAX_GAP = 0.04;

    public function __construct(
        private readonly GeminiService $gemini,
        private readonly PineconeService $pinecone,
        private readonly EventRepository $events,
        private readonly LexicalGate $lexicalGate,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Requête à tester (ex: "sport")')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Nombre de matches Pinecone à inspecter', 15);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $query = (string) $input->getArgument('query');
        $topK = max(5, (int) $input->getOption('top'));

        $io->title(sprintf('Debug recherche : « %s » (topK=%d)', $query, $topK));

        $vector = $this->gemini->getEmbedding($query, GeminiService::TASK_QUERY);
        $matches = $this->pinecone->query($vector, $topK);

        if ($matches === []) {
            $io->warning('Aucun match Pinecone.');
            return Command::SUCCESS;
        }

        $ids = array_map(static fn (array $m): int => $m['id'], $matches);
        $events = $this->events->findByIdsPreservingOrder($ids);
        $eventsById = [];
        foreach ($events as $event) {
            $eventsById[$event->getId()] = $event;
        }

        $tokens = $this->lexicalGate->tokenize($query);
        $io->writeln(sprintf('Tokens lexicaux retenus : %s', $tokens === [] ? '(aucun)' : implode(', ', $tokens)));
        $io->newLine();

        $semanticTop = 0.0;
        foreach ($matches as $m) {
            if ($m['score'] > $semanticTop) {
                $semanticTop = $m['score'];
            }
        }
        $io->writeln(sprintf('Score sémantique top brut : <info>%.4f</info>', $semanticTop));
        $io->writeln(sprintf('LEXICAL_FLOOR             : %.4f (avec lex ✓)', self::LEXICAL_FLOOR));
        $io->writeln(sprintf('STRONG_SEMANTIC           : %.4f (sans lex)', self::STRONG_SEMANTIC));
        $io->newLine();

        // Top parmi les matches acceptables (politique = lex ✓ ET >= LEXICAL_FLOOR, ou >= STRONG_SEMANTIC).
        $acceptableTop = 0.0;
        foreach ($matches as $m) {
            $event = $eventsById[$m['id']] ?? null;
            if ($event === null) {
                continue;
            }
            $lex = $this->lexicalGate->eventMatchesQuery($event, $query);
            $score = $m['score'];
            $acceptable = ($score >= self::STRONG_SEMANTIC) || ($lex && $score >= self::LEXICAL_FLOOR);
            if ($acceptable && $score > $acceptableTop) {
                $acceptableTop = $score;
            }
        }
        $primaryCutoff = $acceptableTop > 0 ? $acceptableTop - self::PRIMARY_MAX_GAP : 0.0;

        $rows = [];
        foreach ($matches as $m) {
            $event = $eventsById[$m['id']] ?? null;
            $titre = $event !== null ? $event->getTitre() : '(non trouvé)';
            $categorie = $event !== null ? $event->getCategorie() : '-';
            $score = $m['score'];

            $lexicalOk = $event !== null && $this->lexicalGate->eventMatchesQuery($event, $query);

            [$classif, $raison] = $this->classify($score, $primaryCutoff, $acceptableTop, $lexicalOk);

            $rows[] = [
                $m['id'],
                $this->truncate($titre, 40),
                $categorie,
                sprintf('%.4f', $score),
                $lexicalOk ? '✓' : '✗',
                $classif,
                $raison,
            ];
        }

        $io->table(
            ['ID', 'Titre', 'Catégorie', 'Score', 'Lex', 'Classif', 'Raison'],
            $rows,
        );

        return Command::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function classify(float $score, float $primaryCutoff, float $acceptableTop, bool $lexicalOk): array
    {
        $acceptable = ($score >= self::STRONG_SEMANTIC) || ($lexicalOk && $score >= self::LEXICAL_FLOOR);
        if (!$acceptable) {
            if ($score < self::LEXICAL_FLOOR) {
                return ['REJET', sprintf('< %.2f', self::LEXICAL_FLOOR)];
            }
            return ['REJET', 'lex KO et < strong'];
        }

        if ($acceptableTop >= self::PRIMARY_MIN_TOP && $score >= $primaryCutoff) {
            return ['PRIMARY', $score >= self::STRONG_SEMANTIC ? 'score fort' : 'lex OK'];
        }

        return ['SECONDARY', $score >= self::STRONG_SEMANTIC ? 'score fort' : 'lex OK'];
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1) . '…';
    }
}
