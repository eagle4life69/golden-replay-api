<?php
declare(strict_types=1);

// Deploy this directory as api.goldenreplay.app's document root.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

function respond($body, int $status = 200): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(int $status, string $message): void { respond(['error' => $message], $status); }
function param(string $name): string { return trim((string)($_GET[$name] ?? '')); }
function source(): array { return ['key' => 'golden-replay', 'name' => 'Golden Replay', 'site_url' => 'https://goldenreplay.app']; }
function dateValue(array $row): ?string {
    if (empty($row['original_air_year'])) return null;
    return sprintf('%04d-%02d-%02d', (int)$row['original_air_year'], (int)($row['original_air_month'] ?? 0), (int)($row['original_air_day'] ?? 0));
}
function yearKey($year): string { return 'gr-year-' . (!$year ? 'unknown' : (int)$year); }
function duration($seconds): ?string {
    if ($seconds === null) return null;
    $n = (int)$seconds;
    return $n >= 3600 ? sprintf('%d:%02d:%02d', intdiv($n, 3600), intdiv($n % 3600, 60), $n % 60) : sprintf('%d:%02d', intdiv($n, 60), $n % 60);
}
function episode(array $r): array {
    return [
        'post_id' => (int)$r['id'], 'title' => $r['title'], 'original_air_date' => dateValue($r),
        'duration_display' => duration($r['duration_seconds']),
        'published_date' => $r['published_at'] ? date('c', strtotime($r['published_at'])) : '1970-01-01T00:00:00+00:00',
        'audio' => ['provider' => $r['source_type'], 'episode_id' => $r['source_type'] === 'spreaker' && $r['external_episode_id'] !== null ? (string)$r['external_episode_id'] : null, 'stream_url' => $r['audio_url'], 'download_url' => $r['audio_url']],
    ];
}
function detail(array $r): array {
    $out = episode($r);
    $out['web_url'] = $r['web_url'] ?: 'https://goldenreplay.app';
    $out['pretty_url'] = $out['web_url'];
    $out['series'] = ['id' => (int)$r['show_id'], 'key' => $r['show_slug'], 'name' => $r['show_name'], 'slug' => $r['show_slug'], 'source_name' => 'Golden Replay', 'source_slug' => $r['show_slug']];
    $out['description'] = $r['description'];
    $out['duration_seconds'] = $r['duration_seconds'] === null ? null : (int)$r['duration_seconds'];
    $out['file_size_bytes'] = $r['file_size_bytes'] === null ? null : (int)$r['file_size_bytes'];
    $out['file_size_display'] = $r['file_size_bytes'] === null ? null : round((int)$r['file_size_bytes'] / 1048576, 1) . ' MB';
    $out['credits'] = [];
    $out['availability'] = ['status' => 'available', 'available' => true, 'scheduled_for' => null];
    return $out;
}
function rows(PDO $db, string $sql, array $params = []): array {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function one(PDO $db, string $sql, array $params = []): ?array {
    $result = rows($db, $sql, $params);
    return $result[0] ?? null;
}

$path = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
$path = preg_replace('~^v1/?~', '', $path);
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !($path === 'legacy/resolve' && $_SERVER['REQUEST_METHOD'] === 'POST')) fail(405, 'Method not allowed');
try {
    $configPath = dirname(__DIR__) . '/private/config/database.php';
    if (!is_file($configPath)) throw new RuntimeException('Database configuration is unavailable');
    $config = require $configPath;
    if ($config instanceof PDO) $db = $config;
    elseif (isset($pdo) && $pdo instanceof PDO) $db = $pdo;
    elseif (is_array($config)) {
        $db = new PDO($config['dsn'], $config['username'] ?? '', $config['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else throw new RuntimeException('Database configuration is invalid');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($path === 'health' || $path === '') {
        $db->query('SELECT 1')->fetchColumn();
        respond(['status' => 'ok', 'service' => 'golden-replay-catalog', 'version' => 1]);
    }

    // One public, active, free audio source per episode. Never serialize premium sources.
    $playable = "SELECT e.id, e.show_id, e.title, e.description, e.original_air_year, e.original_air_month, e.original_air_day,
        s.name AS show_name, s.slug AS show_slug, es.source_type, es.external_episode_id, es.audio_url, es.web_url, es.duration_seconds, es.file_size_bytes, es.published_at
        FROM episodes e JOIN shows s ON s.id=e.show_id AND s.is_active=1
        JOIN episode_versions v ON v.episode_id=e.id AND v.access_level='free'
        JOIN episode_sources es ON es.episode_version_id=v.id AND es.is_active=1 AND es.audio_url IS NOT NULL AND es.audio_url<>''
        WHERE e.is_active=1 AND es.id=(SELECT es2.id FROM episode_sources es2
            JOIN episode_versions v2 ON v2.id=es2.episode_version_id
            WHERE v2.episode_id=e.id AND v2.access_level='free' AND es2.is_active=1 AND es2.audio_url IS NOT NULL AND es2.audio_url<>''
            ORDER BY v2.is_default DESC, es2.priority DESC, es2.id ASC LIMIT 1)";

    if ($path === 'genres') {
        $list = rows($db, "SELECT g.name,g.slug,COUNT(DISTINCT p.id) AS episode_count FROM genres g
            JOIN show_genres sg ON sg.genre_id=g.id JOIN ($playable) p ON p.show_id=sg.show_id
            WHERE g.is_active=1 GROUP BY g.id,g.name,g.slug ORDER BY g.sort_order,g.name");
        foreach ($list as &$g) { $g['episode_count'] = (int)$g['episode_count']; $g['primary_episode_count'] = $g['episode_count']; }
        respond(['source' => source(), 'genres' => $list]);
    }

    $slug = param('genre');
    if (in_array($path, ['series', 'seasons', 'episodes', 'latest'], true) && $slug === '') fail(400, 'genre is required');
    $genre = $slug === '' ? null : one($db, 'SELECT name,slug FROM genres WHERE slug=? AND is_active=1', [$slug]);
    if ($slug !== '' && !$genre) fail(404, 'Genre not found');

    if ($path === 'series') {
        $list = rows($db, "SELECT p.show_id AS id,p.show_name AS name,p.show_slug AS slug,COUNT(DISTINCT p.id) AS matching_episode_count
            FROM ($playable) p JOIN show_genres sg ON sg.show_id=p.show_id JOIN genres g ON g.id=sg.genre_id
            WHERE g.slug=? GROUP BY p.show_id,p.show_name,p.show_slug ORDER BY p.show_name", [$slug]);
        foreach ($list as &$s) {
            $s['id'] = (int)$s['id']; $s['key'] = $s['slug']; $s['source_slug'] = $s['slug'];
            $s['match_type'] = 'show'; $s['matching_episode_count'] = (int)$s['matching_episode_count']; $s['primary_episode_count'] = $s['matching_episode_count'];
        }
        respond(['source' => source(), 'genre' => $genre, 'series' => $list]);
    }

    if (in_array($path, ['seasons', 'episodes'], true)) {
        $showSlug = param('series');
        if ($showSlug === '') fail(400, 'series is required');
        $show = one($db, 'SELECT s.id,s.name,s.slug FROM shows s JOIN show_genres sg ON sg.show_id=s.id JOIN genres g ON g.id=sg.genre_id WHERE s.slug=? AND s.is_active=1 AND g.slug=?', [$showSlug, $slug]);
        if (!$show) fail(404, 'Series not found');
        $series = ['id' => (int)$show['id'], 'key' => $show['slug'], 'name' => $show['name'], 'slug' => $show['slug'], 'source_slug' => $show['slug'], 'match_type' => 'show'];
        if ($path === 'seasons') {
            $years = rows($db, "SELECT p.original_air_year AS year,COUNT(*) AS episode_count FROM ($playable) p WHERE p.show_id=? GROUP BY p.original_air_year ORDER BY p.original_air_year DESC", [$show['id']]);
            $seasons = [];
            foreach ($years as $y) {
                $year = !$y['year'] ? null : (int)$y['year']; $key = yearKey($year);
                $seasons[] = ['id' => $year ?? 0, 'key' => $key, 'slug' => $key, 'source_name' => $show['name'], 'season' => $year ?? 0, 'year' => $year, 'label' => $year === null ? 'Unknown Year' : (string)$year, 'episode_count' => (int)$y['episode_count']];
            }
            respond(['source' => source(), 'genre' => $genre, 'series' => $series, 'selection_mode' => 'year', 'season_parent' => null, 'seasons' => $seasons]);
        }
        $where = 'p.show_id=?'; $params = [$show['id']];
        $season = param('season');
        if ($season !== '') {
            if ($season === 'gr-year-unknown') $where .= ' AND (p.original_air_year IS NULL OR p.original_air_year=0)';
            elseif (preg_match('/^gr-year-([0-9]{4})$/', $season, $m)) { $where .= ' AND p.original_air_year=?'; $params[] = (int)$m[1]; }
            else fail(400, 'Invalid season');
        }
        $page = max(1, min(100000, (int)(param('page') ?: 1)));
        $perPage = max(1, min(100, (int)(param('per_page') ?: 25)));
        $total = (int)one($db, "SELECT COUNT(*) AS n FROM ($playable) p WHERE $where", $params)['n'];
        $items = rows($db, "SELECT p.* FROM ($playable) p WHERE $where ORDER BY p.original_air_year DESC,p.original_air_month DESC,p.original_air_day DESC,p.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
        respond(['source' => source(), 'genre' => $genre, 'series' => $series, 'selection_mode' => 'year', 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int)ceil($total / $perPage)], 'episodes' => array_map('episode', $items)]);
    }

    if (preg_match('~^episode/([1-9][0-9]*)$~', $path, $m)) {
        $item = one($db, "SELECT p.* FROM ($playable) p WHERE p.id=?", [(int)$m[1]]);
        if (!$item) fail(404, 'Episode not found');
        respond(detail($item));
    }
    if ($path === 'latest') {
        $item = one($db, "SELECT p.* FROM ($playable) p JOIN show_genres sg ON sg.show_id=p.show_id JOIN genres g ON g.id=sg.genre_id WHERE g.slug=? ORDER BY p.published_at DESC,p.id DESC LIMIT 1", [$slug]);
        if (!$item) fail(404, 'No episodes found');
        respond(['source' => source(), 'genre' => $genre, 'episode' => detail($item)]);
    }
    if ($path === 'legacy/resolve') {
        header('Cache-Control: no-store');
        $body = json_decode(file_get_contents('php://input'), true);
        $ids = $body['post_ids'] ?? null;
        if (!is_array($ids) || count($ids) > 200 || array_filter($ids, static fn($id) => !is_int($id) || $id < 1)) fail(400, 'post_ids must be an array of at most 200 positive integers');
        if (!$ids) respond(['matches' => []]);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $matches = rows($db, "SELECT es.external_post_id AS legacy_post_id,p.id AS post_id,p.show_slug AS series_key,p.original_air_year AS year
            FROM ($playable) p JOIN episode_versions v ON v.episode_id=p.id JOIN episode_sources es ON es.episode_version_id=v.id
            JOIN publishers pub ON pub.id=es.publisher_id
            WHERE pub.slug='otrwesterns' AND es.external_post_id IN ($marks) GROUP BY es.external_post_id,p.id,p.show_slug,p.original_air_year", $ids);
        $byLegacy = [];
        foreach ($matches as $row) $byLegacy[(string)$row['legacy_post_id']][] = $row;
        $safe = [];
        foreach ($byLegacy as $legacy => $candidates) if (count($candidates) === 1) {
            $r = $candidates[0]; $safe[] = ['legacy_post_id' => (int)$legacy, 'post_id' => (int)$r['post_id'], 'series_key' => $r['series_key'], 'season_key' => yearKey($r['year'])];
        }
        respond(['matches' => $safe]);
    }
    fail(404, 'Endpoint not found');
} catch (Throwable $error) {
    error_log('Catalog API: ' . $error->getMessage());
    fail(503, 'Catalog temporarily unavailable');
}
