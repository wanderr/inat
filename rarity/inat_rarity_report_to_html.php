<?php
/**
 * inat_report_from_username.php
 *
 * Usage:
 *   php inat_report_from_username.php <username> [directory] [--sleep=0.1]
 *
 * Expects CSVs:
 *   <dir>/<username>_least_observed_species_top20.csv
 *   <dir>/<username>_oldest_last_seen_by_others_top20.csv
 *
 * Outputs:
 *   <dir>/<username>_inat_report.html
 */

ini_set('memory_limit', '1024M');

const INAT_API = 'https://api.inaturalist.org/v1';
const USER_AGENT = 'inat-csv-to-html-php/1.1';

$username  = $argv[1] ?? null;
$baseDir   = $argv[2] ?? getcwd();
$sleepSeconds = 0.1;

foreach ($argv as $arg) {
  if (preg_match('/^--sleep=([0-9]*\.?[0-9]+)$/', $arg, $m)) {
    $sleepSeconds = (float)$m[1];
  }
}

if (!$username) {
  fwrite(STDERR, "Usage: php inat_report_from_username.php <username> [directory] [--sleep=0.1]\n");
  exit(1);
}

$baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);

$leastCsv  = "{$baseDir}/{$username}_least_observed_species_top20.csv";
$oldestCsv = "{$baseDir}/{$username}_oldest_last_seen_by_others_top20.csv";
$outHtml   = "{$baseDir}/{$username}_inat_report.html";

foreach ([$leastCsv, $oldestCsv] as $f) {
  if (!is_file($f)) {
    fwrite(STDERR, "Missing required CSV: $f\n");
    exit(1);
  }
}

/* ------------------------------------------------------------------ */
/* -------------------------- helpers -------------------------------- */

function qs(array $p): string {
  return http_build_query(array_filter($p, fn($v) => $v !== null));
}

function http_get_json(string $url, float $sleep, int $retries = 10): array {
  if ($sleep > 0) usleep((int)($sleep * 1_000_000));

  $attempt = 0;
  $backoff = 1.0;

  while (true) {
    $attempt++;

    $ctx = stream_context_create([
      'http' => [
        'method' => 'GET',
        'timeout' => 30,
        'header' => "Accept: application/json\r\nUser-Agent: " . USER_AGENT,
      ],
    ]);

    $body = @file_get_contents($url, false, $ctx);

    $code = 0;
    $retryAfter = null;
    if (isset($http_response_header)) {
      foreach ($http_response_header as $h) {
        if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
        if (preg_match('#^Retry-After:\s*(\d+)#i', $h, $m)) $retryAfter = (int)$m[1];
      }
    }

    if ($body === false || $code === 0) {
      if ($attempt <= $retries) { sleep($backoff); $backoff = min(60, $backoff * 2); continue; }
      throw new RuntimeException("HTTP failure: $url");
    }

    if ($code === 429) {
      sleep($retryAfter ?? $backoff);
      $backoff = min(60, $backoff * 2);
      if ($attempt <= $retries) continue;
      throw new RuntimeException("HTTP 429 for $url");
    }

    if ($code < 200 || $code >= 300) {
      throw new RuntimeException("HTTP $code for $url");
    }

    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException("Bad JSON for $url");
    return $json;
  }
}

function read_csv_assoc(string $path): array {
  $fp = fopen($path, 'r');
  $header = fgetcsv($fp, 0, ',', '"', '\\');
  $rows = [];
  while (($line = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
    $rows[] = array_combine($header, $line);
  }
  fclose($fp);
  return $rows;
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function best_photo(?string $u): string {
  if (!$u) return '';
  return preg_replace('#/square\.#', '/medium.', $u) ?? $u;
}

/* ------------------------------------------------------------------ */
/* -------------------------- API bits -------------------------------- */

function get_user_profile(string $username, float $sleep): array {
  $j = http_get_json(INAT_API.'/users/autocomplete?'.qs(['q'=>$username]), $sleep);
  foreach (($j['results'] ?? []) as $u) {
    if (strcasecmp($u['login'] ?? '', $username) === 0) {
      return $u;
    }
  }
  return $j['results'][0] ?? ['login'=>$username];
}

function fetch_user_latest_obs(string $username, int $taxonId, float $sleep): array {
  $j = http_get_json(INAT_API.'/observations?'.qs([
    'user_login'=>$username,
    'taxon_id'=>$taxonId,
    'per_page'=>1,
    'order'=>'desc',
    'order_by'=>'observed_on'
  ]), $sleep);

  $o = $j['results'][0] ?? null;
  if (!$o) return [];
  return [
    'id' => $o['id'],
    'photo' => best_photo($o['photos'][0]['url'] ?? null),
    'date' => $o['observed_on'] ?? ''
  ];
}

function fetch_obs_by_id(string $id, float $sleep): array {
  $j = http_get_json(INAT_API.'/observations/'.$id, $sleep);
  $o = $j['results'][0] ?? null;
  if (!$o) return [];
  return [
    'id' => $o['id'],
    'photo' => best_photo($o['photos'][0]['url'] ?? null),
    'date' => $o['observed_on'] ?? '',
    'user' => $o['user']['login'] ?? ''
  ];
}

/* ------------------------------------------------------------------ */
/* -------------------------- load data ------------------------------- */

$least  = read_csv_assoc($leastCsv);
$oldest = read_csv_assoc($oldestCsv);

$profile = get_user_profile($username, $sleepSeconds);

/* ------------------------------------------------------------------ */
/* -------------------------- render --------------------------------- */

ob_start();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>iNaturalist report for <?=h($username)?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{margin:0;font-family:system-ui;background:#f6f7f9;color:#1f2937}
header{background:#74ac00;color:white;padding:16px}
header .wrap{max-width:1100px;margin:auto;display:flex;gap:12px;align-items:center}
.avatar{width:52px;height:52px;border-radius:50%;border:2px solid rgba(255,255,255,.7)}
.container{max-width:1100px;margin:18px auto;padding:0 16px}
h2{margin:18px 0 8px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.card{background:white;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden}
.thumb{width:100%;aspect-ratio:16/10;object-fit:cover;background:#dde3ea}
.body{padding:12px}
.common{font-weight:800}
.sci{font-style:italic;color:#6b7280;font-size:13px}
.meta{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}
.pill{font-size:12px;background:#f3f4f6;border:1px solid #e5e7eb;padding:4px 8px;border-radius:999px}
a{color:#4d7d00;font-weight:600;text-decoration:none}
a:hover{text-decoration:underline}
.small{font-size:12px;color:#6b7280}
</style>
</head>
<body>

<header>
  <div class="wrap">
    <?php if (!empty($profile['icon_url'])): ?>
      <img class="avatar" src="<?=h(best_photo($profile['icon_url']))?>">
    <?php endif ?>
    <div>
      <div style="font-weight:800">iNaturalist report</div>
      <div class="small">
        for <a style="color:white" href="https://www.inaturalist.org/people/<?=h($profile['login'])?>">@<?=h($profile['login'])?></a>
      </div>
    </div>
  </div>
</header>

<div class="container">

<h2>Least observed species (globally)</h2>
<div class="grid">
<?php foreach ($least as $r):
  $obs = fetch_user_latest_obs($username, (int)$r['taxon_id'], $sleepSeconds); ?>
  <div class="card">
    <?php if (!empty($obs['photo'])): ?>
      <img class="thumb" src="<?=h($obs['photo'])?>">
    <?php endif ?>
    <div class="body">
      <div class="common"><?=h($r['common_name'] ?: $r['scientific_name'])?></div>
      <div class="sci"><?=h($r['scientific_name'])?></div>
      <div class="meta">
        <span class="pill">Global: <?=$r['global_observation_count']?></span>
        <span class="pill">Yours: <?=$r['user_observation_count']?></span>
      </div>
      <?php if (!empty($obs['id'])): ?>
        <a href="https://www.inaturalist.org/observations/<?=$obs['id']?>" target="_blank">View your observation</a>
      <?php endif ?>
    </div>
  </div>
<?php endforeach ?>
</div>

<h2>Oldest “most recent” observations by others</h2>
<div class="grid">
<?php foreach ($oldest as $r):
  if (empty($r['last_other_observation_id'])) continue;
  $obs = fetch_obs_by_id($r['last_other_observation_id'], $sleepSeconds); ?>
  <div class="card">
    <?php if (!empty($obs['photo'])): ?>
      <img class="thumb" src="<?=h($obs['photo'])?>">
    <?php endif ?>
    <div class="body">
      <div class="common"><?=h($r['common_name'] ?: $r['scientific_name'])?></div>
      <div class="sci"><?=h($r['scientific_name'])?></div>
      <div class="meta">
        <span class="pill">Last seen: <?=h($r['last_other_observed_at'])?></span>
      </div>
      <a href="https://www.inaturalist.org/observations/<?=$obs['id']?>" target="_blank">View observation</a>
      <?php if (!empty($obs['user'])): ?>
        <div class="small">by @<?=h($obs['user'])?></div>
      <?php endif ?>
    </div>
  </div>
<?php endforeach ?>
</div>

</div>
</body>
</html>
<?php

file_put_contents($outHtml, ob_get_clean());
fwrite(STDERR, "Wrote HTML report: $outHtml\n");

