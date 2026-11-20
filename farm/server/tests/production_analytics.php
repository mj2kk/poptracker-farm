<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/production_analytics.php';
function checkTrend(bool $valid, string $name): void
{
    if (!$valid) {
        throw new RuntimeException($name);
    }
    echo "PASS $name\n";
}

$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE poptracker_videos (id TEXT PRIMARY KEY,author_id TEXT,caption TEXT,music_id TEXT,published_at TEXT,first_seen_at TEXT)');
$db->exec('CREATE TABLE poptracker_scans (id INTEGER PRIMARY KEY,source TEXT,payload_sha256 TEXT)');
$db->exec('CREATE TABLE poptracker_observations (id INTEGER PRIMARY KEY, scan_id INTEGER, video_id TEXT, observed_at TEXT,view_count TEXT,like_count TEXT,share_count TEXT,save_count TEXT,comment_count TEXT)');
$db->exec("INSERT INTO poptracker_scans VALUES (1,'qualified/source','sha1'),(2,'other/source','sha2')");
$video = $db->prepare('INSERT INTO poptracker_videos VALUES (?,?,?,?,?,?)');
foreach (['9999999999999999999','12','13','14','15','16','17','18'] as $id) {
    $when = $id === '9999999999999999999' ? '2025-04-02 00:00:00.000000' : '2025-04-01 00:00:00.000000';
    $video->execute([$id, '10001', null, '123', $when, $when]);
}
$obs = $db->prepare('INSERT INTO poptracker_observations (video_id,observed_at,view_count,like_count,comment_count,share_count,save_count,scan_id) VALUES (?,?,?,?,?,?,?,?)');
function sample(PDOStatement $obs, string $video, int $hour, ?string $views, ?int $shares, ?int $saves, int $scan=1): void
{
    $obs->execute([$video,sprintf('2025-04-03 %02d:00:00.000000', $hour),$views,10,2,$shares,$saves,$scan]);
}
$id = '9999999999999999999';
sample($obs,$id,1,'100',2,1);
sample($obs,$id,2,'125',3,4);
sample($obs,$id,3,'180',4,5);
sample($obs,$id,3,'200',7,8);
sample($obs,$id,3,null,700,800);
foreach (['12','13','14'] as $index=>$peer) {
    sample($obs,$peer,1,'50',1,1);
    sample($obs,$peer,2,(string)(60+10*$index),1,1);
}
$localFixture = static function (array $packet): array {
    if (isset($packet['observations']) || isset($packet['evidence'])) {
        throw new RuntimeException('model received raw points');
    }
    $high = $packet['features']['views_per_hour'] > 60;
    return ['video_id'=>$packet['video_id'],'input_sha256'=>$packet['input_sha256'],'attention_status'=>$high?'high':'low',
        'collect_comments'=>$high,'potential_trend'=>$high,'reason'=>'Fixture decision'];
};
$first = productionEvaluateVideo($db,$id,$localFixture,'test-model-v1');
$f = $first['features'];
checkTrend($first['status']==='evaluated' && $first['collect_comments'] && $first['attention_status']==='high', 'high-priority model output');
checkTrend($f['views_per_hour']===75.0 && $f['view_acceleration_per_hour_squared']===50.0
    && $f['shares_per_hour']===4.0 && $f['saves_per_hour']===4.0, 'rates, acceleration, shared time tie and independent nullable counters');
checkTrend($f['engagement_rate']===27/200 && $f['age_hours_at_observation']===27.0, 'engagement fraction and age at the same cutoff');
checkTrend($f['author_median_views_per_hour']===20.0 && $f['views_per_hour_minus_author_median']===55.0
    && $f['catalog_sound_videos_7d']===8 && $f['catalog_sound_authors_7d']===1,'prior-author comparison and catalog sound spread');
checkTrend(count($first['evidence']['observations'])===3 && count($first['evidence']['author_baseline'])===3,'retained feature provenance');
$replay = productionEvaluateVideo($db,$id,$localFixture,'test-model-v1');
checkTrend($replay['input_sha256']===$first['input_sha256'],'replay has the same evidence fingerprint');
checkTrend(productionEvaluateVideo($db,$id)['status']==='blocked','unconfigured inference cannot approve comments');
$low = productionEvaluateVideo($db,'12',$localFixture,'test-model-v1');
checkTrend($low['attention_status']==='low' && !$low['collect_comments'],'low interest does not authorize comments');
sample($obs,'15',1,'200',3,1);
$never = static fn(array $packet): array => throw new RuntimeException('model must not be called');
checkTrend(productionEvaluateVideo($db,'15',$never,'test-v1')['status']==='insufficient_data','single observation does not invoke model');
sample($obs,'15',2,'199',null,null);
$corrected = productionEvaluateVideo($db,'15',$never,'test-v1');
checkTrend($corrected['status']==='insufficient_data' && $corrected['features']['views_per_hour']===-1.0
    && $corrected['features']['shares_per_hour']===null && $corrected['features']['engagement_rate']===null,'counter correction and missing fields');
sample($obs,'16',1,'0',0,0);
sample($obs,'16',2,'0',0,0);
checkTrend(productionEvaluateVideo($db,'16',$localFixture,'test-v1')['features']['engagement_rate']===null,'zero denominator preserves unknown engagement');
sample($obs,'17',1,'18446744073709551614',1,1);
sample($obs,'17',2,'18446744073709551615',1,1);
checkTrend(productionEvaluateVideo($db,'17',$localFixture,'test-v1')['features']['views_per_hour']===1.0,'exact delta for adjacent unsigned 64-bit counters');
sample($obs,'18',1,'2',1,1);
sample($obs,'18',2,'10',1,1,2);
checkTrend(productionEvaluateVideo($db,'18',$never,'test-v1')['status']==='insufficient_data','source transition cannot trigger collection');
sample($obs,$id,4,'325',8,10);
$next = productionEvaluateVideo($db,$id,$localFixture,'test-model-v1');
checkTrend($first['input_sha256']!==$next['input_sha256'], 'new observation requires a new evaluation');
try {
    productionEvaluateVideo($db,$id,fn(array $packet): array => array_replace($localFixture($packet), ['input_sha256'=>$first['input_sha256']]),'test-model-v1');
    throw new RuntimeException('stale model reply accepted');
} catch (UnexpectedValueException $expected) { checkTrend($expected->getMessage()==='invalid_model_decision','stale response rejected'); }
