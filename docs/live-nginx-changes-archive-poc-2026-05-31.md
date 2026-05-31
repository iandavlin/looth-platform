# Live nginx changes (dev) — 2026-05-31, NOT yet reconciled into repo copies

The deployed `/etc/nginx/snippets/strangler-archive-poc.conf` has two changes the
repo copies (`archive-poc/deploy/archive-poc.nginx-snippet.conf`,
`archive-poc/nginx-snippet.conf`) lack. Those copies are drifted (they reference
`/srv/archive-poc/` paths vs the live `/home/ubuntu/projects/archive-poc/`), so these
were applied **live-only**; fold them in at the next nginx-snippet reconciliation.

## 1. Loopback endpoints 502 fix (`_sync` + `_materialize`)
Both `location = /archive-api/v0/_sync.php` and `…/_materialize.php`:
- `include snippets/fastcgi-php.conf;` → `include fastcgi.conf;` (drop the alias-incompatible `try_files`)
- `fastcgi_param SCRIPT_FILENAME $request_filename;` → absolute path
  (`/home/ubuntu/projects/archive-poc/api/v0/_{sync,materialize}.php`)

Cause: `try_files` / `$request_filename` mis-resolve under the parent `alias` → fast 502.
This endpoint is what the save-hook dispatches to, so it gates save-triggered re-bake.

## 2. FE-edit handoff (`?lg_edit=1` → WordPress)
Added to each intercepted CPT permalink location (post-imgcap, loothprint, loothcuts,
useful_links, member-benefit, document), right after the 403 gate line:

    if ($arg_lg_edit) { rewrite ^ /index.php last; }   # FE-edit -> WP

Routes edit requests to WP's front controller (WP resolves the same permalink from
`REQUEST_URI` → plugin FE editor + capability check); read-only requests render
standalone. Verified: `?lg_edit=1` → WP (`/wp-json/` markers, no standalone wrapper),
no flag → standalone.
