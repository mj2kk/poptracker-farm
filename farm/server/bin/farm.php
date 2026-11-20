<?php
declare(strict_types=1);
require dirname(__DIR__).'/farm.php';
$db = database();
if (str_starts_with($argv[1] ?? '', 'task:')) {
    try {
        echo json_encode(taskCommand($db,$argv),JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    } catch (ApiError|JsonException $e) {
        fwrite(STDERR, $e->getMessage()."\n");
        exit(2);
    }
}
switch ($argv[1] ?? '') {
    case 'issue':
        $pair = issue($db, $argv[2] ?? '');
        echo $pair['device_id'], " ", $pair['code'], "\n";
        break;
    case 'list':
        $rows = listDevices($db);
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
        break;
    case 'revoke':
        $id = $argv[2] ?? '';
        $db->beginTransaction();
        // Invalidate a pending enrollment as well as its token, in enrollment lock order.
        $matched = query($db, 'SELECT device_id FROM enrollment_codes WHERE device_id=? FOR UPDATE', [$id])->fetchColumn();
        query($db, 'UPDATE enrollment_codes SET expires_at=UTC_TIMESTAMP() WHERE device_id=?', [$id]);
        $changed = query($db, 'UPDATE device_agents SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE device_id=?', [$id]);
        $db->commit();
        if (!$matched && !$changed->rowCount()) { fwrite(STDERR, "Unknown device\n"); exit(1); }
        echo "Revoked\n";
        break;
    case 'prune':
        expireTaskLeases($db);
        query($db, "DELETE FROM device_tasks WHERE status IN ('succeeded','failed','cancelled') AND updated_at<UTC_TIMESTAMP() - INTERVAL 30 DAY");
        query($db, 'DELETE FROM device_heartbeats WHERE received_at < UTC_TIMESTAMP() - INTERVAL 7 DAY');
        query($db, 'DELETE FROM enrollment_codes WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY');
        break;
    default:
        fwrite(STDERR, "Usage: farm.php issue LABEL | list | revoke UUID | prune\n");
        exit(2);
}
