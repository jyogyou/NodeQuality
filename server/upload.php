<?php
declare(strict_types=1);

$baseUrl = getenv('BASE_URL') ?: 'https://test.etdata.link';
$outputDir = getenv('OUTPUT_DIR') ?: __DIR__ . '/data';
$maxBytes = (int)(getenv('MAX_BYTES') ?: 50 * 1024 * 1024);

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

function respond(int $status, string $body, string $contentType = 'text/plain; charset=utf-8'): void {
    http_response_code($status);
    header('Content-Type: ' . $contentType);
    echo $body;
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' && $path === '/api/v1/record') {
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        respond(500, 'server error');
    }
    if (strlen($raw) > $maxBytes) {
        respond(413, 'payload too large');
    }

    $clean = preg_replace('/\s+/', '', $raw);
    $zip = base64_decode($clean, true);
    if ($zip === false || $zip === '') {
        respond(400, 'invalid base64');
    }

    $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    $file = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id . '.zip';
    file_put_contents($file, $zip);

    respond(200, $baseUrl . '/r/' . $id);
}

if ($method === 'GET' && is_string($path) && strpos($path, '/r/') === 0) {
    $id = substr($path, 3);
    if ($id === '') {
        respond(404, 'not found');
    }

    $download = false;
    if (substr($id, -4) === '.zip') {
        $download = true;
        $id = substr($id, 0, -4);
    }

    $file = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id . '.zip';
    if (!is_file($file)) {
        respond(404, 'not found');
    }

    if ($download) {
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    $html = '<!doctype html><html lang="en"><meta charset="utf-8" />'
        . '<title>ETDATA - Test Result</title>'
        . '<body style="font-family: sans-serif; padding: 24px;">'
        . '<h1>ETDATA - Test Result</h1>'
        . '<p>Result ID: ' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="/r/' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '.zip">Download ZIP</a></p>'
        . '</body></html>';
    respond(200, $html, 'text/html; charset=utf-8');
}

respond(404, 'not found');
