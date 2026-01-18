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

$ansiColors = [
    30 => '#1a1a1a',
    31 => '#ff5c57',
    32 => '#5af78e',
    33 => '#f3f99d',
    34 => '#57c7ff',
    35 => '#ff6ac1',
    36 => '#9aedfe',
    37 => '#f1f1f0',
    90 => '#808080',
    91 => '#ff6e67',
    92 => '#5af78e',
    93 => '#f3f99d',
    94 => '#57c7ff',
    95 => '#ff6ac1',
    96 => '#9aedfe',
    97 => '#ffffff',
];

function ansi_to_html(string $text, array $colors): string {
    $pattern = "/\x1b\\[[0-9;]*m/";
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $style = [
        'color' => null,
        'bold' => false,
        'italic' => false,
        'underline' => false,
        'dim' => false,
    ];
    $out = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (preg_match($pattern, $part)) {
            $codes = trim(substr($part, 2, -1));
            $codes = $codes === '' ? ['0'] : explode(';', $codes);
            foreach ($codes as $code) {
                $c = (int)$code;
                if ($c === 0) {
                    $style = ['color' => null, 'bold' => false, 'italic' => false, 'underline' => false, 'dim' => false];
                } elseif ($c === 1) {
                    $style['bold'] = true;
                } elseif ($c === 2) {
                    $style['dim'] = true;
                } elseif ($c === 3) {
                    $style['italic'] = true;
                } elseif ($c === 4) {
                    $style['underline'] = true;
                } elseif ($c === 22) {
                    $style['bold'] = false;
                    $style['dim'] = false;
                } elseif ($c === 23) {
                    $style['italic'] = false;
                } elseif ($c === 24) {
                    $style['underline'] = false;
                } elseif ($c === 39) {
                    $style['color'] = null;
                } elseif (isset($colors[$c])) {
                    $style['color'] = $colors[$c];
                }
            }
            continue;
        }

        $escaped = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
        $styleParts = [];
        if ($style['color']) {
            $styleParts[] = 'color:' . $style['color'];
        }
        if ($style['bold']) {
            $styleParts[] = 'font-weight:700';
        }
        if ($style['italic']) {
            $styleParts[] = 'font-style:italic';
        }
        if ($style['underline']) {
            $styleParts[] = 'text-decoration:underline';
        }
        if ($style['dim']) {
            $styleParts[] = 'opacity:0.85';
        }
        if (!empty($styleParts)) {
            $out .= '<span style="' . implode(';', $styleParts) . '">' . $escaped . '</span>';
        } else {
            $out .= $escaped;
        }
    }
    return $out;
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
    $allContent = '';
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($file) === true) {
            foreach ($sections as $name => $label) {
                $content = $zip->getFromName($name);
                if ($content !== false) {
                    $files[$name] = [
                        'label' => $label,
                        'content' => $content,
                    ];
                    if (substr($name, -4) === '.log') {
                        $allContent .= $content . "\n\n";
                    }
                }
            }
            $zip->close();
        }
    }

    $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    $downloadUrl = '/r/' . $safeId . '.zip';
    $hasContent = count($files) > 0;
    $tabs = $files;
    if (trim($allContent) !== '') {
        $tabs = array_merge(
            ['all.log' => ['label' => 'All', 'content' => $allContent]],
            $tabs
        );
    }
    $firstTab = $hasContent ? array_key_first($tabs) : '';

    $html = '<!doctype html><html lang="zh-Hant"><head>'
        . '<meta charset="utf-8" />'
        . '<meta name="viewport" content="width=device-width, initial-scale=1" />'
        . '<title>易通数据 - 测试结果</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com" />'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />'
        . '<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet" />'
        . '<style>'
        . ':root{--color-primary:#0d1e67;--color-primary-light:#1a2d8a;--color-accent:#2f8bff;--color-accent-hover:#1f6fe0;'
        . '--color-text:#dbe4ff;--color-text-muted:#98a3c7;--color-border:rgba(255,255,255,.1);'
        . '--radius-lg:1rem;--radius-md:.5rem;--shadow-lg:0 16px 32px rgba(0,0,0,.35);}'
        . 'body{margin:0;font-family:Roboto,-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;'
        . 'color:var(--color-text);background:radial-gradient(1200px 600px at 20% -10%, #1b2d8c 0%, rgba(13,30,103,0) 60%),'
        . 'linear-gradient(135deg,var(--color-primary) 0%,var(--color-primary-light) 100%);min-height:100vh;}'
        . '.hero{padding:40px 0 16px;text-align:center;color:#fff;}'
        . '.hero h1{margin:0;font-size:2.2rem;font-weight:700;}'
        . '.hero p{margin:8px 0 0;opacity:.9;}'
        . '.container{max-width:980px;margin:0 auto;padding:0 16px 48px;}'
        . '.card{background:rgba(9,14,34,.7);border:1px solid rgba(255,255,255,.08);border-radius:var(--radius-lg);'
        . 'box-shadow:var(--shadow-lg);padding:20px;backdrop-filter:blur(6px);}'
        . '.meta{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:12px;}'
        . '.meta .id{font-weight:600;color:#cbd6ff;}'
        . '.btn{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;'
        . 'background:var(--color-accent);color:#fff;text-decoration:none;font-weight:600;font-size:14px;}'
        . '.btn:hover{background:var(--color-accent-hover);}'
        . '.tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;}'
        . '.tab{padding:6px 12px;border-radius:999px;border:1px solid var(--color-border);background:rgba(255,255,255,.06);'
        . 'color:#e6ecff;cursor:pointer;font-weight:600;font-size:13px;}'
        . '.tab.active{background:#0f1a4d;color:#fff;border-color:#2f8bff;}'
        . '.panel{display:none;}'
        . '.panel.active{display:block;}'
        . '.terminal{background:#0b1024;border:1px solid rgba(255,255,255,.08);border-radius:12px;'
        . 'padding:12px;max-height:70vh;overflow:auto;resize:vertical;}'
        . 'pre{margin:0;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;'
        . 'font-size:13px;line-height:1.5;color:#dbe4ff;white-space:pre;}'
        . '.empty{padding:20px;text-align:center;color:var(--color-text-muted);}'
        . '</style></head><body>'
        . '<section class="hero"><h1>易通数据 · 测试结果</h1><p>ETDATA NodeQuality Report</p></section>'
        . '<div class="container"><div class="card">'
        . '<div class="meta"><div class="id">Result ID: ' . $safeId . '</div>'
        . '<a class="btn" href="' . $downloadUrl . '">Download ZIP</a></div>';

    if (!$hasContent) {
        $html .= '<div class="empty">Result parsed failed or ZipArchive missing. Please download the ZIP.</div>';
    } else {
        $html .= '<div class="tabs">';
        foreach ($tabs as $name => $data) {
            $active = ($name === $firstTab) ? ' active' : '';
            $label = htmlspecialchars($data['label'], ENT_QUOTES, 'UTF-8');
            $html .= '<button class="tab' . $active . '" data-tab="' . $name . '">' . $label . '</button>';
        }
        $html .= '</div>';

        foreach ($tabs as $name => $data) {
            $active = ($name === $firstTab) ? ' active' : '';
            $content = ansi_to_html($data['content'], $ansiColors);
            $html .= '<div class="panel' . $active . '" data-panel="' . $name . '"><div class="terminal"><pre>' . $content . '</pre></div></div>';
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
