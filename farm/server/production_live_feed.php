<?php
declare(strict_types=1);

function liveFeedBusyReason(PDO $db): ?string
{
    $run = query($db, "SELECT r.phase,r.run_id,j.status FROM production_apify_feed_runs r
        JOIN production_jobs j ON j.id=r.job_id
        WHERE r.provider_slot=1 OR (r.phase='queued' AND j.status IN ('pending','running')) LIMIT 1")->fetch();
    if (!$run) {
        return null;
    }
    if ($run['phase'] === 'uncertain' || in_array($run['status'], ['blocked','failed','cancelled'], true)) {
        $id = $run['run_id'] ? ' ('.$run['run_id'].')' : '';
        return 'Требуется сверка предыдущего запуска Apify'.$id.'. Новый сбор временно закрыт.';
    }
    return 'Сбор ленты ZA уже поставлен в очередь.';
}

/** The caller holds the production_state row lock for the duration of this transaction. */
function liveFeedSubmit(PDO $db, string $requestId, int $quantity = APIFY_FEED_MIN): int
{
    feedRequire($db->inTransaction(), 'feed_submit_requires_transaction');
    if (!isUuid($requestId)) {
        reject(422, 'Некорректный идентификатор запуска.');
    }
    $paused = (bool)query($db, 'SELECT paused FROM production_state WHERE id=1 FOR UPDATE')->fetchColumn();
    $existing = query($db, 'SELECT requested_count FROM production_apify_feed_runs WHERE request_id=?', [$requestId])->fetchColumn();
    if ($existing !== false) {
        if ((int)$existing !== $quantity) { reject(409, 'Этот запрос уже сохранён с другим количеством публикаций.'); }
        return 0;
    }
    $quote = apifyFeedQuote($quantity);
    if ($paused) {
        reject(409, 'Очередь приостановлена.');
    }
    if ($reason = liveFeedBusyReason($db)) {
        reject(409, $reason);
    }
    feedRequire(prodEnqueue($db, 'IMPORT_FEED', 'ZA', $requestId, 50, $quantity), 'apify_feed_queue_conflict');
    $id = query($db, 'SELECT id FROM production_jobs WHERE retained_key=?',
        [prodKey('IMPORT_FEED', 'ZA', $requestId)])->fetchColumn();
    query($db, 'INSERT INTO production_apify_feed_runs(job_id,request_id,actor_id,build_id,requested_input,max_charge_usd,requested_count)
        VALUES(?,?,?,?,?,?,?)', [$id,$requestId,APIFY_FEED_ACTOR,APIFY_FEED_BUILD_ID,json_encode(apifyFeedInput(min($quantity, APIFY_FEED_BATCH)), JSON_THROW_ON_ERROR),apifyUsd($quote['max_charge_cents']),$quantity]);
    return 1;
}

function liveFeedCanCancel(?string $phase, ?int $batch): bool
{
    return $phase === null || ($phase === 'queued' && $batch === 1);
}

/** Recheck under lock before cancelling. A later phase may already have incurred a charge. */
function liveFeedCancelAllowed(PDO $db, string $jobId): bool
{
    $run = query($db, 'SELECT phase,batch_number FROM production_apify_feed_runs WHERE job_id=? FOR UPDATE', [$jobId])->fetch();
    return liveFeedCanCancel($run['phase'] ?? null, isset($run['batch_number']) ? (int)$run['batch_number'] : null);
}

function liveFeedWait(PDO $db, string $id, string $message, int $delay): void
{
    query($db, "UPDATE production_jobs SET status='pending',result=?,next_run_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6))
        WHERE id=?", [$message,$delay,$id]);
}

function liveFeedStop(PDO $db, array $run, bool $uncertain, string $message): void
{
    if ($uncertain) {
        $providerId = $run['run_id'] === null ? '' : ' ID '.$run['run_id'].'.';
        $message .= $providerId.' Проверьте запуск в Apify перед следующим сбором.';
    }
    prodTxn($db, function () use ($db,$run,$uncertain,$message) {
        query($db, 'UPDATE production_apify_feed_runs SET phase=? WHERE job_id=?',
            [$uncertain ? 'uncertain' : 'failed',$run['job_id']]);
        prodFinish($db, $run['job_id'], $uncertain ? 'blocked' : 'failed', $message);
    });
}

function liveFeedReadError(PDO $db, array $run, Throwable $error): void
{
    $polling = $run['phase'] === 'polling';
    if ($error instanceof ApifyFeedTransient && (int)$run['network_errors'] < 4) {
        prodTxn($db, function () use ($db,$run,$polling) {
            $n = (int)$run['network_errors'] + 1;
            query($db, 'UPDATE production_apify_feed_runs SET network_errors=? WHERE job_id=?', [$n,$run['job_id']]);
            liveFeedWait($db, $run['job_id'], $polling ? 'Ожидаем ответа Apify по начатому сбору.' : 'Ожидаем ответа Apify перед запуском.', 20 * $n);
        });
        return;
    }
    $terminal = [
        'apify_run_failed' => 'Сбор завершился ошибкой в Apify.',
        'apify_run_aborted' => 'Сбор остановлен в Apify.',
        'apify_run_timed_out' => 'Сбор в Apify превысил лимит времени.',
    ];
    $code = $error->getMessage();
    if ($polling && isset($terminal[$code])) {
        liveFeedStop($db, $run, false, $terminal[$code].' ID '.$run['run_id'].'.');
        return;
    }
    $code = preg_match('/\Aapify_[a-z0-9_]{1,65}\z/D', $code) ? ' ('.$code.')' : '';
    liveFeedStop($db, $run, $polling,
        ($polling ? 'Не удалось подтвердить результат сбора' : 'Apify не прошёл проверку перед запуском').$code.'.');
}

function liveFeedStartJob(PDO $db, array $run, callable $preflight, callable $start): void
{
    try {
        $owner = $preflight(null, $run['batch_size']);
        if ($run['owner_id'] !== null && $owner !== $run['owner_id']) {
            throw new RuntimeException('apify_run_owner_changed');
        }
    } catch (Throwable $error) {
        liveFeedReadError($db, $run, $error);
        return;
    }
    // Commit intent before the only billable POST. An interrupted POST is never replayed.
    $claimed = prodTxn($db, function () use ($db,$run,$owner) {
        if ((bool)query($db, 'SELECT paused FROM production_state WHERE id=1 FOR UPDATE')->fetchColumn()) {
            liveFeedWait($db, $run['job_id'], 'Очередь приостановлена перед запуском Apify.', 0);
            return false;
        }
        return query($db, "UPDATE production_apify_feed_runs r JOIN production_jobs j ON j.id=r.job_id
            SET r.phase='starting',r.owner_id=?,r.start_intent_at=UTC_TIMESTAMP(6),r.network_errors=0
            WHERE r.job_id=? AND r.phase='queued' AND j.status='running'", [$owner,$run['job_id']])->rowCount() === 1;
    });
    if (!$claimed) {
        return;
    }
    try {
        $remote = $start($owner, null, $run['batch_size']);
    } catch (Throwable $error) {
        liveFeedStop($db, $run, true, 'Не удалось подтвердить ответ на запуск. Повторный запрос остановлен.');
        return;
    }
    prodTxn($db, function () use ($db,$run,$remote) {
        query($db, "UPDATE production_apify_feed_runs SET phase='polling',run_id=?,dataset_id=? WHERE job_id=?",
            [$remote['run_id'],$remote['dataset_id'],$run['job_id']]);
        query($db, 'INSERT INTO production_apify_feed_batches(job_id,batch_number,requested_count,max_charge_usd,run_id,dataset_id)
            VALUES(?,?,?,?,?,?)', [$run['job_id'],$run['batch_number'],$run['batch_size'],apifyUsd(apifyBatchCapCents($run['batch_size'])),
                $remote['run_id'],$remote['dataset_id']]);
        liveFeedWait($db, $run['job_id'], 'Apify собирает ленту ZA. Запуск '.$remote['run_id'].'.', 20);
    });
}

function liveFeedPollJob(PDO $db, array $run, callable $poll): void
{
    $deadline = apifyBatchTimeout($run['batch_size']) + 1200;
    if ((int)$run['poll_count'] >= intdiv($deadline, 20) || (int)$run['elapsed'] >= $deadline) {
        liveFeedStop($db, $run, true, 'Истёк срок ожидания подтверждённого результата Apify.');
        return;
    }
    query($db, 'UPDATE production_apify_feed_runs SET poll_count=poll_count+1 WHERE job_id=?', [$run['job_id']]);
    try {
        $snapshot = $poll($run['owner_id'], $run['run_id'], $run['dataset_id'], null, $run['batch_size']);
    } catch (Throwable $error) {
        liveFeedReadError($db, $run, $error);
        return;
    }
    $id = $run['job_id'];
    if ($snapshot === null) {
        prodTxn($db, function () use ($db,$id) {
            query($db, 'UPDATE production_apify_feed_runs SET network_errors=0,terminal_fingerprint=NULL WHERE job_id=?', [$id]);
            liveFeedWait($db, $id, 'Ожидаем завершения и закрытия набора Apify.', 20);
        });
        return;
    }
    $fingerprint = hash('sha256', json_encode([$snapshot['sha'],$snapshot['cost'],$snapshot['started'],
        $snapshot['finished'],count($snapshot['rows'])], JSON_THROW_ON_ERROR));
    if ($fingerprint !== $run['terminal_fingerprint']) {
        prodTxn($db, function () use ($db,$id,$fingerprint) {
            query($db, 'UPDATE production_apify_feed_runs SET terminal_fingerprint=?,network_errors=0 WHERE job_id=?',
                [$fingerprint,$id]);
            liveFeedWait($db, $id, 'Проверяем стабильность завершённого набора Apify.', 20);
        });
        return;
    }
    prodTxn($db, function () use ($db,$id,$snapshot,$run) {
        query($db, "UPDATE production_apify_feed_runs SET phase='importing',source_sha=?,received_at=?,started_at=?,finished_at=?,
            provider_cost_usd=COALESCE(provider_cost_usd,0)+?,projected_rows=?,network_errors=0 WHERE job_id=?", [$snapshot['sha'],$snapshot['received'],
                $snapshot['started'],$snapshot['finished'],$snapshot['cost'],
                json_encode($snapshot['rows'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),$id]);
        query($db, 'UPDATE production_apify_feed_batches SET source_sha=?,provider_cost_usd=?,row_count=?,started_at=?,finished_at=?
            WHERE job_id=? AND batch_number=?', [$snapshot['sha'],$snapshot['cost'],count($snapshot['rows']),$snapshot['started'],$snapshot['finished'],$id,$run['batch_number']]);
        liveFeedWait($db, $id, 'Набор Apify подтверждён, начинаем импорт.', 0);
    });
}

function liveFeedImportJob(PDO $db, array $run): void
{
    $rows = json_decode($run['projected_rows'], true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    $size = $run['batch_size'];
    $offset = (int)$run['import_cursor'];
    feedRequire(is_array($rows) && array_is_list($rows) && count($rows) <= $size && $offset <= count($rows), 'apify_staged_row_count_invalid');
    $hasHistory = is_dir(feedRoot().'/geo-history');
    [$facts,$previous] = $hasHistory ? feedGeoRegistry() : [[],[]];
    $catalog = feedCatalogName();
    prodTxn($db, function () use ($db,$run,$rows,$offset,$facts,$previous,$hasHistory,$catalog) {
        $id = $run['job_id'];
        $end = min($offset + 100, count($rows));
        $processed = $end - $offset;
        $new = $rejected = $candidates = 0;
        for ($i = $offset; $i < $end; $i++) {
            [$n,$bad,$candidate] = feedApplyItem($db, $rows[$i], ['dataset'=>$run['dataset_id'],'row'=>$i,'sha'=>$run['source_sha'],
                'run'=>$run['run_id'],'received'=>$run['received_at']], $catalog,$facts,$previous,$hasHistory ? 'pending' : 'check_history');
            $new += $n;
            $rejected += $bad;
            $candidates += $candidate;
        }
        $duplicates = $processed - $new - $rejected;
        query($db, 'UPDATE production_apify_feed_runs SET import_cursor=?,new_candidates=new_candidates+?,duplicate_videos=duplicate_videos+? WHERE job_id=?',
            [$end,$candidates,$duplicates,$id]);
        query($db, 'UPDATE production_jobs SET written=written+?,rejected=rejected+?,cursor_position=cursor_position+? WHERE id=?',
            [$new,$rejected,$processed,$id]);
        if ($end < count($rows)) {
            liveFeedWait($db, $id, 'Импортируем публикации из подтверждённого набора Apify.', 0);
            return;
        }

        if ($rows !== []) { prodScoreFeed($db, $run['dataset_id']); }
        query($db, 'UPDATE production_state SET summary_at=NULL WHERE id=1');
        $remaining = (int)$run['requested_count'] - (int)$run['batch_number'] * APIFY_FEED_BATCH;
        if ($remaining > 0 && $rows !== []) {
            $input = apifyFeedInput(min($remaining, APIFY_FEED_BATCH));
            query($db, "UPDATE production_apify_feed_runs SET batch_number=batch_number+1, requested_input=?,phase='queued',
                start_intent_at=NULL,run_id=NULL,dataset_id=NULL, poll_count=0,network_errors=0,terminal_fingerprint=NULL,
                source_sha=NULL,started_at=NULL,finished_at=NULL,received_at=NULL,projected_rows=NULL,import_cursor=0 WHERE job_id=?",
                [json_encode($input, JSON_THROW_ON_ERROR), $id]);
            liveFeedWait($db, $id, 'Готова следующая партия из ленты ZA.', 0);
            return;
        }

        $totals = query($db, 'SELECT cursor_position,written,rejected FROM production_jobs WHERE id=?', [$id])->fetch();
        $count = (int)$totals['cursor_position'];
        query($db, "UPDATE production_apify_feed_runs SET phase='done' WHERE job_id=?", [$id]);
        query($db, 'UPDATE production_jobs SET total=? WHERE id=?', [$count,$id]);
        $totalCandidates = (int)$run['new_candidates'] + $candidates;
        $known = (int)$run['duplicate_videos'] + $duplicates;
        $cost = rtrim(rtrim($run['provider_cost_usd'], '0'), '.') ?: '0';
        $summary = "Запрошено: {$run['requested_count']}. Строк: $count. Новых видео: {$totals['written']}. Уже известных: $known. Новых кандидатов: $totalCandidates. Отклонено: {$totals['rejected']}. Стоимость Apify: $".$cost.'.';
        prodFinish($db, $id, 'completed', $summary);
    });
}

/** The scheduler holds its worker lock and has already committed its claim. */
function liveFeedStep(PDO $db, array $job, array $provider = []): void
{
    feedRequire(!$db->inTransaction(), 'apify_network_inside_transaction');
    $run = query($db, 'SELECT r.*, TIMESTAMPDIFF(SECOND,r.start_intent_at,UTC_TIMESTAMP(6)) AS elapsed
        FROM production_apify_feed_runs r WHERE job_id=?', [$job['id']])->fetch();
    feedRequire((bool)$run, 'apify_feed_request_missing');
    $quantity = (int)$run['requested_count'];
    $quote = apifyFeedQuote($quantity);
    $size = min(APIFY_FEED_BATCH, $quantity - ((int)$run['batch_number'] - 1) * APIFY_FEED_BATCH);
    feedRequire($size > 0 && $run['batch_number'] >= 1, 'apify_invalid_batch_number');
    $run['batch_size'] = $size;
    switch ($run['phase']) {
        case 'queued':
            $expected = apifyFeedInput($size);
            $input = json_decode($run['requested_input'], true, 32, JSON_THROW_ON_ERROR);
            ksort($expected);
            ksort($input);
            if ($run['actor_id'] !== APIFY_FEED_ACTOR || $run['build_id'] !== APIFY_FEED_BUILD_ID || $input !== $expected ||
                (float)$run['max_charge_usd'] !== (float)apifyUsd($quote['max_charge_cents'])) {
                liveFeedStop($db, $run, false, 'Параметры сбора изменились после постановки задачи. Чтобы принять новые параметры, запустите сбор ещё раз.');
                break;
            }
            liveFeedStartJob($db, $run, $provider['preflight'] ?? 'apifyFeedPreflight', $provider['start'] ?? 'apifyFeedStart');
            break;
        case 'starting':
            liveFeedStop($db, $run, true, 'Исполнитель прервался до фиксации ответа на запуск Apify.');
            break;
        case 'polling':
            liveFeedPollJob($db, $run, $provider['poll'] ?? 'apifyFeedPoll');
            break;
        case 'importing':
            liveFeedImportJob($db, $run);
            break;
        default:
            throw new UnexpectedValueException('apify_feed_invalid_phase');
    }
}
