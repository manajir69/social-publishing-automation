<?php
declare(strict_types=1);

$checks = [
    'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'cURL' => extension_loaded('curl'),
    'JSON' => extension_loaded('json'),
    'OpenSSL' => extension_loaded('openssl'),
    'Sodium encryption' => extension_loaded('sodium'),
    'HTTPS request' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
];
$ready = !in_array(false, $checks, true);
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Server Check</title><style>body{font:16px system-ui;max-width:720px;margin:40px auto;padding:20px;color:#17202a}table{width:100%;border-collapse:collapse}td{padding:12px;border-bottom:1px solid #ddd}.ok{color:#087f23}.bad{color:#b42318}.note{background:#f4f6f8;padding:14px;border-radius:8px}</style></head>
<body><h1>Social Automation — Server Check</h1><p class="note">No API keys or database passwords are displayed by this page.</p><table>
<?php foreach ($checks as $label => $result): ?><tr><td><?= htmlspecialchars($label) ?></td><td class="<?= $result ? 'ok' : 'bad' ?>"><?= $result ? 'PASS' : 'FAIL' ?></td></tr><?php endforeach; ?>
</table><h2 class="<?= $ready ? 'ok' : 'bad' ?>"><?= $ready ? 'Server is ready.' : 'Fix failed requirements before installation.' ?></h2></body></html>
