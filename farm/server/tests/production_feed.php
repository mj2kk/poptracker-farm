<?php
declare(strict_types=1);

require dirname(__DIR__).'/farm.php';
require_once dirname(__DIR__).'/production_feed.php';

function verifyFeed(bool $value, string $name): void
{
    if (!$value) { throw new RuntimeException($name); }
    echo "PASS $name\n";
}

verifyFeed(feedId('18446744073709551619') === '18446744073709551619' && feedId(1.2e19) === null,
    'source IDs are exact decimal strings; floating identities are rejected');
verifyFeed(feedDate('2026-11-14T17:52:49.123+01:00') === '2026-11-14 16:52:49.123000'
    && feedDate('2026-02-30T20:00:00Z') === null, 'literal timestamps retain UTC precision; invalid dates are rejected');
verifyFeed(feedMetadata(['videoLikeCount'=>0,'forwardCount'=>100])['share_count'] === null
    && feedMetadata(['videoLikeCount'=>0])['like_count'] === 0, 'absent shares stay null; genuine zero stays zero');

$scratch = tempnam(sys_get_temp_dir(),'farm-feed-proof-');
try {
    file_put_contents($scratch, '{"source":"original"}');
    $expected = hash_file('sha256',$scratch);
    file_put_contents($scratch, '{"source":"changed"}');
    try {
        feedRead($scratch,$expected);
        throw new LogicException('unverified archive accepted');
    } catch (RuntimeException $e) {
        verifyFeed($e->getMessage()==='feed_archive_hash_mismatch','modified archive fails before writing a row');
    }
} finally { unlink($scratch); }

$db = database();
$schema = query($db, 'SELECT DATABASE()')->fetchColumn();
if (!str_starts_with($schema, 'poptracker_farm_test_')) { exit(2); }
putenv('FARM_CATALOG_DB='.$schema);
$db->exec('CREATE TEMPORARY TABLE poptracker_authors (id VARCHAR(32) PRIMARY KEY)');
$db->exec('CREATE TEMPORARY TABLE poptracker_videos (id VARCHAR(32) PRIMARY KEY, author_id VARCHAR(32) NOT NULL)');
foreach (['production_feed_candidates','production_feed_videos','production_feed_items','production_geo_history'] as $table) {
    $ddl = query($db,"SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    $db->exec(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $ddl, 1));
}
$vid='18446744073709551619'; $uid='18446744073709551621';
$row = ['country'=>'ZA','videoAuthorUsername'=>'test123','videoCreatedAt'=>'2026-11-01T10:00:00Z','videoId'=>$vid,'videoAuthorId'=>$uid];
$source = ['dataset'=>'testDataset','row'=>0,'sha'=>str_repeat('a',64),'run'=>'localTest','received'=>'2026-11-14 16:52:49.123000'];
$apply = static fn(array $item,int $n,array $facts=[],array $previous=[]) => feedApplyItem($db,$item,array_replace($source,['row'=>$n]),feedCatalogName(),$facts,$previous);
$count = static fn(string $table) => (int)query($db, "SELECT COUNT(*) FROM `$table`")->fetchColumn();

$db->beginTransaction();
try {
    $apply($row,0);
    verifyFeed($count('production_feed_candidates')===1 && $count('production_feed_videos')===1
        && $count('poptracker_authors')===0 && query($db,'SELECT geo_status FROM production_feed_candidates')->fetchColumn()==='pending',
        'unknown author and video retained outside main catalog as pending');
    $apply($row,0); $apply($row,1);
    verifyFeed($count('production_feed_videos')===1 && $count('production_feed_candidates')===1 && $count('production_feed_items')===2,
        'repeat of a source row and distinct sightings preserve one video and each provenance');
    query($db,'INSERT INTO poptracker_authors VALUES (?)',[$uid]);
    query($db,'INSERT INTO poptracker_videos VALUES (?,?)',[$vid,$uid]);
    $apply($row,0);
    verifyFeed(query($db,'SELECT catalog_author_id FROM production_feed_videos')->fetchColumn()===$uid && $count('poptracker_videos')===1,
        'replay links a candidate video after catalog admission without duplicating or rewriting the catalog');
    $apply(array_replace($row,['videoId'=>'22']),2);
    verifyFeed($count('production_feed_candidates')===1 && $count('production_feed_videos')===2,
        'known author with new video is linked without creating another candidate');
    $apply(array_replace($row,['videoAuthorId'=>null]),3);
    $apply(array_replace($row,['videoId'=>null]),4);
    verifyFeed($count('production_feed_items')===5 && $count('production_feed_videos')===2
        && (int)query($db,'SELECT COUNT(*) FROM production_feed_items WHERE error_code IS NOT NULL')->fetchColumn()===2,
        'missing UID and VID are quarantined by source locator without inventing identities');
    $db->exec('SAVEPOINT feed_chunk');
    $apply(array_replace($row,['videoId'=>'23']),5);
    try {
        $apply(array_replace($row,['videoId'=>'23','videoAuthorId'=>'42']),6);
        throw new LogicException('conflicting owner went unreported');
    } catch (RuntimeException $e) {
        verifyFeed($e->getMessage()==='feed_video_identity_conflict','conflicting source owner stops chunk');
        $db->exec('ROLLBACK TO SAVEPOINT feed_chunk');
    }
    verifyFeed($count('production_feed_items')===5 && $count('production_feed_videos')===2, 'interrupted chunk rolls back items and videos together');
    $facts=['55'=>['video_id'=>'33','status'=>'unknown','region'=>null],
        '56'=>['video_id'=>'34','status'=>'non-ZA','region'=>'AU'], '57'=>['video_id'=>'35','status'=>'ZA','region'=>'ZA']];
    foreach (array_keys($facts) as $i=>$historical) {
        $apply(array_replace($row,['videoAuthorId'=>(string)$historical,'videoId'=>$facts[$historical]['video_id']]),7+$i,$facts);
    }
    $apply(array_replace($row,['videoId'=>'36','videoAuthorId'=>'58']),10,[],['58'=>true]);
    verifyFeed($count('production_geo_history')===3 && query($db,"SELECT region FROM production_geo_history WHERE author_uid='55'")->fetchColumn()===null
        && query($db,"SELECT geo_status FROM production_feed_candidates WHERE author_uid='58'")->fetchColumn()==='previous_attempt'
        && $count('poptracker_authors')===1, 'audited ZA, non-ZA, unknown and previous attempts stay distinct; no automatic GEO retry or catalog promotion');
    query($db, 'INSERT INTO poptracker_videos VALUES (?,?)', ['99',$uid]);
    verifyFeed($apply(array_replace($row,['videoId'=>'99']),11) === [0,0,0],
        'a catalog video first seen in the feed retains its source row and contributes no new video or candidate');
    $fresh = array_replace($row,['videoId'=>'310','videoAuthorId'=>'311']);
    $locator = array_replace($source, ['row'=>12]);
    verifyFeed(feedApplyItem($db,$fresh,$locator,feedCatalogName(),[],[],'check_history') === [1,0,1]
        && feedApplyItem($db,$fresh,$locator,feedCatalogName(),[],[],'check_history') === [0,0,0]
        && query($db,"SELECT geo_status FROM production_feed_candidates WHERE author_uid='311'")->fetchColumn()==='check_history',
        'fresh provider rows are counted once and keep unknown geography when prior attempts have not been checked');
} finally { $db->rollBack(); }

if (getenv('FARM_FEED_ARCHIVE')) {
    $targets = productionFeedTargets();
    verifyFeed(count($targets)>0, 'registered archive has independently audited closed datasets');
    foreach ($targets as $dataset=>$rows) {
        [$entry,$received,$items] = feedDataset($dataset);
        verifyFeed(count($items)===$rows, "hash-verified literal provider export $dataset");
    }
    [$facts,$attempted] = feedGeoRegistry();
    verifyFeed(count($facts)===3471 && count($attempted)===18801,'all qualified and unknown E078 results plus attempted registry match evidence');
    $db->beginTransaction();
    try {
        $dataset = array_key_first($targets);
        $page = productionFeedImport($db,$dataset,0,10);
        $replay = productionFeedImport($db,$dataset,0,10);
        verifyFeed($page['next_cursor']===10 && $page['total']===$targets[$dataset]
            && $replay['inserted']===0 && $count('production_feed_items')===10,'real closed dataset imports one bounded page and safely replays from checkpoint');
    } finally { $db->rollBack(); }
}
