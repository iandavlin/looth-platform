<?php
// Shared bootstrap for archive-poc API endpoints.
// Cookie-gate is enforced by nginx; if a request reaches PHP we trust it.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Archive-Poc: v0');

$SQLITE = realpath(__DIR__ . '/../../index.sqlite');
if (!$SQLITE || !is_file($SQLITE)) {
    http_response_code(500);
    echo json_encode(['error' => 'index missing']);
    exit;
}

try {
    $db = new PDO('sqlite:' . $SQLITE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA query_only = ON');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db open failed', 'detail' => $e->getMessage()]);
    exit;
}

function send_json($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function param_str(string $k, string $default = ''): string {
    $v = $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $default;
}
function param_int(string $k, int $default = 0): int {
    $v = $_GET[$k] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}
function param_csv(string $k): array {
    $v = param_str($k);
    if ($v === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
}

/** Sanitize an FTS5 MATCH input — quote each token to avoid syntax errors. */
function fts_quote(string $q): string {
    $tokens = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($tokens as $t) {
        $t = str_replace('"', '', $t);
        if ($t === '') continue;
        $out[] = '"' . $t . '"';
    }
    return implode(' ', $out);
}
