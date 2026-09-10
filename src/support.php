<?php
declare(strict_types=1);

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function database(): PDO {
    static $db;
    if ($db) return $db;
    $path = getenv('DATABASE') ?: dirname(__DIR__) . '/data/app.sqlite';
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT PRIMARY KEY, attempts INTEGER NOT NULL, started INTEGER NOT NULL)');
    return $db;
}
function run(string $sql, array $params = []): PDOStatement {
    $statement = database()->prepare($sql); $statement->execute($params); return $statement;
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function notice(string $message): void { $_SESSION['notice'] = $message; }
function go(string $path = '/'): never { header('Location: ' . $path, true, 303); exit; }
function fail(int $status, string $message): never {
    http_response_code($status); echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Request could not be completed</title><body><h1>' . $status . '</h1><p>' . e($message) . '</p><a href="/">Return to workspace</a></body></html>'; exit;
}
function boot(string $name, string $intro): void {
    set_exception_handler(function (Throwable $error): never {
        if ($error instanceof InvalidArgumentException) fail(400, $error->getMessage());
        error_log($error->getMessage());
        fail(500, 'An unexpected error occurred. Please retry.');
    });
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store');
    if (strlen((string)getenv('APP_PASSWORD')) < 12) fail(503, 'Set APP_PASSWORD to at least 12 characters before starting.');
    session_name('workspace_' . substr(hash('sha256', __DIR__), 0, 12));
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => getenv('COOKIE_SECURE') === 'true', 'use_strict_mode' => true]);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) fail(413, 'This request is too large.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) fail(403, 'This form expired. Reload and try again.');
        if (($_POST['action'] ?? '') === 'logout') { $_SESSION = []; session_regenerate_id(true); go(); }
        if (($_POST['action'] ?? '') === 'login') {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown'; $now = time();
            run('DELETE FROM login_attempts WHERE started < ?', [$now - 60]);
            $count = run('SELECT attempts FROM login_attempts WHERE ip = ?', [$ip])->fetchColumn();
            if ($count !== false && $count >= 10) fail(429, 'Too many login attempts. Wait one minute.');
            run('INSERT INTO login_attempts VALUES (?,1,?) ON CONFLICT(ip) DO UPDATE SET attempts=attempts+1', [$ip, $now]);
            if (is_string($_POST['password'] ?? null) && hash_equals(getenv('APP_PASSWORD'), $_POST['password'])) {
                session_regenerate_id(true); $_SESSION['authenticated'] = true;
                $_SESSION['csrf'] = bin2hex(random_bytes(32)); run('DELETE FROM login_attempts WHERE ip = ?', [$ip]); go();
            }
            notice('That password is not correct.'); go();
        }
    }
    if (empty($_SESSION['authenticated'])) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(401, 'Please sign in.');
        page_start($name, 'Private workspace');
        echo '<section class="hero"><p class="eyebrow">A BILLIONCODES WORKSPACE</p><h1>' . e($intro) . '</h1><p>Sign in to your private workspace to get started.</p></section><form class="panel login" method="post">' . csrf_field() . '<input type="hidden" name="action" value="login"><h2>Welcome back.</h2><label for="password">Workspace password</label><input id="password" name="password" type="password" required autocomplete="current-password"><button>Open workspace &rarr;</button></form>';
        page_end(); exit;
    }
}
function page_start(string $name, string $section): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($name) . ' | ' . e($section) . '</title><link rel="stylesheet" href="/style.css"></head><body><div class="shell"><header><a href="/" class="brand">' . e($name) . '<span> / </span></a><span class="edition">' . e($section) . '</span>';
    if (!empty($_SESSION['authenticated'])) echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="logout"><button class="quiet">Sign out</button></form>';
    echo '</header><main>';
    if (isset($_SESSION['notice'])) { echo '<p class="notice" role="status">' . e($_SESSION['notice']) . '</p>'; unset($_SESSION['notice']); }
}
function page_end(): void { echo '</main><footer>Built with intention.<span>Billioncodes / Independent software</span></footer></div></body></html>'; }
function field(string $name, string $label, string $type = 'text', string $value = '', bool $required = true): void {
    echo '<label for="' . e($name) . '">' . e($label) . '</label><input id="' . e($name) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '" maxlength="160" ' . ($required ? 'required' : '') . '>';
}
function choice(string $name, string $label, array $options, string $selected = ''): void {
    echo '<label for="' . e($name) . '">' . e($label) . '</label><select id="' . e($name) . '" name="' . e($name) . '">';
    foreach ($options as $option) echo '<option' . ($option === $selected ? ' selected' : '') . '>' . e($option) . '</option>';
    echo '</select>';
}
function input_text(array $input, string $key, int $limit = 160): string {
    $value = $input[$key] ?? '';
    if (!is_string($value) || strlen(trim($value)) > $limit) throw new InvalidArgumentException('Invalid ' . $key . '.');
    return trim($value);
}
