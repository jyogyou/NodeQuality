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

    if (!preg_match('/^[0-9]{14}-[a-f0-9]{8}$/', $id)) {
        respond(404, 'not found');
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

    $sections = [
        'header_info.log' => 'Header',
        'basic_info.log' => 'Basic',
        'ip_quality.log' => 'IP Quality',
        'net_quality.log' => 'Net Quality',
        'backroute_trace.log' => 'Backroute',
        'yabs.json' => 'Yabs JSON',
        'ip_quality.json' => 'IP JSON',
        'net_quality.json' => 'Net JSON',
        'backroute_trace.json' => 'Trace JSON',
    ];

    $files = [];
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($file) === true) {
            foreach ($sections as $name => $label) {
                $content = $zip->getFromName($name);
                if ($content !== false) {
                    $content = preg_replace('/\x1b\\[[0-9;]*[A-Za-z]/', '', $content);
                    $files[$name] = [
                        'label' => $label,
                        'content' => $content,
                    ];
                }
            }
            $zip->close();
        }
    }

    $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    $downloadUrl = '/r/' . $safeId . '.zip';
    $hasContent = count($files) > 0;
    $firstTab = $hasContent ? array_key_first($files) : '';

    $html = '<!doctype html><html lang="zh-Hant"><head>'
        . '<meta charset="utf-8" />'
        . '<meta name="viewport" content="width=device-width, initial-scale=1" />'
        . '<title>易通数据 - 测试结果</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com" />'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />'
        . '<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet" />'
        . '<style>'
        . ':root{--color-primary:#0d1e67;--color-primary-light:#1a2d8a;--color-accent:#007aff;--color-accent-hover:#0056b3;'
        . '--color-text:#333;--color-text-light:#666;--color-bg:#fff;--color-bg-light:#f8f9fa;--color-border:rgba(0,0,0,.1);'
        . '--radius-lg:1rem;--radius-md:.5rem;--shadow-md:0 4px 6px rgba(0,0,0,.1);--shadow-lg:0 10px 15px rgba(0,0,0,.1);'
        . '--spacing-4:1rem;--spacing-6:1.5rem;--spacing-8:2rem;--spacing-12:3rem;}'
        . 'body{margin:0;font-family:Roboto,-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;'
        . 'color:var(--color-text);background:linear-gradient(135deg,var(--color-primary) 0%,var(--color-primary-light) 100%);}'
        . '.hero{padding:48px 0 24px;text-align:center;color:#fff;}'
        . '.hero h1{margin:0;font-size:2.2rem;font-weight:700;}'
        . '.hero p{margin:8px 0 0;opacity:.9;}'
        . '.container{max-width:1100px;margin:0 auto;padding:0 16px 48px;}'
        . '.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);padding:24px;}'
        . '.meta{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px;}'
        . '.meta .id{font-weight:600;color:var(--color-primary);}'
        . '.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:var(--radius-md);'
        . 'background:var(--color-accent);color:#fff;text-decoration:none;font-weight:500;}'
        . '.btn:hover{background:var(--color-accent-hover);}'
        . '.tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}'
        . '.tab{padding:8px 12px;border-radius:999px;border:1px solid var(--color-border);background:#fff;cursor:pointer;font-weight:500;}'
        . '.tab.active{background:var(--color-primary);color:#fff;border-color:var(--color-primary);}'
        . '.panel{display:none;}'
        . '.panel.active{display:block;}'
        . 'pre{margin:0;background:var(--color-bg-light);padding:16px;border-radius:var(--radius-md);overflow:auto;font-size:13px;line-height:1.5;}'
        . '.empty{padding:24px;text-align:center;color:var(--color-text-light);}'
        . '</style></head><body>'
        . '<section class="hero"><h1>易通数据 · 测试结果</h1><p>ETDATA NodeQuality Report</p></section>'
        . '<div class="container"><div class="card">'
        . '<div class="meta"><div class="id">Result ID: ' . $safeId . '</div>'
        . '<a class="btn" href="' . $downloadUrl . '">Download ZIP</a></div>';

    if (!$hasContent) {
        $html .= '<div class="empty">Result parsed failed or ZipArchive missing. Please download the ZIP.</div>';
    } else {
        $html .= '<div class="tabs">';
        foreach ($files as $name => $data) {
            $active = ($name === $firstTab) ? ' active' : '';
            $label = htmlspecialchars($data['label'], ENT_QUOTES, 'UTF-8');
            $html .= '<button class="tab' . $active . '" data-tab="' . $name . '">' . $label . '</button>';
        }
        $html .= '</div>';

        foreach ($files as $name => $data) {
            $active = ($name === $firstTab) ? ' active' : '';
            $content = htmlspecialchars($data['content'], ENT_QUOTES, 'UTF-8');
            $html .= '<div class="panel' . $active . '" data-panel="' . $name . '"><pre>' . $content . '</pre></div>';
        }

        $html .= '<script>'
            . 'document.querySelectorAll(".tab").forEach(function(btn){'
            . 'btn.addEventListener("click", function(){'
            . 'document.querySelectorAll(".tab").forEach(function(b){b.classList.remove("active")});'
            . 'document.querySelectorAll(".panel").forEach(function(p){p.classList.remove("active")});'
            . 'btn.classList.add("active");'
            . 'var key=btn.getAttribute("data-tab");'
            . 'var panel=document.querySelector(\'[data-panel="\'+key+\'"]\');'
            . 'if(panel){panel.classList.add("active");}'
            . '});'
            . '});'
            . '</script>';
    }

    $html .= '</div></div></body></html>';
    respond(200, $html, 'text/html; charset=utf-8');
}

respond(404, 'not found');
