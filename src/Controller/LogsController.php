<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/*
LogsController

QUOI : Visionneuse admin des logs Monolog — application (`{env}.log`) + appels HTTP sortants (`http_client.log`).

COMMENT : Tail des N dernières lignes du fichier (lecture à reculons sur 1 Mo max), parsing JSON (prod) ou format ligne (dev),
         filtre par niveau via query string. Sélection automatique du fichier rotating le plus récent en prod.

OÙ : Routes `/admin/logs` et `/admin/logs/http`, accessibles aux `ROLE_ADMIN` uniquement.

POURQUOI : Donner un accès direct aux erreurs et aux appels sortants (Gemini, Pinecone) sans accès SSH au conteneur.
*/

#[IsGranted('ROLE_ADMIN')]
final class LogsController extends AbstractController
{
    private const DEFAULT_LINES = 200;
    private const MAX_LINES = 1000;
    // 1 Mo de tail : suffisant pour 200-1000 lignes même verbeuses,
    // sans charger en mémoire des fichiers de plusieurs centaines de Mo.
    private const TAIL_BYTES = 1_048_576;
    private const LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    public function __construct(
        #[Autowire(param: 'kernel.logs_dir')] private readonly string $logsDir,
        #[Autowire(param: 'kernel.environment')] private readonly string $env,
    ) {}

    /**
     * Vue principale : log applicatif (toutes les erreurs/warnings du site).
     */
    #[Route('/admin/logs', name: 'app_admin_logs', methods: ['GET'])]
    public function appLogs(Request $request): Response
    {
        return $this->renderLogs($request, 'app');
    }

    /**
     * Vue dédiée aux appels HTTP sortants (canal `http_client` → Gemini, Pinecone, Google Maps).
     */
    #[Route('/admin/logs/http', name: 'app_admin_logs_http', methods: ['GET'])]
    public function httpLogs(Request $request): Response
    {
        return $this->renderLogs($request, 'http');
    }

    private function renderLogs(Request $request, string $source): Response
    {
        $lines = max(10, min(self::MAX_LINES, (int) $request->query->get('lines', self::DEFAULT_LINES)));
        $rawLevel = strtoupper((string) $request->query->get('level', ''));
        $levelFilter = in_array($rawLevel, self::LEVELS, true) ? $rawLevel : null;

        $file = $this->resolveLogFile($source);
        $entries = [];
        $totalShown = 0;
        $fileSize = null;
        $fileMtime = null;

        if ($file !== null && is_readable($file)) {
            $fileSize = filesize($file) ?: 0;
            $fileMtime = filemtime($file) ?: null;
            $rawLines = $this->tailFile($file, self::TAIL_BYTES);
            foreach ($rawLines as $raw) {
                $entry = $this->parseLine($raw);
                if ($levelFilter !== null && $entry['level'] !== $levelFilter) {
                    continue;
                }
                $entries[] = $entry;
            }
            // Tri du plus récent au plus ancien.
            $entries = array_reverse($entries);
            $totalShown = count($entries);
            $entries = array_slice($entries, 0, $lines);
        }

        return $this->render('admin/logs.html.twig', [
            'source' => $source,
            'file' => $file,
            'fileExists' => $file !== null && is_readable($file),
            'fileSize' => $fileSize,
            'fileMtime' => $fileMtime !== null ? (new \DateTimeImmutable())->setTimestamp($fileMtime) : null,
            'entries' => $entries,
            'totalShown' => $totalShown,
            'lines' => $lines,
            'level' => $levelFilter,
            'levels' => self::LEVELS,
            'env' => $this->env,
            'profilerAvailable' => $this->env === 'dev',
        ]);
    }

    /**
     * Sélectionne le fichier log pertinent selon la source et l'environnement.
     *
     * Comment : en dev, fichier unique (`dev.log`, `http_client.log`) ; en prod, rotating
     * (`prod-YYYY-MM-DD.log`) → on prend le plus récent par mtime.
     */
    private function resolveLogFile(string $source): ?string
    {
        if ($source === 'http') {
            $candidate = $this->logsDir . '/http_client.log';
            return file_exists($candidate) ? $candidate : null;
        }

        $direct = $this->logsDir . '/' . $this->env . '.log';
        if (file_exists($direct)) {
            return $direct;
        }

        // Rotating handler (prod) : prod-YYYY-MM-DD.log
        $pattern = $this->logsDir . '/' . $this->env . '-*.log';
        $matches = glob($pattern) ?: [];
        if ($matches === []) {
            return null;
        }
        usort($matches, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

        return $matches[0];
    }

    /**
     * Lit les derniers `$maxBytes` octets du fichier et les découpe en lignes.
     * Évite de charger en mémoire les fichiers volumineux.
     *
     * @return list<string>
     */
    private function tailFile(string $path, int $maxBytes): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        try {
            fseek($handle, 0, SEEK_END);
            $size = ftell($handle);
            $readSize = (int) min($size, $maxBytes);
            if ($readSize <= 0) {
                return [];
            }
            fseek($handle, -$readSize, SEEK_END);
            $content = (string) fread($handle, $readSize);
        } finally {
            fclose($handle);
        }

        $lines = preg_split("/\r?\n/", $content) ?: [];
        // Si on n'a pas lu depuis le début, la première ligne est probablement tronquée.
        if ($size > $readSize && count($lines) > 1) {
            array_shift($lines);
        }

        return array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
    }

    /**
     * Parse une ligne Monolog (JSON formatter en prod, line formatter en dev).
     *
     * @return array{datetime: ?string, channel: ?string, level: ?string, message: string, context: ?string, raw: string}
     */
    private function parseLine(string $raw): array
    {
        $trim = trim($raw);

        // 1) Tentative JSON (prod / formatter JSON).
        if ($trim !== '' && $trim[0] === '{') {
            $decoded = json_decode($trim, true);
            if (is_array($decoded) && isset($decoded['level_name'])) {
                $contextParts = [];
                foreach (['context', 'extra'] as $key) {
                    if (!empty($decoded[$key])) {
                        $contextParts[$key] = $decoded[$key];
                    }
                }
                return [
                    'datetime' => isset($decoded['datetime']) ? (string) $decoded['datetime'] : null,
                    'channel' => isset($decoded['channel']) ? (string) $decoded['channel'] : null,
                    'level' => strtoupper((string) $decoded['level_name']),
                    'message' => (string) ($decoded['message'] ?? ''),
                    'context' => $contextParts === [] ? null : json_encode($contextParts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'raw' => $raw,
                ];
            }
        }

        // 2) Line formatter : [datetime] channel.LEVEL: message context extra
        if (preg_match('/^\[(?<datetime>[^\]]+)\]\s(?<channel>[\w\-]+)\.(?<level>[A-Z]+):\s(?<rest>.*)$/s', $raw, $m)) {
            $rest = $m['rest'];
            // Sépare message des JSON de queue (context/extra) — on coupe au premier "{" qui parse.
            $message = $rest;
            $context = null;
            $bracePos = strpos($rest, ' {');
            if ($bracePos !== false) {
                $maybeContext = trim(substr($rest, $bracePos + 1));
                if ($maybeContext !== '' && $maybeContext !== '[]') {
                    $message = rtrim(substr($rest, 0, $bracePos));
                    $context = $maybeContext;
                }
            }
            return [
                'datetime' => $m['datetime'],
                'channel' => $m['channel'],
                'level' => strtoupper($m['level']),
                'message' => $message,
                'context' => $context,
                'raw' => $raw,
            ];
        }

        // 3) Ligne non reconnue : fallback brut.
        return [
            'datetime' => null,
            'channel' => null,
            'level' => null,
            'message' => $raw,
            'context' => null,
            'raw' => $raw,
        ];
    }
}
