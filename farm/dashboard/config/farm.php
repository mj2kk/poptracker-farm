<?php

$operators = '/etc/dataroom-farm-operators';
$developers = '/etc/dataroom-farm-developers';

return [
    'operator_ids' => env('FARM_OPERATOR_IDS', is_readable($operators) ? trim(file_get_contents($operators)) : ''),
    'developer_ids' => env('FARM_DEVELOPER_IDS', is_readable($developers) ? trim(file_get_contents($developers)) : ''),
];
