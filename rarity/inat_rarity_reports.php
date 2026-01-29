<?php
/**
 * iNaturalist user species reports (optimized for thousands of taxa)
 *
 * Outputs:
 * 1) <user>_least_observed_species_top20.csv
 * 2) <user>_oldest_last_seen_by_others_top20.csv
 *
 * Key optimizations:
 * - Batch fetch taxon observations_count via /taxa/<comma-separated-ids> (fallback to /taxa?id=... if needed)
 * - Cache per-taxon results to disk for resume
 * - Bounded scan to find "most recent other observer" (won't search forever)
 *
 * Usage:
 *   php inat_user_reports.php <username> [output_dir] [--sleep=0.25] [--max-pages=8] [--batch=200]
 *
 * Examples:
 *   php inat_user_reports.php jayparoline
 *   php inat_user_reports.php jayparoline ./out --sleep=0.5 --max-pages=6 --batch=200
 */

ini_set('memory_limit', '2048M');

const INAT_API = 'https://api.inaturalist.org/v1';
const USER_AGENT = 'inat-user-reports-php/2.0 (contact: you@example.invalid)';

$username   = $argv[1] ?? null;
$outputDir  = $argv[2] ?? getcwd();

$sleepSeconds = 0.25;  // increase if you hit 429
$maxPagesOtherScan = 8; // per taxon: pages * per_page (below) = max observations scanned
$taxaBatchSize = 200;  // batch IDs per taxa call (tune down if you get 414 URI too long)

foreach ($argv as $arg) {
  if (preg_match('/^--sleep=([0-9]*\.?[0-9]+)$/', $arg, $m)) $sleepSeconds = (float)$m[1];
  if (preg_match('/^--max-pages=(\d+)$/', $arg, $m)) $maxPagesOtherScan = (int)$m[1];
  if (preg_match('/^--batch=(\d+)$/', $arg, $m)) $taxaBatchSize = (int)$m[1];
}

if (!$username) {
  fwrite(STDERR, "Usage: php inat_user_reports.php <username> [output_dir] [--sleep=0.25] [--max-pages=8] [--batch=200]\n");
  exit(1);
}

if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true)) {
  fwrite(STDERR, "Failed to create output dir: $outputDir\n");
  exit(1);
}

function qs(array $params): string {
  $clean = [];
  foreach ($params as $k => $v) if ($v !== null) $clean[$k] = $v;
  return http_build_query($clean);
}

function http_get_json(string $url, float $sleepSeconds = 0.0, int $retries = 7): array {
  if ($sleepSeconds > 0) usleep((int)round($sleepSeconds * 1_000_000));

  $attempt = 0;
  $backoff = 0.5;

  while (true) {
    $attempt++;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'User-Agent: ' . USER_AGENT,
      ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
      if ($attempt <= $retries) { usleep((int)round($backoff * 1_000_000)); $backoff *= 2; continue; }
      throw new RuntimeException("curl error: $err");
    }

    // handle rate limits/transients
    if (in_array($code, [429, 500, 502, 503, 504], true)) {
      if ($attempt <= $retries) { usleep((int)round($backoff * 1_000_000)); $backoff *= 2; continue; }
      throw new RuntimeException("HTTP $code for $url (after retries)");
    }

    if ($code < 200 || $code >= 300) {
      throw new RuntimeException("HTTP $code for $url. Body: " . substr($body, 0, 500));
    }

    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException("Bad JSON for $url: " . substr($body, 0, 200));
    return $json;
  }
}

function parse_obs_datetime(array $obs): ?DateTimeImmutable {
  $time = $obs['time_observed_at'] ?? null;
  if ($time) { try { return new DateTimeImmutable($time); } catch (Throwable $e) {} }
  $date = $obs['observed_on'] ?? null;
  if ($date) { try { return new DateTimeImmutable($date . 'T00:00:00Z'); } catch (Throwable $e) {} }
  return null;
}

function csv_write(string $path, array $header, array $rows): void {
  $fp = fopen($path, 'w');
  if (!$fp) throw new RuntimeException("Failed to open for writing: $path");
  fputcsv($fp, $header);
  foreach ($rows as $row) {
    $out = [];
    foreach ($header as $col) $out[] = $row[$col] ?? '';
    fputcsv($fp, $out);
  }
  fclose($fp);
}

function fetch_user_species_counts(string $username, float $sleepSeconds): array {
  $all = [];
  $page = 1;
  $perPage = 200;

  while (true) {
    $url = INAT_API . '/observations/species_counts?' . qs([
      'user_login' => $username,
      'page' => $page,
      'per_page' => $perPage,
    ]);
    $json = http_get_json($url, $sleepSeconds);
    $results = $json['results'] ?? [];
    if (!$results) break;

    foreach ($results as $r) {
      $taxon = $r['taxon'] ?? null;
      if (!$taxon || empty($taxon['id'])) continue;
      $all[] = [
        'taxon_id' => (int)$taxon['id'],
        'taxon_rank' => (string)($taxon['rank'] ?? ''),
        'scientific_name' => (string)($taxon['name'] ?? ''),
        'common_name' => (string)($taxon['preferred_common_name'] ?? ''),
        'user_observation_count' => (int)($r['count'] ?? 0),
      ];
    }

    $page++;
  }
  return $all;
}

function fetch_taxa_batch_observations_count(array $taxonIds, float $sleepSeconds): array {
  // Returns map: taxon_id => observations_count
  $ids = implode(',', $taxonIds);

  // Try /taxa/<ids>
  $url1 = INAT_API . '/taxa/' . $ids . '?' . qs(['per_page' => count($taxonIds)]);
  try {
    $json = http_get_json($url1, $sleepSeconds);
    $out = [];
    foreach (($json['results'] ?? []) as $t) {
      if (!empty($t['id'])) $out[(int)$t['id']] = (int)($t['observations_count'] ?? 0);
    }
    if ($out) return $out;
  } catch (Throwable $e) {
    // fall through
  }

  // Fallback /taxa?id=<ids>
  $url2 = INAT_API . '/taxa?' . qs(['id' => $ids, 'per_page' => count($taxonIds)]);
  $json = http_get_json($url2, $sleepSeconds);
  $out = [];
  foreach (($json['results'] ?? []) as $t) {
    if (!empty($t['id'])) $out[(int)$t['id']] = (int)($t['observations_count'] ?? 0);
  }
  return $out;
}

function fetch_most_recent_other_observation(int $taxonId, string $username, float $sleepSeconds, int $maxPages): array {
  $perPage = 10;

  for ($page = 1; $page <= $maxPages; $page++) {
    $url = INAT_API . '/observations?' . qs([
      'taxon_id' => $taxonId,
      'order' => 'desc',
      'order_by' => 'observed_on',
      'page' => $page,
      'per_page' => $perPage,
    ]);
    $json = http_get_json($url, $sleepSeconds);
    $results = $json['results'] ?? [];
    if (!$results) break;

    foreach ($results as $obs) {
      $login = (string)(($obs['user']['login'] ?? ''));

      // "besides theirs"
      if ($login && strcasecmp($login, $username) !== 0) {
        $dt = parse_obs_datetime($obs);
        return [
          'last_other_observed_at' => $dt ? $dt->format(DateTimeInterface::ATOM) : '',
          'last_other_observation_id' => (string)($obs['id'] ?? ''),
          'last_other_observer_login' => $login,
        ];
      }
    }
  }

  return [
    'last_other_observed_at' => '',
    'last_other_observation_id' => '',
    'last_other_observer_login' => '',
  ];
}

// ---------------- main ----------------

fwrite(STDERR, "Fetching species_counts for $username...\n");
$species = fetch_user_species_counts($username, $sleepSeconds);
fwrite(STDERR, "Found " . count($species) . " taxa\n");

$cachePath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "{$username}_inat_cache.json";
$cache = [];
if (is_file($cachePath)) {
  $raw = file_get_contents($cachePath);
  $cache = json_decode($raw ?: '[]', true) ?: [];
  fwrite(STDERR, "Loaded cache with " . count($cache) . " taxa\n");
}

$rows = [];
// Build taxon id list
$taxonIds = array_values(array_unique(array_map(fn($s) => (int)$s['taxon_id'], $species)));

// 1) Batch fetch global observation counts
fwrite(STDERR, "Fetching global observation counts in batches of $taxaBatchSize...\n");
$globalCounts = [];

for ($i = 0; $i < count($taxonIds); $i += $taxaBatchSize) {
  $chunk = array_slice($taxonIds, $i, $taxaBatchSize);
  $counts = fetch_taxa_batch_observations_count($chunk, $sleepSeconds);
  $globalCounts += $counts;
  fwrite(STDERR, "  batch " . (int)($i / $taxaBatchSize + 1) . " => " . count($counts) . " counts\n");
}

// 2) For each species: fill record + (cached) most recent other observation
$idx = 0;
foreach ($species as $s) {
  $idx++;
  $taxonId = (int)$s['taxon_id'];

  if (!isset($cache[$taxonId])) {
    fwrite(STDERR, "[$idx/" . count($species) . "] scanning other-observer last seen for $taxonId {$s['scientific_name']}...\n");
    $other = fetch_most_recent_other_observation($taxonId, $username, $sleepSeconds, $maxPagesOtherScan);
    $cache[$taxonId] = $other;
    // persist frequently for safety
    file_put_contents($cachePath, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  $rows[] = [
    'username' => $username,
    'taxon_id' => $taxonId,
    'taxon_rank' => $s['taxon_rank'],
    'scientific_name' => $s['scientific_name'],
    'common_name' => $s['common_name'],
    'user_observation_count' => $s['user_observation_count'],
    'global_observation_count' => (int)($globalCounts[$taxonId] ?? 0),
    'last_other_observed_at' => $cache[$taxonId]['last_other_observed_at'] ?? '',
    'last_other_observation_id' => $cache[$taxonId]['last_other_observation_id'] ?? '',
    'last_other_observer_login' => $cache[$taxonId]['last_other_observer_login'] ?? '',
  ];
}

$header = [
  'username',
  'taxon_id',
  'taxon_rank',
  'scientific_name',
  'common_name',
  'user_observation_count',
  'global_observation_count',
  'last_other_observed_at',
  'last_other_observation_id',
  'last_other_observer_login',
];

// 20 least observed species (globally)
$least = $rows;
usort($least, function($a, $b) {
  $c = ($a['global_observation_count'] <=> $b['global_observation_count']);
  if ($c !== 0) return $c;
  return ($a['scientific_name'] <=> $b['scientific_name']);
});
$least = array_slice($least, 0, 20);

// 20 oldest "most recent other obs" (oldest timestamp = least recently seen by others)
$oldest = array_values(array_filter($rows, fn($r) => !empty($r['last_other_observed_at'])));
usort($oldest, fn($a, $b) => strtotime($a['last_other_observed_at']) <=> strtotime($b['last_other_observed_at']));
$oldest = array_slice($oldest, 0, 20);

$leastPath  = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "{$username}_least_observed_species_top20.csv";
$oldestPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "{$username}_oldest_last_seen_by_others_top20.csv";

csv_write($leastPath, $header, $least);
csv_write($oldestPath, $header, $oldest);

fwrite(STDERR, "Done.\n- $leastPath\n- $oldestPath\nCache:\n- $cachePath\n");

