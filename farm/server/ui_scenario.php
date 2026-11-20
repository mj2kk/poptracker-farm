<?php
declare(strict_types=1);

const OWN_UI_SCENARIO = 'OWN_APP_BUTTON_AND_SCROLL_V1';
const UI_FAILURES = ['ACCESSIBILITY_DISABLED','TEST_SCREEN_NOT_OPEN','TARGET_PACKAGE_MISMATCH','NODE_NOT_FOUND',
    'ACTION_FAILED','STEP_TIMEOUT','SCENARIO_TIMEOUT','CANCELLED','INTERNAL_ERROR'];

function validateUiReport(mixed $r, array $data): void {
    if (!$r instanceof stdClass) { reject(422,'invalid_ui_report'); }
    exactFields((array)$r,['schema_version','scenario','status','steps','duration_ms','error_code']);
    $pass = $data['outcome'] === 'succeeded';
    $error = $data['error'];
    $names = ['permission_check','screen_check','reset','counter_zero','button_click','counter_verify','scroll_find_item'];
    if ($r->schema_version !== 1 || $r->scenario !== OWN_UI_SCENARIO || !integerBetween($r->duration_ms,0,2147483647)
        || !is_array($r->steps) || count($r->steps)<1 || count($r->steps)>count($names)) { reject(422,'invalid_ui_report'); }
    if ($pass) {
        if ($r->status!=='PASSED' || $r->error_code!==null || $error!==null || count($r->steps)!==count($names)) { reject(422,'invalid_ui_success'); }
    } else {
        if ($data['outcome']!=='failed' || $r->status!=='FAILED' || !in_array($r->error_code,UI_FAILURES,true) || !$error instanceof stdClass) { reject(422,'invalid_ui_failure'); }
        exactFields((array)$error,['code','message']);
        if ($error->code!==$r->error_code || $error->message!=='Failed at '.$names[count($r->steps)-1]) { reject(422,'invalid_ui_failure'); }
    }
    $observations = ['counter_zero'=>'0','counter_verify'=>'1','scroll_find_item'=>'Test Item 25'];
    $elapsed = 0;
    foreach ($r->steps as $i=>$step) {
        $failed = !$pass && $i===count($r->steps)-1;
        if (!$step instanceof stdClass) { reject(422,'invalid_ui_step'); }
        $keys=['name','status','duration_ms'];
        if (!$failed && isset($observations[$names[$i]])) { $keys[]='observed_value'; }
        exactFields((array)$step,$keys);
        if ($step->name!==$names[$i] || $step->status!==($failed ? 'FAILED' : 'PASSED')
            || !integerBetween($step->duration_ms,0,$r->duration_ms)
            || (!$failed && isset($observations[$step->name]) && $step->observed_value!==$observations[$step->name])) { reject(422,'invalid_ui_step'); }
        $elapsed += $step->duration_ms;
    }
    if ($elapsed>$r->duration_ms) { reject(422,'invalid_ui_duration'); }
}
