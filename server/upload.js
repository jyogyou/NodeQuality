#!/usr/bin/env node
"use strict";

const http = require("http");
const fs = require("fs");
const path = require("path");
const { randomBytes } = require("crypto");

const PORT = Number(process.env.PORT || 8080);
const OUTPUT_DIR = process.env.OUTPUT_DIR || path.join(__dirname, "data");
const BASE_URL = process.env.BASE_URL || "https://test.etdata.link";
const MAX_BYTES = Number(process.env.MAX_BYTES || 50 * 1024 * 1024);

fs.mkdirSync(OUTPUT_DIR, { recursive: true });

function send(res, status, body, contentType = "text/plain") {
  res.writeHead(status, { "Content-Type": contentType });
  res.end(body);
}

function generateId() {
  const ts = new Date().toISOString().replace(/[-:.TZ]/g, "");
  const rnd = randomBytes(4).toString("hex");
  return `${ts}-${rnd}`;
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`);

  if (req.method === "POST" && url.pathname === "/api/v1/record") {
    let size = 0;
    const chunks = [];

    req.on("data", (chunk) => {
      size += chunk.length;
      if (size > MAX_BYTES) {
        send(res, 413, "payload too large");
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });

    req.on("end", () => {
      try {
        const raw = Buffer.concat(chunks).toString("utf8");
        const clean = raw.replace(/\s+/g, "");
        const zip = Buffer.from(clean, "base64");
        if (!zip.length) {
          send(res, 400, "empty payload");
          return;
        }

        const id = generateId();
        const filePath = path.join(OUTPUT_DIR, `${id}.zip`);
        fs.writeFileSync(filePath, zip);

        const resultUrl = `${BASE_URL}/r/${id}`;
        send(res, 200, resultUrl);
      } catch (err) {
        send(res, 500, "server error");
      }
    });

    req.on("error", () => {
      send(res, 500, "server error");
    });

    return;
  }

  if (req.method === "GET" && url.pathname.startsWith("/r/")) {
    let id = url.pathname.slice("/r/".length);
    if (!id) {
      send(res, 404, "not found");
      return;
    }

    let download = false;
    if (id.endsWith(".zip")) {
      download = true;
      id = id.replace(/\.zip$/, "");
    }

    const zipPath = path.join(OUTPUT_DIR, `${id}.zip`);
    if (!fs.existsSync(zipPath)) {
      send(res, 404, "not found");
      return;
    }

    if (download) {
      const stat = fs.statSync(zipPath);
      res.writeHead(200, {
        "Content-Type": "application/zip",
        "Content-Length": stat.size,
      });
      fs.createReadStream(zipPath).pipe(res);
      return;
    }

    const html = `<!doctype html>
<html lang="en">
<meta charset="utf-8" />
<title>ETDATA - Test Result</title>
<body style="font-family: sans-serif; padding: 24px;">
  <h1>ETDATA - Test Result</h1>
  <p>Result ID: ${id}</p>
  <p><a href="/r/${id}.zip">Download ZIP</a></p>
</body>
</html>`;
    send(res, 200, html, "text/html; charset=utf-8");
    return;
  }

  send(res, 404, "not found");
});

server.listen(PORT, () => {
  console.log(`ETDATA upload server listening on :${PORT}`);
});
