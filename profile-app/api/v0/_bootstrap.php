<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

function profile_app_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
