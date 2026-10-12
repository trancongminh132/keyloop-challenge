<?php

if ($_SERVER['REQUEST_URI'] === '/health') {
    echo 'ready';

    return;
}

$startedAt = microtime(true);
usleep((int) getenv('TEST_UPSTREAM_DELAY_MS') * 1000);
header('Content-Type: application/json');
echo json_encode([
    'documents' => [['id' => 'SALE-1', 'name' => 'Sales Contract']],
    'records' => [['documentId' => 'SVC-1', 'description' => 'Service Invoice']],
    'started_at' => $startedAt,
    'completed_at' => microtime(true),
]);
