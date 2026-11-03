<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Services\BlogAutomationService;
use App\Services\DatabaseUpgradeService;

try {
    $db = Database::connection();
    $pending = (new DatabaseUpgradeService($db))->pending();
    if (array_filter($pending, static fn(string $change): bool => str_contains($change, 'blog_')) !== []) {
        throw new RuntimeException('Blog Automation is waiting for Admin Panel > Database Upgrade.');
    }
    $results = (new BlogAutomationService($db))->runDueSites();
    if ($results === []) {
        echo '[' . date('c') . '] SKIPPED: No enabled WordPress sites.' . PHP_EOL;
        exit(0);
    }
    $failed = false;
    foreach ($results as $result) {
        echo '[' . date('c') . '] ' . strtoupper($result['result']) . ' [' . $result['site_name'] . ']: ' . $result['message'] . PHP_EOL;
        if ($result['result'] === 'failed') $failed = true;
    }
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] FAILED: ' . substr($e->getMessage(), 0, 1500) . PHP_EOL);
    exit(1);
}
