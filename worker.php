<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/src/support.php';
require __DIR__ . '/src/outbox.php';
try {
    if (count($argv) < 2 || count($argv) > 3 || !preg_match('/^[1-9][0-9]{0,8}$/D', $argv[1]) || (isset($argv[2]) && $argv[2] !== '--complete')) throw new InvalidArgumentException('Usage: php worker.php MESSAGE_ID [--complete]');
    initialize_test_outbox();
    dispatch_test_message((int)$argv[1], ($argv[2] ?? '') === '--complete');
    echo 'LOCAL TEST only: ' . run('SELECT state FROM test_messages WHERE id=?', [(int)$argv[1]])->fetchColumn() . ". No message sent.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
