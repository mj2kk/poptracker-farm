<?php
declare(strict_types=1);
require dirname(__DIR__).'/production_apify.php';

function verifyApify(bool $pass, string $case): void
{
    if (!$pass) { throw new RuntimeException($case); }
    echo "PASS $case\n";
}

function apifyError(callable $action, string $case): void
{
    try { $action(); } catch (RuntimeException $e) {
        verifyApify($e->getMessage() === $case, $case);
        return;
    }
    throw new RuntimeException('Missing rejection '.$case);
}

$owner = 'user123';
$actor = [
    'id'=>APIFY_FEED_ACTOR, 'pricingInfos'=>[[
        'startedAt'=>'2020-01-01T00:00:00Z', 'pricingModel'=>'PAY_PER_EVENT', 'pricingPerEvent'=>['actorChargeEvents'=>[
            'apify-actor-start'=>['eventPriceUsd'=>0.001],
            'video'=>['eventTieredPricingUsd'=>['FREE'=>['tieredEventPriceUsd'=>0.00099]]],
        ]],
    ]],
];
$build = ['id'=>APIFY_FEED_BUILD_ID, 'actId'=>APIFY_FEED_ACTOR, 'status'=>'SUCCEEDED', 'buildNumber'=>APIFY_FEED_BUILD,
    'imageDigest'=>APIFY_FEED_IMAGE, 'actorDefinition'=>['maxMemoryMbytes'=>1024]];
$run = ['id'=>'run123', 'actId'=>APIFY_FEED_ACTOR, 'userId'=>$owner, 'buildId'=>APIFY_FEED_BUILD_ID,
    'defaultDatasetId'=>'data123', 'defaultKeyValueStoreId'=>'store123', 'status'=>'SUCCEEDED',
    'startedAt'=>'2026-11-20T08:00:00Z', 'finishedAt'=>'2026-11-20T08:02:00Z',
    'chargedEventCounts'=>['video'=>1,'apify-actor-start'=>1], 'usageTotalUsd'=>0.00199,
    'options'=>['memoryMbytes'=>1024, 'timeoutSecs'=>APIFY_FEED_TIMEOUT, 'maxTotalChargeUsd'=>0.10, 'restartOnError'=>false]];
$item = ['videoId'=>'9007199254740993123', 'videoAuthorId'=>'9007199254740993124', 'videoUrl'=>'https://www.tiktok.com/@someone/video/9007199254740993123',
    'country'=>'ZA', 'category'=>'All', 'videoAuthorUsername'=>'someone', 'videoCreatedAt'=>'2026-11-14T07:00:00Z',
    'videoPlayCount'=>135, 'videoCommentCount'=>null, 'videoShareCount'=>0, 'hashtags'=>['discovery'],
    'musicAudioUrl'=>'https://media.example/temporary', 'videoAuthorEmail'=>'creator@example.invalid'];
$meta = ['id'=>'data123','userId'=>$owner,'actRunId'=>'run123','itemCount'=>1];
$input = apifyFeedInput();
$rows = [$item];
$calls = [];
$request = static function (string $path, ?array $body = null) use (&$calls, $owner, &$actor, &$build, &$run, &$meta, &$input, &$rows): array {
    $calls[] = [$path,$body];
    if ($body !== null) {
        verifyApify($path === 'acts/'.APIFY_FEED_ACTOR.'/runs?build='.APIFY_FEED_BUILD.'&memory=1024&timeout='.APIFY_FEED_TIMEOUT.'&maxTotalChargeUsd=0.10&restartOnError=false',
            'POST pins build, time, price and no automatic restarts');
        verifyApify($body === $input && $body['countries'] === ['ZA'] && $body['categories'] === ['All']
            && $body['maxVideos'] === 100 && $body['postedWithinDays'] === 7 && $body['findLeads'] === false
            && $body['onlyNew'] === true && $body['maxConcurrency'] === 1, 'POST uses the fixed production input');
        return ['data'=>$run];
    }
    return match ($path) {
        'users/me'=>['data'=>['id'=>$owner]],
        'acts/'.APIFY_FEED_ACTOR=>['data'=>$actor],
        'actor-builds/'.APIFY_FEED_BUILD_ID=>['data'=>$build],
        'actor-runs/run123'=>['data'=>$run],
        'datasets/data123'=>['data'=>$meta],
        'key-value-stores/store123/records/INPUT'=>$input,
        'datasets/data123/items?format=json&offset=0&limit=101'=>$rows,
        default=>throw new LogicException('Unexpected mocked request '.$path),
    };
};
$poll = static fn () => apifyFeedPoll($owner, 'run123', 'data123', $request);
verifyApify(apifyFeedPreflight($request) === $owner && count($calls) === 3, 'preflight checks the owner, pricing and pinned build using GET only');
$price = &$actor['pricingInfos'][0];
$price['isPPEPlatformUsagePaidByUser'] = true;
apifyError(fn () => apifyFeedPreflight($request), 'apify_price_changed');
unset($price['isPPEPlatformUsagePaidByUser']);
$price['minimalMaxTotalChargeUsd'] = .11;
apifyError(fn () => apifyFeedPreflight($request), 'apify_price_changed');
unset($price['minimalMaxTotalChargeUsd']);
$price['pricingPerEvent']['actorChargeEvents']['video']['eventTieredPricingUsd']['FREE']['tieredEventPriceUsd'] = .002;
apifyError(fn () => apifyFeedPreflight($request), 'apify_price_changed');
$price['pricingPerEvent']['actorChargeEvents']['video']['eventTieredPricingUsd']['FREE']['tieredEventPriceUsd'] = .00099;
$build['imageDigest'] = 'changed';
apifyError(fn () => apifyFeedPreflight($request), 'apify_build_mismatch');
$build['imageDigest'] = APIFY_FEED_IMAGE;
verifyApify(apifyFeedStart($owner, $request) === ['run_id'=>'run123','dataset_id'=>'data123'], 'paid response binds an exact run and dataset');
$run['status'] = 'RUNNING';
$n = count($calls);
verifyApify($poll() === null && count($calls) === $n + 1, 'running job makes one read');
foreach (['FAILED','ABORTED','TIMED-OUT'] as $status) {
    $run['status'] = $status;
    apifyError($poll, 'apify_run_'.strtolower(str_replace('-', '_', $status)));
}
$run['status'] = 'SUCCEEDED';
$first = $poll();
verifyApify($first['rows'][0]['videoId'] === $item['videoId'] && !isset($first['rows'][0]['videoAuthorEmail'], $first['rows'][0]['musicAudioUrl'])
    && array_key_exists('videoCommentCount', $first['rows'][0]) && $first['rows'][0]['videoCommentCount'] === null
    && $first['rows'][0]['videoShareCount'] === 0 && !isset($first['rows'][0]['videoSaveCount']), 'projection keeps exact identifiers, zero, null and missing fields');
verifyApify($first['sha'] === $poll()['sha'] && $first['cost'] === '0.00199000'
    && $first['started'] === '2026-11-20 08:00:00.000000' && $first['finished'] === '2026-11-20 08:02:00.000000', 'success yields repeatable evidence and UTC dates');
$rows[0]['musicAudioUrl'] = 'different';
verifyApify($first['sha'] !== $poll()['sha'], 'evidence binds every source row before minimization');
$run['usageTotalUsd'] = .11;
apifyError($poll, 'apify_run_cost_exceeded');
$run['usageTotalUsd'] = .00199;
$run['chargedEventCounts']['lead'] = 1;
apifyError($poll, 'apify_unexpected_charge');
unset($run['chargedEventCounts']['lead']);
$run['userId'] = 'foreign';
apifyError($poll, 'apify_run_identity_mismatch');
$run['userId'] = $owner;
$run['options']['maxTotalChargeUsd'] = 2;
apifyError($poll, 'apify_run_limits_mismatch');
$run['options']['maxTotalChargeUsd'] = .10;
$meta['actRunId'] = 'foreign';
apifyError($poll, 'apify_dataset_binding_mismatch');
$meta['actRunId'] = 'run123';
$input['countries'] = ['US'];
apifyError($poll, 'apify_run_input_mismatch');
$input = apifyFeedInput();
$rows[0]['country'] = 'XX';
apifyError($poll, 'apify_feed_market_mismatch');
$rows[0] = $item;
$rows[0]['videoCreatedAt'] = '2026-11-13T07:59:59Z';
apifyError($poll, 'apify_publication_outside_window');
$rows[0] = $item;
$rows[0]['videoUrl'] .= '?param=value';
apifyError($poll, 'apify_video_url_mismatch');
$rows[0] = $item;
$meta['itemCount'] = 0;
verifyApify($poll() === null, 'inconsistent provider item count remains pending');
$meta['itemCount'] = 1;
$run['chargedEventCounts']['video'] = 0;
verifyApify($poll() === null, 'incomplete billed count remains pending');
$rows = array_fill(0, 101, $item);
apifyError($poll, 'apify_dataset_size_mismatch');
$rows = [];
$meta['itemCount'] = 0;
$run['usageTotalUsd'] = .001;
$n = count($calls);
verifyApify($poll()['rows'] === [] && count($calls) === $n + 4, 'empty dataset still reads the first page');
verifyApify(count(array_filter($calls, static fn ($v) => $v[1] !== null)) === 1, 'repeated polling never issues a second POST');
apifyError(fn () => apifyFeedPoll($owner, 'run123', '../other', $request), 'apify_invalid_provider_id');

foreach ([100=>10, 101=>11, 1999=>199, 2000=>199, 2001=>200, 2500=>249, 9999=>995, 10000=>995] as $quantity=>$cents) {
    verifyApify(apifyFeedQuote($quantity)['max_charge_cents'] === $cents, 'sum of bounded provider caps for '.$quantity.' publications');
}
foreach ([99, 10001] as $outside) {
    try { apifyFeedQuote($outside); throw new LogicException('Invalid quantity accepted'); }
    catch (InvalidArgumentException $error) { verifyApify($error->getMessage() === 'apify_invalid_quantity', 'quantity outside range '.$outside); }
}

$run['options']['maxTotalChargeUsd'] = 1.99;
$run['options']['timeoutSecs'] = 3600;
$run['chargedEventCounts']['video'] = 2000;
$run['usageTotalUsd'] = 1.981;
$meta['itemCount'] = 2000;
$input = apifyFeedInput(2000);
$pages = [];
$full = array_fill(0,2000,$item);
$largeRequest = static function ($path, $body = null) use ($request,$input,$run,$full,&$pages) {
    if ($body !== null) {
        verifyApify($body === $input && str_contains($path, 'maxTotalChargeUsd=1.99'), 'provider gets selected per-run quantity and budget');
        return ['data'=>$run];
    }
    if (preg_match('/offset=(\d+)&limit=(\d+)$/', $path, $m)) {
        $pages[] = (int)$m[1];
        return array_slice($full, (int)$m[1], (int)$m[2]);
    }
    return $request($path);
};
apifyFeedStart($owner,$largeRequest,2000);
$large = apifyFeedPoll($owner,'run123','data123',$largeRequest,2000);
verifyApify(count($large['rows']) === 2000 && $pages === range(0,2000,250), '2000-row part fetches bounded pages plus an over-limit probe');
verifyApify($large['sha'] === hash('sha256', json_encode($full, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
    'paged reads retain the historical SHA of the complete source dataset');
