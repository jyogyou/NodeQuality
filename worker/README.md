# Worker Branch (Cloudflare)

This branch adds a Cloudflare Worker implementation alongside the PHP server.
The PHP flow stays untouched; the Worker is an optional parallel entry at
`run.etdata.link` with equivalent routes.

## Endpoints

- `POST /api/v1/record` -> upload base64 zip, returns `https://run.etdata.link/r/<id>`
- `GET /r/<id>` -> render result page
- `GET /r/<id>.zip` -> download zip
- `GET /svg-proxy?url=...` -> proxy SVG from Report.Check.Place

## Storage

- R2 is used to store ZIP results.
- Objects are deleted daily by the scheduled event (older than 24 hours).

## Wrangler Example

Create `worker/wrangler.toml`:

```
name = "nodequality-worker"
main = "worker.js"
compatibility_date = "2024-11-15"

routes = [
  { pattern = "run.etdata.link/*", zone_name = "etdata.link" }
]

[[r2_buckets]]
binding = "RESULTS"
bucket_name = "nodequality-results"

[vars]
BASE_URL = "https://run.etdata.link"
MAX_BYTES = "52428800"
```

## Scheduled Cleanup

Add a cron trigger (daily):

```
[[triggers]]
crons = [ "0 3 * * *" ]
```

## Manual Setup (Cloudflare Dashboard)

1) Workers & Pages -> Create Worker -> name it (e.g. `nodequality-worker`).
2) Paste `worker/worker.js` into the editor and deploy.
3) Settings -> Domains & Routes -> add route `run.etdata.link/*`.
4) Settings -> Bindings -> R2:
   - Create bucket (e.g. `nodequality-results`).
   - Bind name `RESULTS` to that bucket.
5) Settings -> Variables:
   - `BASE_URL = https://run.etdata.link`
   - `MAX_BYTES = 52428800`
6) Settings -> Triggers -> Cron:
   - `0 3 * * *` (daily cleanup).

## Notes

- ZIP parsing uses `DecompressionStream("deflate-raw")`. If your runtime does
  not support it, replace `inflateRaw()` with a JS inflate library such as
  `fflate`.
- ANSI to HTML rendering is implemented in Worker to match the PHP output.
