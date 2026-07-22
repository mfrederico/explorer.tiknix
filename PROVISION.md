# Architecture Explorer — provisioning (Phase 0/1)

The Explorer is a **lean sidecar app** that reuses core tiknix's `vendor/` and shared
classes (Introspector, ExplorerToken, Bean, FlightMap, functions) but has its OWN
controllers, its own SQLite db, and a read-only handle to core's db. Phase 0/1 is
built and tested end-to-end (SSO, owner/team scoping, the authcontrol grid, the
`select *` drill). Going live needs the steps below — they are **operator/infra**
tasks (nginx + DNS + the shared secret), not code.

## What's built (this directory)
- `explorer-init.php` — sidecar bootstrap (sidecar-first autoloader → core fallback;
  own sqlite + read-only core handle; explicit routes to the sidecar's controllers).
- `public/index.php` — front controller with the **hard allowlist gate** (only
  `sso`/`explore`/`index`/`error` may run; everything else 403s pre-dispatch).
- `controls/{Sso,Explore,Index}.php`, `lib/{ExplorerAccess,ExplorerGraph}.php`,
  `views/explore/index.php`, `views/error/{404,500}.php`.
- Security posture already exceeds the plan: the sidecar **does not contain** core's
  other controllers at all (only its own 3), so the gate is defense-in-depth, not the
  only line.

## Steps to go live
1. **Shared SSO secret.** Set `[explorer] sso_secret` to the SAME value in BOTH
   `/var/www/html/default/tiknix/conf/config.ini` and this app's `conf/config.ini`.
   Core already has one generated (gitignored). Copy it here:
   ```
   php -r '$c=parse_ini_file("/var/www/html/default/tiknix/conf/config.ini",true);echo $c["explorer"]["sso_secret"],"\n";'
   ```
   Also set core's `[explorer] url = "https://explorer.tiknix.com"`.

2. **Sidecar config.** `cp conf/config.example.ini conf/config.ini` and set:
   `sso_secret` (from step 1), `core_root=/var/www/html/default/tiknix`,
   `core_url=https://tiknix.com`, `baseurl=https://explorer.tiknix.com`. `config.ini`
   and `data/` are gitignored.

3. **nginx vhost** for `explorer.tiknix.com`, docroot **`public/`**, front-controller
   pattern (mirror the core server block). Critically, run PHP with the docroot at
   `public/` so `SCRIPT_NAME=/index.php` — Flight's base detection needs that (a
   `php -S router.php` harness mangles multi-segment routes; nginx/fpm does not):
   ```nginx
   server {
     server_name explorer.tiknix.com;
     root /var/www/html/default/explorer.tiknix/public;
     index index.php;
     location / { try_files $uri $uri/ /index.php$is_args$args; }
     location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php-fpm.sock;
                         fastcgi_param SCRIPT_FILENAME $document_root/index.php; }
     # TLS via your usual cert block
   }
   ```

4. **DNS.** `explorer.tiknix.com` → this host.

5. **Grant access** per member (the allowlist): on core, an admin toggles
   **Architecture Explorer** on the member's Edit Member page (the `explorer` feature
   flag, min_level 100), or `Feature::setEnabled('explorer', true, $memberId)`.

6. **Done.** A granted member sees "Architecture Explorer" in the core nav → clicks it
   → `Explorer::launch` mints a token → sidecar `Sso::consume` verifies + establishes a
   session → the grid renders for instances they own or share via a team. The sidecar's
   `data/explorer.db` (sessions/nonces/graph cache) auto-creates on first run.

## Notes
- The graph cache (`explorergraph` bean) invalidates by a content hash of the target
  instance's code + authcontrol shape, so a `git pull` or a permission seed there
  refreshes the model on the next view. A cache row is pruned when its hash changes.
- Phases 2–4 (call graph + SVG, AI Q&A, surgical tasks) build on this — see
  `EXPLORER-PLAN.md` and `CALLGRAPH-DESIGN.md`.
