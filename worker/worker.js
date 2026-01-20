export default {
  async fetch(request, env, ctx) {
    try {
      return await handleRequest(request, env, ctx);
    } catch (err) {
      return new Response("server error", { status: 500 });
    }
  },
  async scheduled(event, env, ctx) {
    ctx.waitUntil(purgeOldResults(env));
  },
};

const DEFAULT_MAX_BYTES = 50 * 1024 * 1024;

async function handleRequest(request, env, ctx) {
  const url = new URL(request.url);
  const method = request.method.toUpperCase();

  if (method === "POST" && url.pathname === "/api/v1/record") {
    return handleUpload(request, env);
  }

  if (method === "GET" && (url.pathname === "/svg-proxy" || url.searchParams.has("url"))) {
    return handleSvgProxy(url);
  }

  if (method === "GET" && url.pathname.startsWith("/r/")) {
    return handleResult(url, env);
  }

  return new Response("not found", { status: 404 });
}

async function handleUpload(request, env) {
  const maxBytes = Number(env.MAX_BYTES || DEFAULT_MAX_BYTES);
  const baseUrl = env.BASE_URL || `${new URL(request.url).origin}`;

  const raw = await request.text();
  if (raw.length === 0) {
    return new Response("empty payload", { status: 400 });
  }
  if (raw.length > maxBytes * 2) {
    return new Response("payload too large", { status: 413 });
  }

  const clean = raw.replace(/\s+/g, "");
  let zipBytes;
  try {
    zipBytes = base64ToBytes(clean);
  } catch (err) {
    return new Response("invalid base64", { status: 400 });
  }

  if (zipBytes.length === 0) {
    return new Response("empty payload", { status: 400 });
  }
  if (zipBytes.length > maxBytes) {
    return new Response("payload too large", { status: 413 });
  }

  const id = generateId();
  const key = `${id}.zip`;
  await env.RESULTS.put(key, zipBytes, {
    httpMetadata: { contentType: "application/zip" },
    customMetadata: { uploadedAt: String(Date.now()) },
  });

  return new Response(`${baseUrl}/r/${id}`, { status: 200 });
}

async function handleSvgProxy(url) {
  const target = url.searchParams.get("url") || "";
  if (!target) {
    return new Response("missing url", { status: 400 });
  }
  if (!/^https?:\/\/Report\.Check\.Place\/(ip|net)\/[A-Za-z0-9]+\.svg$/.test(target)) {
    return new Response("invalid url", { status: 400 });
  }

  const res = await fetch(target, {
    headers: { "User-Agent": "ETDATA-NodeQuality" },
  });
  if (!res.ok) {
    return new Response("fetch failed", { status: 502 });
  }

  const svg = await res.text();
  return new Response(svg, {
    status: 200,
    headers: { "Content-Type": "image/svg+xml; charset=utf-8" },
  });
}

async function handleResult(url, env) {
  let id = url.pathname.slice("/r/".length);
  if (!id) {
    return new Response("not found", { status: 404 });
  }

  let download = false;
  if (id.endsWith(".zip")) {
    download = true;
    id = id.slice(0, -4);
  }

  if (!/^[0-9]{14}-[a-f0-9]{8}$/.test(id)) {
    return new Response("not found", { status: 404 });
  }

  const obj = await env.RESULTS.get(`${id}.zip`);
  if (!obj) {
    return new Response("not found", { status: 404 });
  }

  if (download) {
    const headers = new Headers({
      "Content-Type": "application/zip",
      "Content-Length": String(obj.size),
    });
    return new Response(obj.body, { status: 200, headers });
  }

  const zipBytes = new Uint8Array(await obj.arrayBuffer());
  const sections = {
    "header_info.log": "Header",
    "basic_info.log": "基本信息",
    "ip_quality.log": "IP质量",
    "net_quality.log": "网络质量",
    "backroute_trace.log": "回程路由",
  };

  const files = await readZipSections(zipBytes, sections);
  const html = buildResultHtml(id, files);
  return new Response(html, {
    status: 200,
    headers: { "Content-Type": "text/html; charset=utf-8" },
  });
}

function generateId() {
  const now = new Date();
  const ts = now.toISOString().replace(/[-:.TZ]/g, "");
  const rnd = crypto.getRandomValues(new Uint8Array(4));
  return `${ts}-${toHex(rnd)}`;
}

function toHex(bytes) {
  return Array.from(bytes)
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

function base64ToBytes(base64) {
  const bin = atob(base64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) {
    out[i] = bin.charCodeAt(i);
  }
  return out;
}

async function readZipSections(zipBytes, sections) {
  const entries = parseZipDirectory(zipBytes);
  const decoder = new TextDecoder("utf-8");
  const files = {};

  for (const [name, label] of Object.entries(sections)) {
    const entry = entries[name];
    if (!entry) continue;
    const raw = await readZipEntry(zipBytes, entry);
    if (!raw) continue;
    const text = normalizeAnsi(decoder.decode(raw));
    files[name] = {
      label,
      content: text,
      links: extractSvgLinks(text),
    };
  }

  return files;
}

function parseZipDirectory(bytes) {
  const eocd = findEocd(bytes);
  if (eocd < 0) return {};

  const cdSize = readU32(bytes, eocd + 12);
  const cdOffset = readU32(bytes, eocd + 16);
  let offset = cdOffset;
  const entries = {};

  while (offset < cdOffset + cdSize) {
    if (readU32(bytes, offset) !== 0x02014b50) break;
    const method = readU16(bytes, offset + 10);
    const compressedSize = readU32(bytes, offset + 20);
    const uncompressedSize = readU32(bytes, offset + 24);
    const nameLen = readU16(bytes, offset + 28);
    const extraLen = readU16(bytes, offset + 30);
    const commentLen = readU16(bytes, offset + 32);
    const localHeaderOffset = readU32(bytes, offset + 42);
    const name = decodeAscii(bytes.slice(offset + 46, offset + 46 + nameLen));
    entries[name] = {
      method,
      compressedSize,
      uncompressedSize,
      localHeaderOffset,
    };
    offset += 46 + nameLen + extraLen + commentLen;
  }

  return entries;
}

function findEocd(bytes) {
  const max = Math.max(0, bytes.length - 65558);
  for (let i = bytes.length - 22; i >= max; i--) {
    if (readU32(bytes, i) === 0x06054b50) {
      return i;
    }
  }
  return -1;
}

async function readZipEntry(bytes, entry) {
  const offset = entry.localHeaderOffset;
  if (readU32(bytes, offset) !== 0x04034b50) return null;
  const nameLen = readU16(bytes, offset + 26);
  const extraLen = readU16(bytes, offset + 28);
  const dataStart = offset + 30 + nameLen + extraLen;
  const dataEnd = dataStart + entry.compressedSize;
  const slice = bytes.slice(dataStart, dataEnd);

  if (entry.method === 0) {
    return slice;
  }
  if (entry.method === 8) {
    return inflateRaw(slice);
  }
  return null;
}

async function inflateRaw(data) {
  if (typeof DecompressionStream === "undefined") {
    throw new Error("deflate-raw not supported");
  }
  const ds = new DecompressionStream("deflate-raw");
  const stream = new Response(data).body.pipeThrough(ds);
  const out = await new Response(stream).arrayBuffer();
  return new Uint8Array(out);
}

function readU16(bytes, offset) {
  return bytes[offset] | (bytes[offset + 1] << 8);
}

function readU32(bytes, offset) {
  return (
    bytes[offset] |
    (bytes[offset + 1] << 8) |
    (bytes[offset + 2] << 16) |
    (bytes[offset + 3] << 24)
  ) >>> 0;
}

function decodeAscii(bytes) {
  let out = "";
  for (let i = 0; i < bytes.length; i++) {
    out += String.fromCharCode(bytes[i]);
  }
  return out;
}

function normalizeAnsi(text) {
  let out = text.replace(/\r/g, "");
  out = out.replace(/\[([0-9]+(?:;[0-9]+)*)m/g, "\x1b[$1m");
  out = out.replace(/\x1b\[[0-9;?]*[HJK]/g, "");
  out = out.replace(/\x1b\[[0-9;?]*[JK]/g, "");
  out = out.replace(/\[[0-9;?]*[HJK]/g, "");
  out = out.replace(/\[[0-9;?]*[JK]/g, "");
  out = out.replace(/^\[[0-9;?]*[HJK].*$/gm, "");
  out = out.replace(/^\[[0-9;?]*[JK].*$/gm, "");
  out = out.replace(/^[ \t]+$/gm, "");
  out = out.replace(/\n{3,}/g, "\n\n");
  return out;
}

function stripAnsi(text) {
  let out = text.replace(/\x1b\[[0-9;]*[A-Za-z]/g, "");
  out = out.replace(/\[[0-9;?]*[A-Za-z]/g, "");
  return out;
}

function extractSvgLinks(text) {
  const links = [];
  const re = /https?:\/\/Report\.Check\.Place\/(?:ip|net)\/[A-Za-z0-9]+\.svg/g;
  let m;
  while ((m = re.exec(text))) {
    if (!links.includes(m[0])) links.push(m[0]);
  }
  return links;
}

function ansiToHtml(text) {
  const colors = {
    30: "#1a1a1a",
    31: "#ff5c57",
    32: "#5af78e",
    33: "#f3f99d",
    34: "#57c7ff",
    35: "#ff6ac1",
    36: "#9aedfe",
    37: "#f1f1f0",
    90: "#808080",
    91: "#ff6e67",
    92: "#5af78e",
    93: "#f3f99d",
    94: "#57c7ff",
    95: "#ff6ac1",
    96: "#9aedfe",
    97: "#ffffff",
  };
  const bgColors = {
    40: "#1a1a1a",
    41: "#ff5c57",
    42: "#5af78e",
    43: "#f3f99d",
    44: "#57c7ff",
    45: "#ff6ac1",
    46: "#9aedfe",
    47: "#f1f1f0",
    100: "#4d4d4d",
    101: "#ff6e67",
    102: "#5af78e",
    103: "#f3f99d",
    104: "#57c7ff",
    105: "#ff6ac1",
    106: "#9aedfe",
    107: "#ffffff",
  };

  const pattern = /\x1b\[[0-9;]*m/g;
  const parts = text.split(pattern);
  const codes = text.match(pattern) || [];
  const style = {
    color: null,
    bg: null,
    bold: false,
    italic: false,
    underline: false,
    dim: false,
  };

  let out = "";
  for (let i = 0; i < parts.length; i++) {
    if (parts[i]) {
      const escaped = escapeHtml(parts[i]);
      const spanStyle = [];
      if (style.color) spanStyle.push(`color:${style.color}`);
      if (style.bg) spanStyle.push(`background-color:${style.bg}`);
      if (style.bold) spanStyle.push("font-weight:700");
      if (style.italic) spanStyle.push("font-style:italic");
      if (style.underline) spanStyle.push("text-decoration:underline");
      if (style.dim) spanStyle.push("opacity:0.85");
      if (spanStyle.length) {
        out += `<span style="${spanStyle.join(";")}">${escaped}</span>`;
      } else {
        out += escaped;
      }
    }
    const code = codes[i];
    if (!code) continue;
    const seq = code.slice(2, -1);
    const items = seq ? seq.split(";") : ["0"];
    for (const item of items) {
      const c = Number(item);
      if (c === 0) {
        style.color = null;
        style.bg = null;
        style.bold = false;
        style.italic = false;
        style.underline = false;
        style.dim = false;
      } else if (c === 1) {
        style.bold = true;
      } else if (c === 2) {
        style.dim = true;
      } else if (c === 3) {
        style.italic = true;
      } else if (c === 4) {
        style.underline = true;
      } else if (c === 22) {
        style.bold = false;
        style.dim = false;
      } else if (c === 23) {
        style.italic = false;
      } else if (c === 24) {
        style.underline = false;
      } else if (c === 39) {
        style.color = null;
      } else if (c === 49) {
        style.bg = null;
      } else if (colors[c]) {
        style.color = colors[c];
      } else if (bgColors[c]) {
        style.bg = bgColors[c];
      }
    }
  }

  return out;
}

function escapeHtml(text) {
  return text
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function buildResultHtml(id, files) {
  const safeId = escapeHtml(id);
  const downloadUrl = `/r/${safeId}.zip`;
  const hasContent = Object.keys(files).length > 0;

  const tabs = {};
  if (files["header_info.log"] || files["basic_info.log"]) {
    const combined = []
      .concat(files["header_info.log"] ? [files["header_info.log"].content] : [])
      .concat(files["basic_info.log"] ? [files["basic_info.log"].content] : [])
      .join("\n\n");
    tabs["all.log"] = { label: "全部", content: combined, links: [] };
  }
  for (const name of ["basic_info.log", "ip_quality.log", "net_quality.log", "backroute_trace.log"]) {
    if (files[name]) tabs[name] = files[name];
  }

  const firstTab = Object.keys(tabs)[0] || "";
  const anchorMap = {
    "basic_info.log": "anchor-basic",
    "ip_quality.log": "anchor-ip",
    "net_quality.log": "anchor-net",
    "backroute_trace.log": "anchor-trace",
  };

  let allCopy = "";
  let panelHtml = "";

  if (tabs["all.log"]) {
    const content = tabs["all.log"].content || "";
    const html = ansiToHtml(content);
    const copy = stripAnsi(content);
    allCopy = copy;
    panelHtml += `<div class="panel${firstTab === "all.log" ? " active" : ""}" data-panel="all.log">`;
    panelHtml += `<div class="terminal"><pre data-raw="${escapeHtml(copy)}">${html}</pre>`;

    for (const key of ["ip_quality.log", "net_quality.log", "backroute_trace.log"]) {
      const link = files[key]?.links?.[0];
      if (!link) continue;
      const anchor = anchorMap[key] || "";
      if (anchor) panelHtml += `<span id="${anchor}"></span>`;
      const proxyUrl = `/svg-proxy?url=${encodeURIComponent(link)}`;
      panelHtml += `<div class="report-svg-inline" data-svg-url="${proxyUrl}" data-source-url="${escapeHtml(link)}"></div>`;
      panelHtml += `<div class="svg-spacer"></div>`;
    }
    panelHtml += `</div>`;
    panelHtml += `<span class="panel-copy" data-copytext="${escapeHtml(allCopy)}"></span>`;
    panelHtml += `</div>`;
  }

  if (files["basic_info.log"]) {
    const key = "basic_info.log";
    const content = files[key].content || "";
    const html = ansiToHtml(content);
    const copy = stripAnsi(content);
    panelHtml += `<div class="panel${firstTab === key ? " active" : ""}" data-panel="${key}">`;
    panelHtml += `<div class="terminal"><pre data-raw="${escapeHtml(copy)}">${html}</pre></div>`;
    panelHtml += `<span class="panel-copy" data-copytext="${escapeHtml(copy)}"></span>`;
    panelHtml += `</div>`;
  }

  const tabHtml = Object.entries(tabs)
    .map(([name, data]) => {
      const active = name === firstTab ? " active" : "";
      return `<button class="tab${active}" data-tab="${name}">${escapeHtml(data.label)}</button>`;
    })
    .join("");

  return `<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>易通数据 - 测试结果</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet" />
  <style>
    :root{--color-primary:#0d1e67;--color-primary-light:#1a2d8a;--color-accent:#2f8bff;--color-accent-hover:#1f6fe0;--color-text:#dbe4ff;--color-text-muted:#98a3c7;--color-border:rgba(255,255,255,.1);--radius-lg:1rem;--radius-md:.5rem;--shadow-lg:0 16px 32px rgba(0,0,0,.35);}
    body{margin:0;font-family:Roboto,-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;color:var(--color-text);background:radial-gradient(1200px 600px at 20% -10%, #1b2d8c 0%, rgba(13,30,103,0) 60%),linear-gradient(135deg,var(--color-primary) 0%,var(--color-primary-light) 100%);min-height:100vh;}
    .hero{padding:40px 0 16px;text-align:center;color:#fff;}
    .hero h1{margin:0;font-size:2.2rem;font-weight:700;}
    .hero p{margin:8px 0 0;opacity:.9;}
    .container{max-width:980px;margin:0 auto;padding:0 16px 48px;}
    .card{background:rgba(9,14,34,.7);border:1px solid rgba(255,255,255,.08);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);padding:20px;backdrop-filter:blur(6px);}
    .meta{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:12px;}
    .meta .id{font-weight:600;color:#cbd6ff;}
    .actions{display:flex;gap:8px;flex-wrap:wrap;}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;background:var(--color-accent);color:#fff;text-decoration:none;font-weight:600;font-size:14px;}
    .btn:hover{background:var(--color-accent-hover);}
    .btn-secondary{background:rgba(255,255,255,.12);color:#e6ecff;border:1px solid rgba(255,255,255,.2);}
    .btn-secondary:hover{background:rgba(255,255,255,.2);}
    .tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;}
    .tab{padding:6px 12px;border-radius:999px;border:1px solid var(--color-border);background:rgba(255,255,255,.06);color:#e6ecff;cursor:pointer;font-weight:600;font-size:13px;}
    .tab.active{background:#0f1a4d;color:#fff;border-color:#2f8bff;}
    .panel{display:none;}
    .panel.active{display:block;}
    .report-svg-inline{margin:6px 0;}
    .svg-spacer{height:2.6em;}
    .report-svg-inline svg{width:100%;height:auto;display:block;border-radius:8px;background:#0b1024;}
    .report-fallback{color:#98a3c7;font-size:13px;}
    .terminal{background:#0b1024;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px;max-height:70vh;overflow:auto;resize:vertical;}
    pre{margin:0;font-family:"Noto Sans Mono","Noto Sans Symbols2","Segoe UI Symbol",ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:13px;line-height:1.45;color:#dbe4ff;white-space:pre;}
    .float-contact{position:fixed;right:max(16px, env(safe-area-inset-right));bottom:max(120px, env(safe-area-inset-bottom));z-index:1000;max-width:calc(100vw - 24px);}
    .float-contact-link{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:999px;background:linear-gradient(135deg,#0f7ae5 0%,#0d1e67 100%);color:#fff;box-shadow:0 10px 24px rgba(13,30,103,.35);text-decoration:none;transition:transform 200ms ease, box-shadow 200ms ease;}
    .float-contact-link:hover{transform:translateY(-3px);box-shadow:0 14px 28px rgba(13,30,103,.45);}
    .float-contact-icon{width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.18);display:grid;place-items:center;}
    .float-contact-text{display:flex;flex-direction:column;line-height:1.1;}
    .float-contact-title{font-size:.95rem;font-weight:700;letter-spacing:.02em;}
    .float-contact-subtitle{font-size:.75rem;opacity:.85;}
    @media (max-width:640px){.float-contact{right:max(12px, env(safe-area-inset-right));bottom:max(96px, env(safe-area-inset-bottom));}.float-contact-link{padding:10px 12px;gap:10px;}.float-contact-icon{width:38px;height:38px;}.float-contact-title{font-size:.85rem;}}
    .empty{padding:20px;text-align:center;color:var(--color-text-muted);}
  </style>
</head>
<body>
  <section class="hero">
    <h1>易通数据 · 测试结果</h1>
    <p>ETDATA NodeQuality Report</p>
    <p class="hero-tagline">易通数据 · 全球 IDC 与 CDN 资源整合服务商，7×24 小时人工技术支持</p>
  </section>
  <div class="container">
    <div class="card">
      <div class="meta">
        <div class="id">结果ID：${safeId}</div>
        <div class="actions">
          <button class="btn btn-secondary" data-copy="all">复制全部</button>
          <button class="btn btn-secondary" data-copy="current">复制当前</button>
          <a class="btn btn-secondary" href="${downloadUrl}">下载ZIP</a>
        </div>
      </div>
      ${hasContent ? `<div class="tabs">${tabHtml}</div>${panelHtml}` : `<div class="empty">Result parsed failed. Please download the ZIP.</div>`}
    </div>
  </div>
  <aside class="float-contact" aria-label="Telegram联系">
    <a class="float-contact-link" href="https://t.me/hketsp_bot" target="_blank" rel="noopener">
      <span class="float-contact-icon" aria-hidden="true">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path fill="#ffffff" d="M21.9 3.6c-.3-.3-.7-.4-1.1-.3L2.4 9.9c-.5.2-.8.7-.8 1.2 0 .5.4.9.9 1.1l4.7 1.5 2 6.4c.1.4.5.7.9.8h.1c.4 0 .8-.2 1-.5l2.9-3.6 4.8 3.5c.2.1.4.2.7.2.2 0 .4-.1.6-.2.3-.2.5-.5.6-.8l3.2-16.1c.1-.4 0-.8-.3-1.1zM9.3 13.7l-.6 3.1-1.2-3.8 9.5-6-7.7 6.7z"/>
        </svg>
      </span>
      <span class="float-contact-text">
        <span class="float-contact-title">Telegram</span>
        <span class="float-contact-subtitle">在线咨询</span>
      </span>
    </a>
  </aside>
  <script>
    document.querySelectorAll(".tab").forEach(function(btn){
      btn.addEventListener("click", function(){
        document.querySelectorAll(".tab").forEach(function(b){b.classList.remove("active")});
        document.querySelectorAll(".panel").forEach(function(p){p.classList.remove("active")});
        btn.classList.add("active");
        var key=btn.getAttribute("data-tab");
        var anchorMap={"basic_info.log":"anchor-basic","ip_quality.log":"anchor-ip","net_quality.log":"anchor-net","backroute_trace.log":"anchor-trace"};
        var panelKey=(key==="basic_info.log"||key==="all.log")?key:"all.log";
        var panel=document.querySelector('[data-panel="'+panelKey+'"]');
        if(panel){panel.classList.add("active");}
        if(key!=="all.log"&&anchorMap[key]){var a=document.getElementById(anchorMap[key]);if(a){a.scrollIntoView({behavior:"smooth",block:"start"});}}
      });
    });
    function copyText(text){
      if(navigator.clipboard&&window.isSecureContext){return navigator.clipboard.writeText(text);}
      var ta=document.createElement("textarea");ta.value=text;document.body.appendChild(ta);ta.select();
      document.execCommand("copy");document.body.removeChild(ta);return Promise.resolve();
    }
    function svgToText(svg){
      var tmp=document.createElement("div");tmp.innerHTML=svg;
      var text=tmp.textContent||"";text=text.replace(/\\s+$/gm,"");
      return text.trim();
    }
    async function loadSvg(el){
      var url=el.getAttribute("data-svg-url");
      if(!url)return;
      try{
        var res=await fetch(url);
        if(!res.ok)throw new Error("fetch failed");
        var svg=await res.text();
        el.innerHTML=svg;
        var svgEl=el.querySelector("svg");
        if(svgEl){
          svgEl.setAttribute("width","100%");
          var style=document.createElement("style");
          style.textContent="text, tspan { font-family: \\"SimHei\\", \\"Consolas\\", \\"DejaVu Sans Mono\\", \\"SF Mono\\", monospace; letter-spacing: 0; }";
          svgEl.insertBefore(style, svgEl.firstChild);
          var box=svgEl.getBBox();
          var h=Math.ceil(box.y+box.height+2);
          svgEl.setAttribute("height",h+"px");
        }
        var panel=el.closest(".panel");
        if(panel){
          var span=panel.querySelector(".panel-copy");
          if(span){
            var current=span.getAttribute("data-copytext")||"";
            var extra=svgToText(svg);
            span.setAttribute("data-copytext",(current+"\\n\\n"+extra).trim());
          }
        }
      }catch(e){
        var src=el.getAttribute("data-source-url")||"";
        var msg=src?("SVG 加载失败，请点击链接查看："+src):"SVG 加载失败，请点击链接查看。";
        el.innerHTML="<div class=\\"report-fallback\\">"+msg+"</div>";
      }
    }
    document.querySelectorAll("[data-copy]").forEach(function(btn){
      btn.addEventListener("click", function(){
        var mode=btn.getAttribute("data-copy");
        var text="";
        if(mode==="all"){
          var all=document.querySelector('[data-panel="all.log"] .panel-copy');
          text=all?all.getAttribute("data-copytext"):"";
        }else{
          var cur=document.querySelector(".panel.active .panel-copy");
          text=cur?cur.getAttribute("data-copytext"):"";
        }
        copyText(text||"");
      });
    });
    document.querySelectorAll(".report-svg-inline").forEach(function(el){loadSvg(el);});
  </script>
</body>
</html>`;
}

async function purgeOldResults(env) {
  if (!env.RESULTS) return;
  const now = Date.now();
  const cutoff = now - 24 * 60 * 60 * 1000;
  let cursor;
  do {
    const list = await env.RESULTS.list({ cursor, limit: 1000 });
    for (const obj of list.objects) {
      const ts = parseIdTimestamp(obj.key);
      if (ts && ts < cutoff) {
        await env.RESULTS.delete(obj.key);
      }
    }
    cursor = list.cursor;
  } while (cursor);
}

function parseIdTimestamp(key) {
  const match = key.match(/^(\d{14})-[a-f0-9]{8}\.zip$/);
  if (!match) return null;
  const ts = match[1];
  const year = Number(ts.slice(0, 4));
  const month = Number(ts.slice(4, 6)) - 1;
  const day = Number(ts.slice(6, 8));
  const hour = Number(ts.slice(8, 10));
  const min = Number(ts.slice(10, 12));
  const sec = Number(ts.slice(12, 14));
  return Date.UTC(year, month, day, hour, min, sec);
}
