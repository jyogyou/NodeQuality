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

$ansiBgColors = [
    40 => '#1a1a1a',
    41 => '#ff5c57',
    42 => '#5af78e',
    43 => '#f3f99d',
    44 => '#57c7ff',
    45 => '#ff6ac1',
    46 => '#9aedfe',
    47 => '#f1f1f0',
    100 => '#4d4d4d',
    101 => '#ff6e67',
    102 => '#5af78e',
    103 => '#f3f99d',
    104 => '#57c7ff',
    105 => '#ff6ac1',
    106 => '#9aedfe',
    107 => '#ffffff',
];

function to_utf8(string $text): string {
    if (function_exists('mb_detect_encoding')) {
        $enc = mb_detect_encoding($text, ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'ISO-8859-1'], true);
        if ($enc && $enc !== 'UTF-8') {
            return mb_convert_encoding($text, 'UTF-8', $enc);
        }
    }
    return $text;
}

function normalize_ansi(string $text): string {
    $text = to_utf8($text);
    $text = str_replace("\r", '', $text);
    $text = preg_replace('/\\[([0-9]+(?:;[0-9]+)*)m/', "\x1b[$1m", $text);
    $text = preg_replace('/\x1b\\[[0-9;?]*[HJK]/', '', $text);
    $text = preg_replace('/\x1b\\[[0-9;?]*[JK]/', '', $text);
    $text = preg_replace('/\\[[0-9;?]*[HJK]/', '', $text);
    $text = preg_replace('/\\[[0-9;?]*[JK]/', '', $text);
    $text = preg_replace('/^\\[[0-9;?]*[HJK].*$/m', '', $text);
    $text = preg_replace('/^\\[[0-9;?]*[JK].*$/m', '', $text);
    $text = preg_replace('/^[ \t]+$/m', '', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return $text;
}

function strip_ansi(string $text): string {
    $text = preg_replace('/\x1b\\[[0-9;]*[A-Za-z]/', '', $text);
    $text = preg_replace('/\\[[0-9;?]*[A-Za-z]/', '', $text);
    return $text;
}

function extract_svg_links(string $text): array {
    $links = [];
    if (preg_match_all('#https?://Report\.Check\.Place/(?:ip|net)/[A-Za-z0-9]+\.svg#', $text, $m)) {
        foreach ($m[0] as $url) {
            $links[$url] = true;
        }
    }
    return array_keys($links);
}

function fetch_svg_inline(string $url): string {
    return '';
}

function svg_to_text(string $svg): string {
    $text = strip_tags($svg);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

function ansi_to_html(string $text, array $colors, array $bgColors): string {
    $pattern = "/\x1b\\[[0-9;]*m/";
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $style = [
        'color' => null,
        'bold' => false,
        'italic' => false,
        'underline' => false,
        'dim' => false,
        'bg' => null,
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
                    $style = ['color' => null, 'bold' => false, 'italic' => false, 'underline' => false, 'dim' => false, 'bg' => null];
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
                } elseif ($c === 49) {
                    $style['bg'] = null;
                } elseif (isset($colors[$c])) {
                    $style['color'] = $colors[$c];
                } elseif (isset($bgColors[$c])) {
                    $style['bg'] = $bgColors[$c];
                }
            }
            continue;
        }

        $escaped = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
        $styleParts = [];
        if ($style['color']) {
            $styleParts[] = 'color:' . $style['color'];
        }
        if ($style['bg']) {
            $styleParts[] = 'background-color:' . $style['bg'];
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

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri !== '' ? $requestUri : '/', PHP_URL_PATH);
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

if ($method === 'GET' && ($path === '/svg-proxy' || isset($_GET['url']))) {
    $url = $_GET['url'] ?? '';
    if (!is_string($url) || $url === '') {
        respond(400, 'missing url');
    }
    if (!preg_match('#^https?://Report\.Check\.Place/(ip|net)/[A-Za-z0-9]+\.svg$#', $url)) {
        respond(400, 'invalid url');
    }
    $context = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header' => "User-Agent: ETDATA-NodeQuality\r\n",
        ],
        'https' => [
            'timeout' => 8,
            'header' => "User-Agent: ETDATA-NodeQuality\r\n",
        ],
    ]);
    $svg = @file_get_contents($url, false, $context);
    if ($svg === false && function_exists('shell_exec')) {
        $safeUrl = escapeshellarg($url);
        $svg = shell_exec("curl -fsSL --max-time 8 $safeUrl");
    }
    if ($svg === false) {
        respond(502, 'fetch failed');
    }
    $svg = to_utf8($svg);
    respond(200, $svg, 'image/svg+xml; charset=utf-8');
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
        'basic_info.log' => '基本信息',
        'ip_quality.log' => 'IP质量',
        'net_quality.log' => '网络质量',
        'backroute_trace.log' => '回程路由',
    ];

    $files = [];
    $allContent = '';
    $svgCache = [];
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($file) === true) {
            foreach ($sections as $name => $label) {
                $content = $zip->getFromName($name);
                if ($content !== false) {
                    $content = normalize_ansi($content);
                    $links = extract_svg_links($content);
                    $files[$name] = [
                        'label' => $label,
                        'content' => $content,
                        'links' => $links,
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
    $tabs = [];
    if (isset($files['basic_info.log']) || isset($files['header_info.log'])) {
        $allText = '';
        if (isset($files['header_info.log']['content'])) {
            $allText .= $files['header_info.log']['content'] . "\n\n";
        }
        if (isset($files['basic_info.log']['content'])) {
            $allText .= $files['basic_info.log']['content'];
        }
        $tabs['all.log'] = ['label' => '全部', 'content' => $allText, 'links' => []];
    }
    foreach (['basic_info.log', 'ip_quality.log', 'net_quality.log', 'backroute_trace.log'] as $key) {
        if (isset($files[$key])) {
            $tabs[$key] = $files[$key];
        }
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
        . '.actions{display:flex;gap:8px;flex-wrap:wrap;}'
        . '.btn{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;'
        . 'background:var(--color-accent);color:#fff;text-decoration:none;font-weight:600;font-size:14px;}'
        . '.btn:hover{background:var(--color-accent-hover);}'
        . '.btn-secondary{background:rgba(255,255,255,.12);color:#e6ecff;border:1px solid rgba(255,255,255,.2);}'
        . '.btn-secondary:hover{background:rgba(255,255,255,.2);}'
        . '.tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;}'
        . '.tab{padding:6px 12px;border-radius:999px;border:1px solid var(--color-border);background:rgba(255,255,255,.06);'
        . 'color:#e6ecff;cursor:pointer;font-weight:600;font-size:13px;}'
        . '.tab.active{background:#0f1a4d;color:#fff;border-color:#2f8bff;}'
        . '.panel{display:none;}'
        . '.panel.active{display:block;}'
        . '.report-links{display:grid;gap:12px;margin-bottom:12px;}'
        . '.report-item{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px;}'
        . '.report-link{display:inline-flex;margin-bottom:8px;color:#8ab4ff;text-decoration:none;font-weight:600;}'
        . '.report-link:hover{text-decoration:underline;}'
        . '.report-svg-inline{margin:6px 0;}'
        . '.svg-spacer{height:2.6em;}'
        . '.report-svg-inline svg{width:100%;height:auto;display:block;border-radius:8px;background:#0b1024;}'
        . '.report-fallback{color:#98a3c7;font-size:13px;}'
        . '.terminal{background:#0b1024;border:1px solid rgba(255,255,255,.08);border-radius:12px;'
        . 'padding:12px;max-height:70vh;overflow:auto;resize:vertical;}'
        . 'pre{margin:0;font-family:"Noto Sans Mono","Noto Sans Symbols2","Segoe UI Symbol",ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;'
        . 'font-size:13px;line-height:1.45;color:#dbe4ff;white-space:pre;}'
        . '.float-contact{position:fixed;right:max(16px, env(safe-area-inset-right));'
        . 'bottom:max(120px, env(safe-area-inset-bottom));z-index:1000;max-width:calc(100vw - 24px);}'
        . '.float-contact-link{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:999px;'
        . 'background:linear-gradient(135deg,#0f7ae5 0%,#0d1e67 100%);color:#fff;box-shadow:0 10px 24px rgba(13,30,103,.35);'
        . 'text-decoration:none;transition:transform 200ms ease, box-shadow 200ms ease;}'
        . '.float-contact-link:hover{transform:translateY(-3px);box-shadow:0 14px 28px rgba(13,30,103,.45);}'
        . '.float-contact-icon{width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.18);'
        . 'display:grid;place-items:center;}'
        . '.float-contact-text{display:flex;flex-direction:column;line-height:1.1;}'
        . '.float-contact-title{font-size:.95rem;font-weight:700;letter-spacing:.02em;}'
        . '.float-contact-subtitle{font-size:.75rem;opacity:.85;}'
        . '@media (max-width:640px){.float-contact{right:max(12px, env(safe-area-inset-right));bottom:max(96px, env(safe-area-inset-bottom));}'
        . '.float-contact-link{padding:10px 12px;gap:10px;}.float-contact-icon{width:38px;height:38px;}.float-contact-title{font-size:.85rem;}}'
        . '.empty{padding:20px;text-align:center;color:var(--color-text-muted);}'
        . '</style></head><body>'
        . '<section class="hero"><h1>易通数据 · 测试结果</h1><p>ETDATA NodeQuality Report</p><p class="hero-tagline">易通数据 · 全球 IDC 与 CDN 资源整合服务商，7×24 小时人工技术支持</p></section>'
        . '<div class="container"><div class="card">'
        . '<div class="meta"><div class="id">结果ID：' . $safeId . '</div>'
        . '<div class="actions">'
        . '<button class="btn btn-secondary" data-copy="all">复制全部</button>'
        . '<button class="btn btn-secondary" data-copy="current">复制当前</button>'
        . '</div></div>';

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

        $anchorMap = [
            'basic_info.log' => 'anchor-basic',
            'ip_quality.log' => 'anchor-ip',
            'net_quality.log' => 'anchor-net',
            'backroute_trace.log' => 'anchor-trace',
        ];

        $allActive = ($firstTab === 'all.log') ? ' active' : '';
        $allCopy = '';
        $html .= '<div class="panel' . $allActive . '" data-panel="all.log">';
        $html .= '<div class="terminal">';
        if (!empty($tabs['all.log']['content'])) {
            $headerText = ansi_to_html($tabs['all.log']['content'], $ansiColors, $ansiBgColors);
            $headerCopy = strip_ansi($tabs['all.log']['content']);
            $allCopy .= $headerCopy;
            $html .= '<pre data-raw="' . htmlspecialchars($headerCopy, ENT_QUOTES, 'UTF-8') . '">' . $headerText . '</pre>';
            $html .= '<div class="svg-spacer"></div>';
        }

        foreach (['ip_quality.log', 'net_quality.log', 'backroute_trace.log'] as $key) {
            if (empty($files[$key]['links'][0])) {
                continue;
            }
            $anchor = $anchorMap[$key] ?? '';
            $link = $files[$key]['links'][0];
            if ($anchor !== '') {
                $html .= '<span id="' . $anchor . '"></span>';
            }
            $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
            $proxyUrl = '/svg-proxy?url=' . rawurlencode($link);
            $html .= '<div class="report-svg-inline" data-svg-url="' . $proxyUrl . '" data-source-url="' . $safeLink . '"></div>';
            $html .= '<div class="svg-spacer"></div>';
        }
        $html .= '</div>';
        $allCopyHtml = htmlspecialchars($allCopy, ENT_QUOTES, 'UTF-8');
        $html .= '<span class="panel-copy" data-copytext="' . $allCopyHtml . '"></span>';
        $html .= '</div>';

        foreach (['basic_info.log'] as $key) {
            if (!isset($files[$key])) {
                continue;
            }
            $active = ($firstTab === $key) ? ' active' : '';
            $html .= '<div class="panel' . $active . '" data-panel="' . $key . '">';
            $panelCopy = '';
            if ($key === 'basic_info.log') {
                $content = ansi_to_html($files[$key]['content'], $ansiColors, $ansiBgColors);
                $panelCopy = strip_ansi($files[$key]['content']);
                $html .= '<div class="terminal"><pre data-raw="' . htmlspecialchars($panelCopy, ENT_QUOTES, 'UTF-8') . '">' . $content . '</pre></div>';
            }
            $panelCopyHtml = htmlspecialchars($panelCopy, ENT_QUOTES, 'UTF-8');
            $html .= '<span class="panel-copy" data-copytext="' . $panelCopyHtml . '"></span>';
            $html .= '</div>';
        }

        $html .= '<script>'
            . 'document.querySelectorAll(".tab").forEach(function(btn){'
            . 'btn.addEventListener("click", function(){'
            . 'document.querySelectorAll(".tab").forEach(function(b){b.classList.remove("active")});'
            . 'document.querySelectorAll(".panel").forEach(function(p){p.classList.remove("active")});'
            . 'btn.classList.add("active");'
            . 'var key=btn.getAttribute("data-tab");'
            . 'var anchorMap={"basic_info.log":"anchor-basic","ip_quality.log":"anchor-ip","net_quality.log":"anchor-net","backroute_trace.log":"anchor-trace"};'
            . 'var panelKey=(key==="basic_info.log"||key==="all.log")?key:"all.log";'
            . 'var panel=document.querySelector(\'[data-panel="\'+panelKey+\'"]\');'
            . 'if(panel){panel.classList.add("active");}'
            . 'if(key!=="all.log"&&anchorMap[key]){var a=document.getElementById(anchorMap[key]);if(a){a.scrollIntoView({behavior:"smooth",block:"start"});}}'
            . '});'
            . '});'
            . 'function copyText(text){'
            . 'if(navigator.clipboard&&window.isSecureContext){return navigator.clipboard.writeText(text);}'
            . 'var ta=document.createElement("textarea");ta.value=text;document.body.appendChild(ta);ta.select();'
            . 'document.execCommand("copy");document.body.removeChild(ta);return Promise.resolve();}'
            . 'function svgToText(svg){'
            . 'var tmp=document.createElement("div");tmp.innerHTML=svg;'
            . 'var text=tmp.textContent||"";'
            . 'text=text.replace(/\\s+$/gm,"");'
            . 'return text.trim();'
            . '}'
            . 'async function loadSvg(el){'
            . 'var url=el.getAttribute("data-svg-url");'
            . 'if(!url)return;'
            . 'try{'
            . 'var res=await fetch(url);'
            . 'if(!res.ok)throw new Error("fetch failed");'
            . 'var svg=await res.text();'
            . 'el.innerHTML=svg;'
            . 'var svgEl=el.querySelector("svg");'
            . 'if(svgEl){'
            . 'svgEl.setAttribute("width","100%");'
            . 'var box=svgEl.getBBox();'
            . 'var h=Math.ceil(box.y+box.height+2);'
            . 'svgEl.setAttribute("height",h+"px");'
            . '}'
            . 'var panel=el.closest(".panel");'
            . 'if(panel){'
            . 'var span=panel.querySelector(".panel-copy");'
            . 'if(span){'
            . 'var current=span.getAttribute("data-copytext")||"";'
            . 'var extra=svgToText(svg);'
            . 'span.setAttribute("data-copytext",(current+"\\n\\n"+extra).trim());'
            . '}'
            . '}'
            . '}catch(e){'
            . 'var src=el.getAttribute("data-source-url")||"";'
            . 'var msg=src?`SVG 加载失败，请点击链接查看：${src}`:"SVG 加载失败，请点击链接查看。";'
            . 'el.innerHTML="<div class=\\"report-fallback\\">"+msg+"</div>";'
            . '}'
            . '}'
            . 'document.querySelectorAll("[data-copy]").forEach(function(btn){'
            . 'btn.addEventListener("click", function(){'
            . 'var mode=btn.getAttribute("data-copy");'
            . 'var text="";'
            . 'if(mode==="all"){'
            . 'var all=document.querySelector(\'[data-panel="all.log"] .panel-copy\');'
            . 'text=all?all.getAttribute("data-copytext"):"";'
            . '}else{'
            . 'var cur=document.querySelector(".panel.active .panel-copy");'
            . 'text=cur?cur.getAttribute("data-copytext"):"";'
            . '}'
            . 'copyText(text||"");'
            . '});'
            . '});'
            . 'document.querySelectorAll(".report-svg-inline").forEach(function(el){loadSvg(el);});'
            . '</script>';
    }

    $html .= '</div></div>'
        . '<aside class="float-contact" aria-label="Telegram联系">'
        . '<a class="float-contact-link" href="https://t.me/hketsp_bot" target="_blank" rel="noopener">'
        . '<span class="float-contact-icon" aria-hidden="true">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        . '<path fill="#ffffff" d="M21.9 3.6c-.3-.3-.7-.4-1.1-.3L2.4 9.9c-.5.2-.8.7-.8 1.2 0 .5.4.9.9 1.1l4.7 1.5 2 6.4c.1.4.5.7.9.8h.1c.4 0 .8-.2 1-.5l2.9-3.6 4.8 3.5c.2.1.4.2.7.2.2 0 .4-.1.6-.2.3-.2.5-.5.6-.8l3.2-16.1c.1-.4 0-.8-.3-1.1zM9.3 13.7l-.6 3.1-1.2-3.8 9.5-6-7.7 6.7z"/>'
        . '</svg>'
        . '</span>'
        . '<span class="float-contact-text">'
        . '<span class="float-contact-title">Telegram</span>'
        . '<span class="float-contact-subtitle">在线咨询</span>'
        . '</span>'
        . '</a>'
        . '</aside>'
        . '</body></html>';
    respond(200, $html, 'text/html; charset=utf-8');
}

respond(404, 'not found');
