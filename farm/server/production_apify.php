<?php
declare(strict_types=1);
require_once __DIR__.'/production_feed.php';

const APIFY_FEED_ACTOR = 'xODWo9p7comzFmcgu';
const APIFY_FEED_BUILD = '0.1.14';
const APIFY_FEED_BUILD_ID = 'pe0ogYmBmMR3Q10Qw';
const APIFY_FEED_IMAGE = 'dad7c84d27f9102407eae1bdde774824a1fb254bfaecd9b34760b8b629620737';
const APIFY_FEED_MIN = 100;
const APIFY_FEED_MAX = 10000;
const APIFY_FEED_BATCH = 2000;
const APIFY_FEED_ITEM_MICROS = 990;
const APIFY_FEED_START_MICROS = 1000;
const APIFY_FEED_TIMEOUT = 600;

class ApifyFeedTransient extends RuntimeException {}

function apifyFeedPricing(): array
{
    return ['min_quantity'=>APIFY_FEED_MIN, 'max_quantity'=>APIFY_FEED_MAX, 'max_per_run'=>APIFY_FEED_BATCH,
        'video_usd_micros'=>APIFY_FEED_ITEM_MICROS, 'start_usd_micros'=>APIFY_FEED_START_MICROS];
}

function apifyBatchCapCents(int $size): int
{
    feedRequire($size >= 1 && $size <= APIFY_FEED_BATCH, 'apify_invalid_batch_size');
    return intdiv(APIFY_FEED_ITEM_MICROS * $size + APIFY_FEED_START_MICROS + 9999, 10000);
}

function apifyUsd(int $cents): string
{
    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}

function apifyFeedQuote(int $quantity): array
{
    if ($quantity < APIFY_FEED_MIN || $quantity > APIFY_FEED_MAX) {
        throw new InvalidArgumentException('apify_invalid_quantity');
    }
    $capCents = 0;
    $runs = 0;
    for ($remaining = $quantity; $remaining > 0; $remaining -= APIFY_FEED_BATCH) {
        $capCents += apifyBatchCapCents(min($remaining, APIFY_FEED_BATCH));
        $runs++;
    }
    return ['quantity'=>$quantity, 'runs'=>$runs, 'max_charge_cents'=>$capCents,
        'estimated_usd'=>number_format(($quantity * APIFY_FEED_ITEM_MICROS + $runs * APIFY_FEED_START_MICROS) / 1000000, 5, '.', '')];
}

function apifyBatchTimeout(int $size): int
{
    // Existing 100-row runs must continue with their originally bound provider options.
    return $size <= APIFY_FEED_MIN ? APIFY_FEED_TIMEOUT : 3600;
}

function apifyFeedInput(int $size = APIFY_FEED_MIN): array
{
    return [
        'countries'=>['ZA'], 'categories'=>['All'], 'maxVideos'=>$size, 'postedWithinDays'=>7,
        'minPlays'=>0, 'onlyNew'=>true, 'monitorName'=>'poptracker-farm-za-explore-all-7d',
        'findLeads'=>false, 'maxLeads'=>0, 'maxConcurrency'=>1,
        'proxyConfiguration'=>['useApifyProxy'=>true], 'debugLog'=>false,
    ];
}

function apifyFeedToken(): ?string
{
    $path = getenv('FARM_APIFY_TOKEN_FILE') ?: '/etc/poptracker-farm/apify-token';
    if (!is_file($path) || !is_readable($path)) { return null; }
    $bytes = @file_get_contents($path, false, null, 0, 256);
    if (!is_string($bytes) || strlen($bytes) === 256) { return null; }
    $token = trim($bytes);
    return preg_match('/\Aapify_api_[A-Za-z0-9_=-]{20,200}\z/D', $token) ? $token : null;
}

function apifyFeedReady(): bool
{
    return function_exists('curl_init') && apifyFeedToken() !== null;
}

function apifyFeedRequest(string $path, ?array $input = null): array
{
    $token = apifyFeedToken();
    feedRequire($token !== null && function_exists('curl_init'), 'apify_token_unavailable');
    $curl = curl_init('https://api.apify.com/v2/'.$path);
    $body = '';
    $tooLarge = false;
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER=>['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer '.$token],
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROXY=>'', CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>14,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($input !== null) {
        curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($input, JSON_THROW_ON_ERROR)]);
    }
    $ok = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    feedRequire(!$tooLarge, 'apify_response_too_large');
    if ($ok === false) { throw new ApifyFeedTransient('apify_network_error'); }
    if (in_array($status, [408,429], true)) { throw new ApifyFeedTransient('apify_http_'.$status); }
    if ($status >= 500 && $status < 600) { throw new ApifyFeedTransient('apify_http_5xx'); }
    feedRequire($status >= 200 && $status < 300, 'apify_http_'.$status);
    try {
        $result = json_decode($body, true, 64, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('apify_invalid_json');
    }
    feedRequire(is_array($result), 'apify_invalid_response');
    return $result;
}

function apifyFeedId(mixed $value): string
{
    feedRequire(is_string($value) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $value) === 1, 'apify_invalid_provider_id');
    return $value;
}

function apifyFeedPriceWithin(array $event, float $max): bool
{
    $prices = [];
    if (array_key_exists('eventPriceUsd', $event)) { $prices[] = $event['eventPriceUsd']; }
    foreach ($event['eventTieredPricingUsd'] ?? [] as $tier) { $prices[] = $tier['tieredEventPriceUsd'] ?? null; }
    foreach ($prices as $price) {
        if (!is_numeric($price) || (float)$price < 0 || (float)$price > $max) { return false; }
    }
    return $prices !== [];
}

function apifyFeedPreflight(?callable $request = null, int $size = APIFY_FEED_MIN): string
{
    $request ??= 'apifyFeedRequest';
    $owner = apifyFeedId($request('users/me')['data']['id'] ?? null);
    $actor = $request('acts/'.APIFY_FEED_ACTOR)['data'] ?? [];
    feedRequire(($actor['id'] ?? '') === APIFY_FEED_ACTOR, 'apify_actor_mismatch');
    $price = [];
    $effective = -1;
    foreach ($actor['pricingInfos'] ?? [] as $entry) {
        $date = strtotime($entry['startedAt'] ?? '');
        if ($date !== false && $date <= time() && $date >= $effective) {
            $effective = $date;
            $price = $entry;
        }
    }
    $events = $price['pricingPerEvent']['actorChargeEvents'] ?? [];
    $minimumCap = $price['minimalMaxTotalChargeUsd'] ?? 0;
    feedRequire(($price['pricingModel'] ?? '') === 'PAY_PER_EVENT' && ($price['isPPEPlatformUsagePaidByUser'] ?? false) === false
        && is_numeric($minimumCap) && (float)$minimumCap >= 0 && (float)$minimumCap <= apifyBatchCapCents($size) / 100
        && is_array($events) && !array_diff(array_keys($events), ['apify-actor-start','video','lead','lead-email'])
        && apifyFeedPriceWithin($events['apify-actor-start'] ?? [], APIFY_FEED_START_MICROS / 1000000)
        && apifyFeedPriceWithin($events['video'] ?? [], APIFY_FEED_ITEM_MICROS / 1000000), 'apify_price_changed');
    $build = $request('actor-builds/'.APIFY_FEED_BUILD_ID)['data'] ?? [];
    feedRequire(($build['id'] ?? '') === APIFY_FEED_BUILD_ID && ($build['actId'] ?? '') === APIFY_FEED_ACTOR
        && ($build['status'] ?? '') === 'SUCCEEDED' && ($build['buildNumber'] ?? '') === APIFY_FEED_BUILD
        && ($build['imageDigest'] ?? '') === APIFY_FEED_IMAGE
        && ($build['actorDefinition']['maxMemoryMbytes'] ?? null) === 1024, 'apify_build_mismatch');
    return $owner;
}

function apifyFeedCheckRun(array $run, string $owner, int $size): void
{
    feedRequire(($run['userId'] ?? '') === $owner && ($run['actId'] ?? '') === APIFY_FEED_ACTOR
        && ($run['buildId'] ?? '') === APIFY_FEED_BUILD_ID, 'apify_run_identity_mismatch');
    $opt = $run['options'] ?? [];
    $cap = $opt['maxTotalChargeUsd'] ?? null;
    feedRequire(is_numeric($cap) && (float)$cap === (float)(apifyBatchCapCents($size) / 100)
        && ($opt['memoryMbytes'] ?? null) === 1024 && ($opt['timeoutSecs'] ?? null) === apifyBatchTimeout($size)
        && ($opt['restartOnError'] ?? null) === false, 'apify_run_limits_mismatch');
}

function apifyFeedStart(string $owner, ?callable $request = null, int $size = APIFY_FEED_MIN): array
{
    $request ??= 'apifyFeedRequest';
    apifyFeedId($owner);
    $query = http_build_query(['build'=>APIFY_FEED_BUILD, 'memory'=>1024, 'timeout'=>apifyBatchTimeout($size),
        'maxTotalChargeUsd'=>apifyUsd(apifyBatchCapCents($size)), 'restartOnError'=>'false']);
    // The scheduler reserves this single paid POST before making it. Polling uses only GET.
    $run = $request('acts/'.APIFY_FEED_ACTOR.'/runs?'.$query, apifyFeedInput($size))['data'] ?? [];
    apifyFeedCheckRun($run, $owner, $size);
    return ['run_id'=>apifyFeedId($run['id'] ?? null), 'dataset_id'=>apifyFeedId($run['defaultDatasetId'] ?? null)];
}

function apifyFeedProjection(array $row, string $earliest, string $finished): array
{
    feedRequire(($row['country'] ?? '') === 'ZA' && ($row['category'] ?? '') === 'All', 'apify_feed_market_mismatch');
    $published = feedDate($row['videoCreatedAt'] ?? null);
    feedRequire($published !== null && $published >= $earliest && $published <= $finished, 'apify_publication_outside_window');
    $keys = ['videoId','videoAuthorId','videoAuthorUsername','country','category','videoCreatedAt',
        'videoUrl','videoDescription','hashtags','musicId','musicTitle','musicAuthor','isPhotoPost','videoPlayCount',
        'videoLikeCount','videoCommentCount','videoShareCount','videoSaveCount','scrapedAt','language','durationSeconds'];
    $clean = array_intersect_key($row, array_flip($keys));
    $counters = ['videoPlayCount','videoLikeCount','videoCommentCount','videoShareCount','videoSaveCount','durationSeconds'];
    foreach ($counters as $key) {
        if (isset($clean[$key])) {
            feedRequire((is_int($clean[$key]) || is_string($clean[$key])) &&
                preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D', (string)$clean[$key]) === 1, 'apify_invalid_counter');
        }
    }
    foreach (['videoAuthorUsername','videoUrl','videoDescription','musicTitle','musicAuthor','scrapedAt','language'] as $key) {
        feedRequire(!isset($clean[$key]) || is_string($clean[$key]), 'apify_invalid_metadata');
    }
    foreach (['videoId','videoAuthorId','musicId'] as $key) {
        feedRequire(!isset($clean[$key]) || is_int($clean[$key]) || is_string($clean[$key]), 'apify_invalid_identity');
    }
    if (isset($clean['hashtags'])) {
        feedRequire(is_array($clean['hashtags']) && array_is_list($clean['hashtags']), 'apify_invalid_hashtags');
        foreach ($clean['hashtags'] as $tag) { feedRequire(is_string($tag), 'apify_invalid_hashtags'); }
    }
    feedRequire(!isset($clean['isPhotoPost']) || is_bool($clean['isPhotoPost']), 'apify_invalid_metadata');
    if (isset($clean['videoUrl'])) {
        feedRequire(preg_match('~\Ahttps://(?:www\.)?tiktok\.com/@[A-Za-z0-9._-]+/(?:video|photo)/([1-9][0-9]{0,31})/?\z~D', $clean['videoUrl'], $match) === 1
            && (feedId($clean['videoId'] ?? null) === null || $match[1] === (string)$clean['videoId']), 'apify_video_url_mismatch');
    }
    return $clean;
}

/** A terminal snapshot is accepted only after the scheduler compares it with the next tick's snapshot. */
function apifyFeedPoll(string $owner, string $runId, string $datasetId, ?callable $request = null, int $size = APIFY_FEED_MIN): ?array
{
    $request ??= 'apifyFeedRequest';
    apifyFeedId($owner);
    apifyFeedId($datasetId);
    $run = $request('actor-runs/'.apifyFeedId($runId))['data'] ?? [];
    apifyFeedCheckRun($run, $owner, $size);
    feedRequire(($run['id'] ?? '') === $runId && ($run['defaultDatasetId'] ?? '') === $datasetId, 'apify_dataset_binding_mismatch');
    $status = $run['status'] ?? '';
    if (in_array($status, ['READY','RUNNING','TIMING-OUT','ABORTING'], true)) { return null; }
    if (in_array($status, ['FAILED','ABORTED','TIMED-OUT'], true)) {
        throw new RuntimeException('apify_run_'.strtolower(str_replace('-', '_', $status)));
    }
    feedRequire($status === 'SUCCEEDED', 'apify_unknown_run_status');
    $started = feedDate($run['startedAt'] ?? null);
    $finished = feedDate($run['finishedAt'] ?? null);
    feedRequire($started !== null && $finished !== null && $finished >= $started, 'apify_invalid_run_time');
    $store = apifyFeedId($run['defaultKeyValueStoreId'] ?? null);
    $input = $request('key-value-stores/'.$store.'/records/INPUT');
    $expected = apifyFeedInput($size);
    ksort($input);
    ksort($expected);
    feedRequire($input === $expected, 'apify_run_input_mismatch');

    $meta = $request('datasets/'.$datasetId)['data'] ?? [];
    feedRequire(($meta['id'] ?? '') === $datasetId
        && (!isset($meta['userId']) || $meta['userId'] === $owner)
        && (!isset($meta['actRunId']) || $meta['actRunId'] === $runId), 'apify_dataset_binding_mismatch');
    // Even an empty dataset must return an empty first page. At most nine bounded pages
    // per snapshot keep the 14s HTTP timeout inside the four-minute worker deadline.
    $earliest = (new DateTimeImmutable($started, new DateTimeZone('UTC')))->modify('-7 days')->format('Y-m-d H:i:s.u');
    $items = [];
    $hash = hash_init('sha256');
    hash_update($hash, '[');
    do {
        $offset = count($items);
        $limit = min(250, $size + 1 - $offset);
        $page = $request('datasets/'.$datasetId.'/items?format=json&offset='.$offset.'&limit='.$limit);
        feedRequire(array_is_list($page) && count($page) <= $limit && $offset + count($page) <= $size, 'apify_dataset_size_mismatch');
        foreach ($page as $row) {
            feedRequire(is_array($row) && !array_is_list($row), 'apify_invalid_dataset_item');
            hash_update($hash, ($items !== [] ? ',' : '').json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $items[] = apifyFeedProjection($row, $earliest, $finished);
        }
    } while (count($page) === $limit);
    hash_update($hash, ']');
    $charged = $run['chargedEventCounts'] ?? null;
    $cost = $run['usageTotalUsd'] ?? null;
    if (!is_array($charged) || $cost === null || !isset($meta['itemCount'])) { return null; }
    feedRequire(is_numeric($cost) && (float)$cost >= 0 && (float)$cost <= apifyBatchCapCents($size) / 100 + 1e-12,
        'apify_run_cost_exceeded');
    foreach ($charged as $event => $count) {
        feedRequire(is_int($count) && $count >= 0 && ($count === 0 || in_array($event, ['video','apify-actor-start'], true)),
            'apify_unexpected_charge');
    }
    feedRequire(($charged['apify-actor-start'] ?? 0) <= 1, 'apify_unexpected_charge');
    if ($meta['itemCount'] !== count($items) || ($charged['video'] ?? 0) !== count($items)) { return null; }
    return ['sha'=>hash_final($hash),
        'received'=>(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        'rows'=>$items, 'cost'=>number_format((float)$cost, 8, '.', ''), 'started'=>$started, 'finished'=>$finished];
}
