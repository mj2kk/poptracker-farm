<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/production.php';

// These requests enqueue work in an isolated schema. This test never starts a worker.
$db = database();
if (!str_starts_with((string)$db->query('SELECT DATABASE()')->fetchColumn(), 'poptracker_farm_test_')
    || !str_starts_with(prodCatalogName(), 'dataroom_test_') || liveFeedBusyReason($db) !== null) {
    throw new RuntimeException('An idle test Farm and a test catalog are required.');
}
function quoteBridge(array $body): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/dashboard.php'],
        [['pipe','r'], ['pipe','w'], ['file','php://stderr','w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the dashboard bridge');
    }
    fwrite($pipes[0], json_encode($body, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $response = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    if (!in_array($exit, [0,2], true)) {
        throw new RuntimeException('Bridge failed with exit code '.$exit);
    }
    return json_decode($response, true, 32, JSON_THROW_ON_ERROR);
}
function quoteCheck(bool $ok, string $case): void
{
    if (!$ok) {
        throw new RuntimeException($case);
    }
    echo "PASS $case\n";
}
$token = tempnam(sys_get_temp_dir(), 'farm-quote-test-');
if ($token === false) {
    throw new RuntimeException('No temporary test directory');
}
try {
    file_put_contents($token, 'apify_api_'.str_repeat('fixture', 4));
    putenv('FARM_APIFY_TOKEN_FILE='.$token);
    $offer = ['action'=>'production_submit', 'type'=>'IMPORT_FEED', 'source'=>'apify', 'request_id'=>deviceId()];
    $snapshot = quoteBridge(['action'=>'production']);
    $command = array_column($snapshot['commands'], null, 'type')['IMPORT_FEED'];
    quoteCheck($command['available'] && $command['pricing'] === apifyFeedPricing(), 'operator receives the authoritative pricing configuration');
    foreach ([[99,10], [10001,995], ['1000',100], [101,null]] as [$count,$cents]) {
        $reply = quoteBridge($offer + ['requested_count'=>$count,'max_charge_cents'=>$cents]);
        quoteCheck(($reply['status'] ?? 0) === 422, 'invalid count or amount rejected: '.json_encode([$count,$cents]));
    }
    quoteCheck((quoteBridge($offer + ['requested_count'=>2001])['status'] ?? 0) === 422, 'a new quantity needs its confirmed cap');
    quoteCheck((quoteBridge(['action'=>'production_submit','type'=>'IMPORT_FEED','requested_count'=>100,'max_charge_cents'=>10])['status'] ?? 0) === 422, 'an archive request cannot start a paid collection');
    foreach ([199,201] as $wrong) {
        quoteCheck((quoteBridge($offer + ['requested_count'=>2001,'max_charge_cents'=>$wrong])['status'] ?? 0) === 409, 'server refuses a different price of '.$wrong.' cents');
    }
    quoteCheck(!query($db, 'SELECT 1 FROM production_apify_feed_runs WHERE request_id=?', [$offer['request_id']])->fetchColumn(), 'failed confirmation creates no job');
    $confirmed = $offer + ['requested_count'=>2001,'max_charge_cents'=>200];
    quoteCheck(quoteBridge($confirmed) === ['queued'=>1] && quoteBridge($confirmed) === ['queued'=>0], '2001 items accepted once at the confirmed price');
    quoteCheck((quoteBridge(array_replace($confirmed,['requested_count'=>10000,'max_charge_cents'=>995]))['status'] ?? 0) === 409, 'changing the quantity cannot reuse an existing request ID');
    $id = query($db, 'SELECT job_id FROM production_apify_feed_runs WHERE request_id=?', [$offer['request_id']])->fetchColumn();
    quoteBridge(['action'=>'production_cancel','id'=>$id]);
    $legacy = array_replace($offer, ['request_id'=>deviceId()]);
    quoteCheck(quoteBridge($legacy) === ['queued'=>1], 'the original 100-item offer is accepted for older clients');
    $old = query($db, 'SELECT job_id,max_charge_usd FROM production_apify_feed_runs WHERE request_id=?', [$legacy['request_id']])->fetch();
    quoteCheck((float)$old['max_charge_usd'] === 0.10, 'old clients retain their ten-cent cap');
    quoteBridge(['action'=>'production_cancel','id'=>$old['job_id']]);
} finally {
    unlink($token);
}
