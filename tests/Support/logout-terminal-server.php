<?php

// Test-only pause BEFORE the real logout route; no substitute authority or response.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'builderad_test_disposable'
    || getenv('DB_PORT') !== '5433' || ! getenv('BUILDERAD_TEST_ISOLATION_TOKEN')) {
    http_response_code(503);
    exit('Disposable proof environment required.');
}
$run = getenv('CURRENT_INTENT_PROOF_RUN');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/logout') {
    file_put_contents($run.'/logout-arrived', '1');
    $deadline = microtime(true) + 20;
    while (! is_file($run.'/logout-release') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($run.'/logout-release')) {
        http_response_code(503);
        exit('Proof barrier not released.');
    }
}
require __DIR__.'/advertiser-goals-server.php';
