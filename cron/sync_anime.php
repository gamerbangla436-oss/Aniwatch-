<?php
require_once __DIR__ . '/../bootstrap.php';

function aniread_log(PDO $pdo, string $type, string $status, string $message = '', int $added = 0, int $updated = 0): int {
    $stmt = $pdo->prepare('INSERT INTO sync_logs (type, status, message, records_added, records_updated, started_at, finished_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$type, $status, $message, $added, $updated]);
    return (int)$pdo->lastInsertId();
}

function aniread_fetch(string $endpoint, array $params = [], array $headers = []): ?array {
    $base = rtrim((string)($_ENV['JIKAN_BASE'] ?? 'https://api.jikan.moe/v4'), '/');
    $url = $base . $endpoint;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 429) {
        sleep(2);
        return aniread_fetch($endpoint, $params, $headers);
    }
    if ($httpCode !== 200 || $body === false || $body === '') {
        return null;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return null;
    }
    return $decoded['data'] ?? $decoded;
}

function aniread_validate_text($value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    return trim((string)$value);
}

function aniread_extract_genres(array $anime): array {
    if (empty($anime['genres'])) {
        return [];
    }
    $genres = [];
    foreach ($anime['genres'] as $genre) {
        $name = aniread_validate_text($genre['name'] ?? null);
        if ($name) {
            $genres[] = $name;
        }
    }
    return $genres;
}

function aniread_upsert_anime(PDO $pdo, array $anime): string {
    $malId = (int)($anime['mal_id'] ?? 0);
    if ($malId <= 0) {
        return 'skipped';
    }

    $title = aniread_validate_text($anime['title'] ?? null) ?: 'Unknown Title';
    $englishTitle = aniread_validate_text($anime['title_english'] ?? null);
    $japaneseTitle = aniread_validate_text($anime['title_japanese'] ?? null);
    $synopsis = $anime['synopsis'] ?? '';
    $year = isset($anime['year']) && is_numeric($anime['year']) ? (int)$anime['year'] : null;
    $score = isset($anime['score']) && is_numeric($anime['score']) ? (float)$anime['score'] : null;
    $rank = isset($anime['rank']) && is_numeric($anime['rank']) ? (int)$anime['rank'] : null;
    $popularity = isset($anime['popularity']) && is_numeric($anime['popularity']) ? (int)$anime['popularity'] : null;
    $type = aniread_validate_text($anime['type'] ?? null) ?: 'TV';
    $status = aniread_validate_text($anime['status'] ?? null) ?: 'Unknown';
    $episodes = isset($anime['episodes']) && is_numeric($anime['episodes']) ? (int)$anime['episodes'] : 0;
    $duration = aniread_validate_text($anime['duration'] ?? null);
    $poster = aniread_validate_text($anime['images']['jpg']['image_url'] ?? null);
    $banner = aniread_validate_text($anime['images']['jpg']['large_image_url'] ?? null);
    $trailer = aniread_validate_text($anime['trailer']['embed_url'] ?? null);
    $season = aniread_validate_text($anime['season'] ?? null);
    $source = aniread_validate_text($anime['source'] ?? null);

    $stmt = $pdo->prepare('SELECT id FROM anime WHERE mal_id = ? LIMIT 1');
    $stmt->execute([$malId]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $stmt = $pdo->prepare('UPDATE anime SET title = ?, english_title = ?, japanese_title = ?, synopsis = ?, year = ?, score = ?, rank = ?, popularity = ?, type = ?, status = ?, episodes = ?, duration = ?, poster = ?, banner = ?, trailer = ?, season = ?, source = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$title, $englishTitle, $japaneseTitle, $synopsis, $year, $score, $rank, $popularity, $type, $status, $episodes, $duration, $poster, $banner, $trailer, $season, $source, (int)$existingId]);
        $animeId = (int)$existingId;
        $result = 'updated';
    } else {
        $stmt = $pdo->prepare('INSERT INTO anime (mal_id, title, english_title, japanese_title, synopsis, year, score, rank, popularity, type, status, episodes, duration, poster, banner, trailer, season, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$malId, $title, $englishTitle, $japaneseTitle, $synopsis, $year, $score, $rank, $popularity, $type, $status, $episodes, $duration, $poster, $banner, $trailer, $season, $source]);
        $animeId = (int)$pdo->lastInsertId();
        $result = 'added';
    }

    // Link genres
    $pdo->prepare('DELETE FROM anime_genres WHERE anime_id = ?')->execute([$animeId]);
    foreach (aniread_extract_genres($anime) as $genreName) {
        $genreStmt = $pdo->prepare('SELECT id FROM genres WHERE name = ? LIMIT 1');
        $genreStmt->execute([$genreName]);
        $genreId = $genreStmt->fetchColumn();

        if (!$genreId) {
            $insertGenre = $pdo->prepare('INSERT INTO genres (name) VALUES (?)');
            $insertGenre->execute([$genreName]);
            $genreId = $pdo->lastInsertId();
        }

        $linkStmt = $pdo->prepare('INSERT IGNORE INTO anime_genres (anime_id, genre_id) VALUES (?, ?)');
        $linkStmt->execute([$animeId, $genreId]);
    }

    return $result;
}

function aniread_sync_mode(PDO $pdo, string $mode = 'top'): array {
    $results = ['added' => 0, 'updated' => 0, 'skipped' => 0];

    $modeMap = [
        'tv' => ['endpoint' => '/anime', 'params' => ['type' => 'TV', 'page' => 1, 'limit' => 25, 'sfw' => true]],
        'movie' => ['endpoint' => '/anime', 'params' => ['type' => 'Movie', 'page' => 1, 'limit' => 25, 'sfw' => true]],
        'ova' => ['endpoint' => '/anime', 'params' => ['type' => 'OVA', 'page' => 1, 'limit' => 25, 'sfw' => true]],
        'ona' => ['endpoint' => '/anime', 'params' => ['type' => 'ONA', 'page' => 1, 'limit' => 25, 'sfw' => true]],
        'special' => ['endpoint' => '/anime', 'params' => ['type' => 'Special', 'page' => 1, 'limit' => 25, 'sfw' => true]],
        'airing' => ['endpoint' => '/anime', 'params' => ['status' => 'airing', 'page' => 1, 'limit' => 25, 'sfw' => true]],
        'upcoming' => ['endpoint' => '/anime', 'params' => ['status' => 'upcoming', 'page' => 1, 'limit' => 25, 'sfw' => true]],
        'season' => ['endpoint' => '/seasons/now', 'params' => ['page' => 1, 'limit' => 25]],
        'top' => ['endpoint' => '/top/anime', 'params' => ['page' => 1, 'limit' => 25]],
    ];

    $config = $modeMap[$mode] ?? null;
    if (!$config) {
        throw new InvalidArgumentException('Unsupported sync mode: ' . $mode);
    }

    $page = 1;
    $maxPages = 5;

    while ($page <= $maxPages) {
        $current = $config['params'];
        $current['page'] = $page;

        $payload = aniread_fetch($config['endpoint'], $current);
        if (!$payload || !is_array($payload)) {
            break;
        }

        foreach ($payload as $anime) {
            if (!is_array($anime)) {
                continue;
            }
            $status = aniread_upsert_anime($pdo, $anime);
            if ($status === 'added') {
                $results['added']++;
            } elseif ($status === 'updated') {
                $results['updated']++;
            } else {
                $results['skipped']++;
            }
        }

        $page++;
        if (count($payload) < 1) {
            break;
        }
    }

    return $results;
}

$mode = strtolower((string)($argv[1] ?? $_GET['mode'] ?? 'top'));
$mode = preg_replace('/[^a-z]/', '', $mode) ?: 'top';

try {
    $logId = aniread_log($pdo, $mode, 'running', 'Jikan sync started');
    $summary = aniread_sync_mode($pdo, $mode);
    $logMessage = 'Sync complete: ' . $summary['added'] . ' added, ' . $summary['updated'] . ' updated, ' . $summary['skipped'] . ' skipped';
    $pdo->prepare('UPDATE sync_logs SET status = ?, message = ?, records_added = ?, records_updated = ?, finished_at = NOW() WHERE id = ?')->execute(['completed', $logMessage, $summary['added'], $summary['updated'], $logId]);

    if (PHP_SAPI === 'cli') {
        echo $logMessage . PHP_EOL;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'mode' => $mode, 'summary' => $summary, 'message' => $logMessage]);
    }
} catch (Throwable $e) {
    $message = 'Jikan sync failed: ' . $e->getMessage();
    if (isset($pdo)) {
        $pdo->prepare('UPDATE sync_logs SET status = ?, message = ?, finished_at = NOW() WHERE id = ?')->execute(['failed', $message, $logId ?? 0]);
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}
