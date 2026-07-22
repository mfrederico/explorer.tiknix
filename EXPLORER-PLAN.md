# Architecture Explorer — Phased Implementation Plan

Sidecar app: `explorer.tiknix` → https://explorer.tiknix.com/
Core app: `/var/www/html/default/tiknix` (the control plane).
All file pointers below are relative to the core repo unless prefixed.

---

## 1. Executive summary

The Architecture Explorer is a **gated, SSO'd sidecar** that renders a live, cached,
single-page "development sitemap" of any tiknix instance the requesting member is
allowed to see: authcontrol routes grouped by control across the top (spreadsheet
keyboard traversal), drill into a control → its data rows (`select *`), drill into a
method → **who calls it** (view / script / lib), and a cross-referenced data-model
graph. From any node the member can ask an AI grounded on the cached graph, or kick
off a **surgical fix task** that lands in the existing Workbench plan pipeline.

**Reuse thesis:** roughly 70% of the engine already exists. `mcptools/Introspector.php`
already produces controllers+routes+levels, models+columns+relations, lib methods,
authcontrol rows, and config sections from *any* root passed to its constructor
(`Introspector.php:20-24` — `PlanRunner::codebaseDigest()` at `lib/PlanRunner.php:275-286`
already points it at foreign instance roots). Feature gating (`lib/Feature.php`),
signed-token SSO (`controls/Aibuilder.php:78-88` + `lib/OAuthStateService.php`),
instance ownership (`lib/TaskAccessControl.php:407-435`), and the surgical-task
pipeline (`PlanRunner → SubmitPlanTool → PlanIngestor → PlanExecutor → AuditRunner`)
are all existing primitives. The **net-new crux is the call/render cross-reference
graph** ("what in the system calls this method"), which Introspector deliberately
does not do today — it never reads method *bodies*, only signatures.

**Single biggest technical risk:** call-graph accuracy. A static scanner over PHP +
view templates will be very good on this codebase (FlightPHP auto-routes
`/control/method`, house style uses literal `$this->render('x/y')` and literal URL
strings) but can never be complete for dynamically built URLs/dispatch. Mitigation:
every edge carries `path:line` evidence + a confidence class, and the UI shows
provenance instead of pretending omniscience.

**Hard security constraint (non-negotiable):** the Explorer can only ever see
instances the requesting member **owns or shares a team with** — never "all
instances on the box." Enforced server-side on every request via the exact
ownership model the Workbench already uses (`TaskAccessControl::canAccessInstance`,
`lib/TaskAccessControl.php:422-425`). Fail closed: unknown/unowned slug → 403.

---

## 2. Architecture

### 2.1 Sidecar shape — a provisioned-instance-style clone, hardened by seeds

`explorer.tiknix/` becomes a **git clone of tiknix** (exactly like AI Builder
instances at `/var/www/html/default/<slug>.<app>` — see `models/Model_Instance.php:25-27`),
NOT a hand-pruned fork. Rationale: the provisioning machinery, bootstrap
(`bootstrap.php` flattens config into Flight `section.key` keys — see
`lib/EncryptionService.php:81-84` for the consumption pattern), Flight auto-routing,
`controls/BaseControls/Control.php` (`render()` :65, `getParam()` :145), Bean/RedBean
setup, and layouts all come for free, and updates are `git pull`, not a port.

Hardening is **configuration + seeds**, mirroring how sandbox instances already
disable builder tooling:

- `conf/config.ini` `[app] builder_tools_enabled = false` (`lib/functions.php:53-58`)
  and `control_plane_host = tiknix.com` so `is_control_plane()` (`lib/functions.php:36-42`)
  is false — no nested provisioning, no AI Builder, no Workbench UI on the sidecar.
- **Allowlist gate (CORRECTED — a missing row is NOT a deny).** ⚠️ Original assumption
  was wrong: `PermissionCache::check()` **defaults an undefined route to PUBLIC**
  (`lib/PermissionCache.php:123-125` — `return $userLevel <= LEVELS['PUBLIC']`), and
  under `build_mode` it *auto-creates* the row and allows (`:116-120`). Because the
  sidecar is a full tiknix clone, EVERY core controller file is present, so "seed only
  explorer rows and leave the rest unrouted" would leave dozens of controllers
  guest-reachable (only those with their own constructor `requireLogin`/`requireLevel`
  guard would bounce, and to a login route the sidecar doesn't even have). Deny must be
  **active**, via an explicit allowlist enforced two ways:
  (1) a tiny front-controller allowlist check (in the sidecar's `public/index.php` or a
  Flight `before` filter) that 403s any `control` not in `{sso, explore, index, error}`
  BEFORE dispatch — the authoritative gate, independent of authcontrol rows; and
  (2) `build_mode = false` in the sidecar config so no route ever auto-provisions.
  Seed explorer's own rows (`sso::*`=101, `explore::*`=100, `index::index`=101→redirect
  to core) following `services/Schema/Seeds/02_AuthControl.php`'s `[control, method,
  level, description]` pattern, but treat them as convenience, not the security boundary.
  Belt-and-suspenders: the provisioning step should also *delete the non-explorer
  controller files* from the sidecar clone so an allowlist miss can't reach real code.
  Cache reset via `scripts/resetcache.php` / `lib/PermissionCache.php` (APCu + version
  file) works unchanged.
- The sidecar has **no member table use for identity** — no registration/login
  routes are seeded. Identity arrives only via SSO (below).

New sidecar-only files (the whole net-new controller surface):

| File | Purpose |
|---|---|
| `controls/Sso.php` | `consume` (validate handoff token, establish session), `logout` |
| `controls/Explore.php` | `index` (the single page), `graph`, `rows`, `node`, `ask`, `askstatus`, `fix` (JSON) |
| `lib/ExplorerAccess.php` | thin wrapper binding TaskAccessControl queries to the **core** DB |
| `lib/ExplorerGraph.php` | build/cache/invalidate the graph (drives Introspector + CallGraph) |
| `lib/AskRunner.php` | read-only headless Q&A session (PlanRunner-derived) |
| `mcptools/CallGraph.php` | the call/render cross-reference scanner (also lands in CORE — see §3) |
| `views/explore/index.php` | the wide single page, inline CSS/JS |

### 2.2 Databases — sidecar-owned writes, core reads read-only

- **Primary RedBean connection**: the sidecar's own SQLite (`explorer.tiknix/data/`),
  holding `explorergraph`, `explorerask`, `ssonNonce` beans and PHP sessions. RedBean
  auto-creates tables on first store (no CREATE TABLE), per CLAUDE.md.
- **Core identity DB, read-only**: `R::addDatabase('core', 'sqlite:<core>/…', …)` (path
  from sidecar `conf/config.ini [explorer] core_root`), used ONLY for: `member`
  (status/level re-check), `settings` (the `feature.explorer` row), `instance`,
  `teammember`, `instance_team`. `ExplorerAccess` reproduces, verbatim, the queries in
  `lib/TaskAccessControl.php` `getMemberTeamIds()` (:278-287, note the mandatory
  `array_values()` — the id-keyed find()→IN() binding trap), `getSharedInstanceIds()`
  (:394-400), `getAccessibleInstanceIds()` (:407-414), `canAccessInstance()` (:422-425),
  `ownsInstance()` (:432-435). Same shape as `Aibuilder::accessibleInstance()`
  (`controls/Aibuilder.php:106-124`). Opened with PDO read-only semantics; the sidecar
  never writes core's DB (data & permission changes ship as seeds only).

### 2.3 SSO handshake — core mints, explorer validates (AI Builder token pattern)

Precedent: `Aibuilder::mintToken()` (`controls/Aibuilder.php:78-88`) — payload =
`b64url(json)` + `.` + `HMAC-SHA256(payload, shared secret)`, TTL 120s, secret in
`conf/aibuilder.ini [token]` which "MUST match … in the bridge env files"
(`conf/aibuilder.ini:1-13`). Envelope mechanics identical to
`lib/OAuthStateService.php` `issue()/verify()` (:24-45: iat/exp/nonce claims,
`hash_equals`).

**Use a dedicated shared secret** — `[explorer] sso_secret` in both apps' configs —
rather than deriving from `[security] app_key` (`EncryptionService::deriveKey`,
`lib/EncryptionService.php:109-114`): the two vhosts are separate apps with separate
`app_key`s, and the aibuilder precedent (secret mirrored into each consumer's config)
is the established pattern on this box.

```
CORE (tiknix.com)                                EXPLORER (explorer.tiknix.com)
─────────────────                                ──────────────────────────────
[1] Member clicks "Architecture Explorer"
    (nav link shown only when
     Feature::isEnabled('explorer'))
[2] GET /explorer/launch
    - requireLogin()
    - Feature::isEnabled('explorer', id, level)   ← gate #1 (feature flag)
    - claims = {member_id, level, email,
        feature:'explorer', aud:'explorer',
        iat, exp: now+120, nonce}
    - token = b64url(json).hmac_sha256(secret)
[3] 302 → https://explorer.tiknix.com
            /sso/consume?token=…      ─────────▶ [4] Sso::consume
                                                   - verify HMAC (hash_equals), exp,
                                                     aud === 'explorer'
                                                   - nonce single-use (ssonNonce bean;
                                                     replay → 403)
                                                   - core-DB re-check (read-only):
                                                     member active, level unchanged,
                                                     settings row feature.explorer='1'
                                                   - session_regenerate_id();
                                                     $_SESSION = {member_id, level}
                                                 [5] 302 → /explore
Every later request:                             [6] Explore::* : session member_id is
                                                     the ONLY identity; ownership gate
                                                     (§2.4) runs per request. Feature
                                                     flag re-checked hourly per session
                                                     so revocation propagates.
```

The reverse direction (explorer → core, for surgical tasks) signs with the same
secret and an `aud:'core'` claim — see §6.

### 2.4 Reaching a target instance's code + data — RECOMMENDED: direct read-only filesystem access, behind the ownership gate

Options weighed:

- **(a) Direct filesystem read (RECOMMENDED).** Explorer runs on the same box; every
  instance lives at `/var/www/html/default/<slug>.<app>` (`Model_Instance::dir()`,
  `models/Model_Instance.php:25-27`). `Introspector` was *built* for this: its
  constructor takes an arbitrary `$root` and opens that instance's own SQLite
  read-only (`mcptools/Introspector.php:20-36`), and core already does exactly this
  cross-root read in `PlanRunner::codebaseDigest()` (`lib/PlanRunner.php:275-286`).
  Zero new network surface, zero deployment into instances, works on stale clones.
- **(b) Per-instance MCP introspection endpoint called with a broker token.** Rejected
  as the primary path: it requires new endpoints deployed into every instance clone
  (old clones won't have them), rides the jail-hairpin network path that is a known
  open gate in the connector architecture, and the broker key model
  (`lib/BrokerService.php:1-12`) is scoped *instance → its own stores*, not
  *explorer → instance*. It buys process isolation we can get more cheaply.
- **(c) Hybrid.** Deferred. If explorer ever runs on a different box, (b)'s shape
  (broker-keyed `tools/call` against `controls/Mcp.php`, hash-stored keys per
  `Mcp.php:1762-1766`) is the migration path; the `ExplorerGraph` interface is kept
  transport-agnostic so this swap stays local.

**The security boundary for (a) — every introspection request, no exceptions:**

1. Client sends a URL (or picks from a list). Server parses host →
   `<slug>.<app>.com`; slug validated `^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`.
2. Resolve to the `instance` bean **in the core DB** (`slug` + `app` match). No bean
   → 403. The filesystem path is then constructed ONLY from the bean's own
   slug/app (the `Model_Instance::dir()` recipe) — **never from client input** — so
   there is no path a member can type that reaches a directory without a bean.
3. `ExplorerAccess::canAccessInstance($sessionMemberId, $instanceId)` — owner OR
   team-shared via `instance_team ⋈ teammember` (the `TaskAccessControl.php:422-425`
   / `Aibuilder.php:106-124` check, byte-for-byte the same SQL). False → 403.
   The instance-picker list itself is built from `getAccessibleInstanceIds()` — the
   member never even sees un-owned slugs.
4. Only then: `new Introspector($instanceDir)` + `CallGraph` scan + row reads. All
   reads; the explorer never writes an instance's tree or DB.
5. Entering `/` (or no URL) scopes to the member's **default accessible instance**,
   never to the core control-plane repo itself (core introspection is ROOT-only,
   flag-gated separately).

---

## 3. The introspection & cross-reference engine (the crux)

### 3.1 REUSE — what `Introspector` already provides (point it at the instance root)

| Capability | Where | Status |
|---|---|---|
| Controllers + public methods + line numbers | `mcptools/Introspector.php:214-235` (regex `public function` per line) | REUSE |
| Route + effective level (`method` row falling back to `*` wildcard) | `:306-317` `routeLevel()` | REUSE |
| All authcontrol rows (the top ribbon's data) | `:320-329` `authcontrolRows()` | REUSE (widen from private, or add a public accessor) |
| Models + tables | `:238-247` | REUSE |
| Live columns via `PRAGMA table_info` | `:272-281` | REUSE |
| FK relations inferred from `*_id` → existing table | `:284-292` `relations()` | REUSE (this IS the data-model graph's edge set) |
| Lib classes + public methods | `:250-263` | REUSE |
| Config sections, seed script inventory | `:265-268`, `:332-336` | REUSE |
| `map()/describe()/whatprovides()/digest()` | `:41-209` | REUSE (describe() powers the node inspector; digest() grounds the AI) |

### 3.2 What Introspector **cannot** do today (and what to ADD)

Introspector reads *signatures only* — "answers structural questions WITHOUT loading
file bodies" (`Introspector.php:2-8`). It has: no method bodies, no render targets,
no caller cross-reference, no view inventory, no `routes/` custom-route parsing, no
row browsing, and no persistent cache (per-request memoization only, `:213,237,249`).

**ADD `mcptools/CallGraph.php`** (namespace `app\mcptools`, same zero-dependency,
jail-safe style — this lands in **CORE** so every instance clone inherits it and the
MCP tools can expose it; the sidecar `require`s it from the target instance tree or
its own clone, whichever is newer). Five edge extractors:

1. **Method-body spans** — `token_get_all()` per controller/lib file (stdlib, exact,
   comment/string-safe — strictly better than regex for spans; Introspector's
   line-regex approach stays for signatures). Yields `[class::method => [startLine,
   endLine, tokenSlice]]`.
2. **route → view edges** — inside each controller method span, match
   `$this->render('x/y')` (`BaseControls/Control.php:65` renders `views/x/y.php`),
   plus `Flight::redirect('/a/b')`, `Flight::jsonSuccess/jsonError` (marks the route
   as JSON/endpoint-shaped, no view). Literal-string args only; variable args emit a
   `dynamic-render` node flagged low-confidence.
3. **view → route edges** (the "which view calls this endpoint" answer) — scan
   `views/**/*.php` for quoted strings matching `#^/([a-z][a-z0-9]*)/([a-z][a-z0-9_]*)#`
   in `href=`, `action=`, `fetch(`, `$.ajax/post/get`, `window.location`, validated
   against the known controller::method set (so `/path/to/file` noise is dropped).
   Each edge carries `views/foo/bar.php:123` evidence.
4. **caller edges (PHP)** — inside every method span (controllers, libs, `scripts/`,
   `cli/`, `services/`): `ClassName::method(`, `new ClassName(`, `$this->helper(`
   (same-class), resolved against the lib/controller inventory. A route whose only
   inbound edges come from `scripts/`/`cli/` is classified **script-only → show the
   file name**; a route with view inbound edges is an **endpoint → show the view
   name(s)** — exactly the product's hard requirement.
5. **data edges** — in the same spans: `R::find|findOne|load|dispense|store|trash('bean'`,
   `Bean::…('bean'` (normalized per the Bean wrapper rules), `own<X>List` /
   `shared<X>List` property reads → `method → bean` read/write edges. Combined with
   Introspector `relations()` (bean → bean FK edges) this is the full
   route ⇄ data-model cross-reference.

Also ADD (small, on Introspector itself — EXTEND):
- `tables(): array` (expose `tableNames()`, `:295-303`) and
  `rows(string $table, int $limit=50, int $offset=0): array` for the `select * from
  connections` drill — guarded by the same `^[a-z0-9_]+$` identifier check already
  used at `:273`, read-only PDO, LIMIT-capped.
- Parse `routes/*.php` for `Flight::route(...)` literals so custom routes appear
  beside auto-routes (flagged `custom-route`).

### 3.3 Technique choice — token/regex static scan, NOT full AST; mantic as optional enrichment

- **Chosen:** `token_get_all` spans + targeted literal-pattern extraction. Cheapest
  reliable approach; deterministic; no deps; runs anywhere PHP runs (including
  inside the bwrap jail, like Introspector). A full tiknix clone is ~45 controllers
  / ~200 views / ~40 libs — one cold scan is well under ~2s, cacheable (§4).
- **Mantic** (tree-sitter code intelligence; per-host node server wired at
  `conf/aibuilder.ini:35-38 [tools] mantic_server`, checkout at
  `/var/www/html/default/Mantic.sh`) offers `goto`/`references`. It is **optional
  enrichment only** (a "verify with mantic" button / AI-side tool), never a runtime
  dependency of the page: it's per-host-configured, node-based, and heuristic-ranked
  — wrong properties for a deterministic cached graph.
- **Known limits (stated in the UI, not papered over):** dynamically built URLs in
  JS, variable dispatch (`$this->$method()`), string-concatenated render paths,
  routes referenced only from external systems (webhooks, cron), and reflection.
  Every edge = `{from, to, kind, evidence: path:line, confidence: exact|inferred}`;
  orphan routes (no inbound edge) are explicitly listed as "no static caller found —
  possibly webhook/external/dynamic" rather than hidden.

---

## 4. Data model & cache

New beans (sidecar DB; RedBean auto-creates on first store; all lowercase types via
`\app\Bean`):

**`explorergraph`** — one row per (instance, scope):
- `instanceId` (int, core instance.id), `scope` (string, '' = whole app, else
  `/control` or `/control/method`), `graphJson` (TEXT, gzdeflate+base64 of
  `{nodes[], edges[], meta}`), `codeHash` (string), `builtAt`, `buildMs`,
  `nodeCount`, `edgeCount`.

**`explorerask`** — Q&A audit + polling: `instanceId`, `memberId`, `question`,
`answer` (TEXT), `status` (running|done|failed), `sessionName`, `createdAt`.

**`ssononce`** — consumed SSO nonces: `nonce`, `expiresAt` (rows past exp pruned
opportunistically).

**Cache strategy (two layers, mirroring `lib/PermissionCache.php`'s APCu+version
pattern):**
1. **APCu** hot layer keyed `explorer.graph.<instanceId>.<scope>.<codeHash>`, TTL 10m
   — page loads after the first are memory-speed.
2. **`explorergraph` bean** durable layer — survives fpm restarts; rebuilt lazily.

**Invalidation = content-addressed, not TTL-first.** `codeHash = sha1(` per-dir
`(fileCount . maxMtime)` over the instance's `controls/ views/ lib/ models/ routes/
scripts/` + `count(authcontrol) . max(authcontrol.id) . sum(level)` from the instance
DB `)`. The stat pass costs ~tens of ms; it runs on every page hit, so a `git pull`
or an authcontrol seed in the instance invalidates the graph on the next view. A
24h hard TTL backstops mtime-invisible changes. Rebuild is synchronous with a
"rebuilding…" state (first paint uses the stale graph + banner when one exists).

---

## 5. The page & UX (single wide page, self-contained)

House style: inline CSS/JS, no CDNs — per `views/index/coming-soon.php` /
`controls/Social.php` and the artifact conventions. One template
`views/explore/index.php`; all data via same-origin JSON endpoints on
`controls/Explore.php`.

**Regions (top → bottom):**

1. **Scope bar** — URL input ("enter a URL from your instance; `/` = everything") +
   instance picker (populated ONLY from `getAccessibleInstanceIds`), cache
   freshness chip (`codeHash` short + builtAt + rebuild button).
2. **Control ribbon (the spreadsheet)** — one cell per authcontrol `control` group
   (from `authcontrolRows()` grouped by `control`), showing name, route count,
   dominant level as a color chip (ROOT/ADMIN/MEMBER/PUBLIC). Roving-tabindex grid:
   `←/→` moves across controls, `↓/Enter` drills into the selected control's method
   row (a second ribbon row listing its methods+levels), `↑/Esc` backs out. Pure
   vanilla JS keydown handling on `[role=grid]`.
3. **Main canvas (wide, 2/3)** — the graph for the current selection, rendered as
   **hand-rolled inline SVG in a fixed 4-column layered layout** (no JS graph lib,
   no force simulation): `views | routes/methods | lib services | beans`, nodes as
   rounded rects, edges as cubic bezier `<path>`s colored by kind (render / calls /
   reads / writes / FK). Layered layout is deterministic, cheap, and matches the
   mental model (request flows left→right into data). Click node → inspector;
   hover edge → evidence tooltip (`path:line`). Pan/zoom via viewBox drag +
   wheel (20 lines of JS). `overflow-x: auto` container.
4. **Inspector panel (1/3, right)** — for the selected node: `describe()`-shaped
   detail (routes+levels / columns+relations / methods), inbound callers list
   ("called by `views/connections/index.php:88`", "script-only:
   `scripts/resetcache.php`"), orphan warnings, and the two action buttons:
   **Ask AI** and **Create fix task** (§6).
5. **Data drawer (bottom, collapsible)** — the `select *` drill for the selected
   control/bean: paginated rows via `Explore::rows` → `Introspector::rows()`,
   rendered with the `lib/DataTableResponse.php` response shape (REUSE) so the
   sidecar's grid JS matches core conventions. LIMIT 50, offset paging, values
   HTML-escaped via `h()` (`lib/functions.php:63-67`), long text truncated with
   expand.

Runtime generation: `Explore::graph?instance=…&scope=…` returns the cached graph or
builds it (§4). The page is fully usable keyboard-only.

---

## 6. AI Q&A + surgical tasks

### 6.1 Q&A — `Explore::ask` / `Explore::askstatus` (sidecar)

- **NEW `lib/AskRunner.php`, derived from the `PlanRunner` pattern** (REUSE of the
  mechanism, not the class): detached tmux session (`lib/TmuxManager.php`), headless
  `claude -p` with model resolved via `lib/EngineRegistry.php` +
  `lib/MemberEnginePrefs.php` (AGENT_ORCHESTRATION.md §2/§7), jailed via the same
  `jailFor()` logic (`lib/PlanRunner.php:104-114`, capricorn `jail-run.sh`) against
  the **target instance** workspace, read-only intent stated in the brief.
- **Grounding:** the brief file (analogous to `plan-request.md`,
  `PlanRunner.php:48,82`) embeds (a) the member's question, (b) the **scoped slice of
  the cached graph** (nodes+edges+evidence for the selected subtree, JSON), and
  (c) `Introspector::digest()` (`Introspector.php:133-209`) — plus the workspace
  `.mcp.json` already gives the agent `codebase_map/describe/whatprovides` to drill
  further (AGENT_ORCHESTRATION.md §6). Answer streams to a log; `askstatus` polls the
  tail (the `PlanRunner::logTail()` pattern, `:57-62`); result stored on the
  `explorerask` bean.
- Q&A is read-only: the ask brief forbids edits, and the jail plus the PreToolUse
  security hook are the backstops.

### 6.2 Surgical tasks — hand off into the EXISTING plan pipeline, never a parallel one

The "fix this" button must produce a **scoped plan-request**, then get out of the way:

1. **Sidecar** `Explore::fix`: builds a Markdown **task brief** from the selected
   node — goal text from the member + auto-appended scope: the node's routes/levels,
   its inbound caller evidence lines, touched beans/columns, and a pre-filled
   `reuses` list (e.g. `["controller/Connections","model/connections"]`) derived
   from the graph. This is precisely the grounding `PlanRunner::buildPlanRequest`
   wants (`lib/PlanRunner.php:188-267` — REUSE/EXTEND/NEW classification, seeds
   rule at :237-242).
2. **Signed handoff explorer → core**: server-to-server POST to a NEW core endpoint
   `Workbench::explorerbrief` (EXTEND `controls/Workbench.php`; sibling of
   `decompose` at `:310`), authenticated with the shared `[explorer] sso_secret`
   HMAC envelope (`aud:'core'`, carries `member_id`, `instance_id`, brief). Core
   re-runs `TaskAccessControl::canAccessInstance` on ITS side (never trusts the
   sidecar), then starts `PlanRunner::start($goal)` exactly as `decompose` does
   (`Workbench.php:350`).
3. **From there the existing pipeline owns everything**: planner grounds + calls
   `submit_plan` (`mcptools/SubmitPlanTool.php:45-62`) → `.aibuilder/plan.json` →
   atomic-claim ingest (`lib/PlanIngestor.php:25-30, 49-122`) → member reviews in
   the Workbench (`planapprove` `Workbench.php:489`, `planbuild` `:502`) →
   `lib/PlanExecutor.php` worktree workers via `EngineRegistry::agentCommand`
   (AGENT_ORCHESTRATION.md §Status item 3) → `lib/AuditRunner.php` DoD pass.
4. Sidecar responds with a deep link to the created plan on core
   (`https://tiknix.com/workbench/view?id=…`). Execution, approval, audit UI: all
   core, all existing. Explorer builds briefs; it never executes.

---

## 7. Gating & security

**Layer 1 — feature flag (who may use the tool at all).** EXTEND
`Feature::CATALOG` (`lib/Feature.php:24-30`) with:

```php
'explorer' => [
    'label'     => 'Architecture Explorer',
    'blurb'     => 'Visual data-model + call-graph explorer for your instances (heavy; runs as a sidecar).',
    'min_level' => 100,  // MEMBER: standard members can REACH it (they own instances);
                         // actual activation is governed by the explorer allowlist below.
],
```

**The `min_level = 100` eligibility is necessary, not sufficient — an explorer
allowlist governs activation.** Level 100 only makes a member *able to reach* the
launch link; whether they may actually use Explorer is an explicit per-member grant
(the "auth list in explorer itself"). Two-tier so ownership scoping is never the only
gate on a heavy tool:
- **Baseline (reuse):** the existing per-member `Feature` toggle *is* an admin-managed
  allowlist — `Feature::setEnabled('explorer', true, memberId)` writes the
  `settings.feature.explorer='1'` row, and `isEnabled()` (`Feature.php:61-72`) is the
  grant check. `min_level=100` gates eligibility; the toggle gates membership. SSO
  consume re-checks this row against core's DB, so a grant/revoke propagates.
- **Optional finer control (NEW, Phase 5):** an explorer-owned `exploreraccess` bean
  (`memberId` and/or `teamId` grants) checked at SSO consume in addition to the flag —
  lets Explorer maintain its *own* allowlist (e.g. grant a whole team) independent of
  the core feature toggle. Recommended only if the per-member toggle proves too coarse.

Admin toggles the baseline grant per member on Edit Member (`controls/Admin.php:203`
already iterates the catalog — zero UI work). Core nav link + `Explorer::launch` gate with
`Feature::isEnabled('explorer', …)` exactly like `Ecommerce::requireFeature()`
(`controls/Ecommerce.php:30-39`) and the nav check (`views/layouts/header.php:93`).
Eligibility is re-checked on every read, so demotion silently revokes
(`Feature.php:61-72`).

**Layer 2 — per-request ownership scoping (what a flagged member may see).** The
SSO session carries ONLY `{member_id, level}` proven by the signed token; **every**
introspection/DB/graph/ask/fix request re-derives the allowed instance set
server-side from the core DB (`ExplorerAccess` ≙ `TaskAccessControl.php:407-425`)
and 403s anything outside it. A slug/URL from the client is a *lookup hint*, never
an authorization input: no `instance` bean match → 403; bean but no
ownership/team edge → 403; filesystem paths built only from the bean (§2.4). The
flag turns the tool on; ownership decides its blast radius. Both layers must pass.

**Seeds (core):** one idempotent `database/seeds/*.php` (Bean wrapper, per
CLAUDE.md; the dir is created by this plan's first seed) adding
`explorer::launch = 100` on core (feature flag + eligibility enforced in-controller,
like ecommerce) — pattern from `services/Schema/Seeds/02_AuthControl.php:25+`.
**Seeds (sidecar):** the deny-by-default profile of §2.1. After seeding:
`php scripts/resetcache.php`.

**Other hardening:** SSO nonce single-use + 120s exp (replay-proof); secrets live in
gitignored ini (the `conf/aibuilder.ini` precedent, header comment :1-8); sidecar
sessions `session_regenerate_id()` on consume; CSRF via `lib/SimpleCsrf.php` +
`csrf_field()` on every POST; `lib/RateLimiter.php` (REUSE) on `ask`/`fix`; ask/fix
actions logged with member id.

---

## 8. Phased delivery (each phase independently landable)

Component classification summary — **REUSE:** Introspector (all of §3.1), Feature,
TaskAccessControl queries, TmuxManager/EngineRegistry/MemberEnginePrefs, PlanRunner→
PlanIngestor→PlanExecutor→AuditRunner pipeline, SimpleCsrf, RateLimiter,
DataTableResponse, PermissionCache, bootstrap/BaseControls/layouts, mintToken/
OAuthState envelope mechanics, seed patterns. **EXTEND:** Feature::CATALOG,
Introspector (`tables()/rows()`, routes/ parsing, public authcontrol accessor),
Workbench (`explorerbrief`), core nav/launch. **NEW:** CallGraph.php, ExplorerGraph,
ExplorerAccess (thin), Sso/Explore controllers, explore view, AskRunner,
`explorergraph`/`explorerask`/`ssononce` beans.

### Phase 0 — Sidecar skeleton + SSO + gating (no explorer features yet)
- T0.1 Core: `explorer` flag in CATALOG + nav link + `Explorer::launch` (mint +
  redirect) + core authcontrol seed. `reuses: ["lib/Feature","controls/Ecommerce(gating pattern)","controls/Aibuilder(mintToken)","services/Schema/Seeds/02_AuthControl"]`
- T0.2 Sidecar: provision clone at `/var/www/html/default/explorer.tiknix`, config
  (`builder_tools_enabled=false`, `[explorer] sso_secret`, `core_root`), nginx vhost,
  deny-by-default authcontrol seed. `reuses: ["bootstrap.php","lib/PermissionCache","capricorn provisioning"]`
- T0.3 Sidecar: `Sso::consume` (verify/nonce/re-check/session) + `ssononce` bean +
  `ExplorerAccess` over the core DB (read-only). `reuses: ["lib/OAuthStateService(envelope)","lib/TaskAccessControl(queries)","lib/Bean"]`
- **Exit criteria:** flagged member round-trips core→explorer→session; unflagged
  member never sees the link and `launch` 403s; un-owned instance ids invisible.

### Phase 1 — Smallest viable Explorer: read-only grid + `select *` drill (NO call graph)
- T1.1 EXTEND core `mcptools/Introspector.php`: public `authcontrol()`, `tables()`,
  `rows($table,$limit,$offset)`, `routes/` literal parsing. `reuses: ["mcptools/Introspector"]`
- T1.2 Sidecar `Explore::index` + `views/explore/index.php`: scope bar (ownership-
  filtered picker + URL resolve→403 path), control ribbon grouped by `control`,
  arrow-key spreadsheet traversal, method row with levels. `reuses: ["mcptools/Introspector(authcontrolRows,describe)","lib/ExplorerAccess","views/index/coming-soon.php(style)"]`
- T1.3 `Explore::rows` data drawer (paginated `select *`, escaped, capped).
  `reuses: ["lib/DataTableResponse","mcptools/Introspector(rows)"]`
- T1.4 Cache v1: `explorergraph` bean + APCu + `codeHash` invalidation (caches the
  Introspector inventory even before the call graph exists). `reuses: ["lib/PermissionCache(pattern)","lib/Bean"]`
- **Exit criteria:** enter `/` or a URL → grouped authcontrol grid for an owned
  instance; select `connections` → its rows; foreign slug → 403; second load <100ms.

### Phase 2 — The call/render cross-reference graph (the hard part)
- T2.1 NEW `mcptools/CallGraph.php` in CORE (spans via token_get_all; render/
  redirect edges; view→route edges; PHP caller edges incl. scripts/cli; data edges;
  confidence + evidence on every edge). Unit-tested against the core repo itself
  (known fixtures: `Connections.php:99,425` render edges, etc.).
  `reuses: ["mcptools/Introspector(inventories)","controls/BaseControls/Control(render semantics)"]`
- T2.2 Sidecar `ExplorerGraph`: compose Introspector + CallGraph into the cached
  node/edge JSON; scope slicing (`/control`, `/control/method`). `reuses: ["mcptools/CallGraph","explorergraph bean"]`
- T2.3 SVG layered canvas + inspector panel (endpoint→view name, script-only→file
  name, orphan list, evidence tooltips). `reuses: ["Explore views (P1)"]`
- **Exit criteria:** clicking a `connections` method shows every static caller with
  `path:line`; script-only vs endpoint classification correct on the core repo.

### Phase 3 — AI Q&A
- T3.1 NEW sidecar `lib/AskRunner.php` (PlanRunner-derived, read-only brief,
  jailed) + `explorerask` bean. `reuses: ["lib/PlanRunner(pattern)","lib/TmuxManager","lib/EngineRegistry","lib/MemberEnginePrefs"]`
- T3.2 `Explore::ask`/`askstatus` + inspector chat drawer; grounding = graph slice +
  `digest()`; rate-limited. `reuses: ["mcptools/Introspector(digest)","lib/RateLimiter","lib/SimpleCsrf"]`

### Phase 4 — Surgical tasks
- T4.1 Sidecar brief builder (node → scoped Markdown brief with pre-filled
  `reuses`) + `Explore::fix` + signed POST. `reuses: ["ExplorerGraph","sso envelope"]`
- T4.2 Core `Workbench::explorerbrief` (verify HMAC + re-run canAccessInstance +
  `PlanRunner::start`) + authcontrol seed row + deep-link response.
  `reuses: ["controls/Workbench(decompose)","lib/PlanRunner","lib/TaskAccessControl","lib/PlanIngestor","lib/PlanExecutor","lib/AuditRunner"]`
- **Exit criteria:** "fix this" on a node → reviewable plan tree in core Workbench,
  scoped to that subsystem, executed/audited entirely by the existing pipeline.

### Phase 5 — Polish (optional, unordered)
- Mantic enrichment button (verify callers via tree-sitter references) — per-host,
  degrade gracefully. `reuses: ["conf/aibuilder.ini [tools] mantic_server"]`
- Graph diffing across `codeHash`es ("what changed since last week"); multi-instance
  compare; export as artifact.

---

## 9. Open questions / risks

1. **Call-graph accuracy (top risk).** Dynamic URLs/dispatch are statically
   invisible; conversely regex-adjacent extraction can false-positive in comments
   (token_get_all mitigates) and in JS template strings. Contained by: evidence +
   confidence on every edge, an explicit orphan list, and mantic as a second
   opinion. Accept: the graph is a high-recall *map*, not a proof.
2. **Cross-instance access must fail closed — and be verified to.** The invariants:
   (a) an un-owned or unknown slug returns 403 with no existence oracle beyond the
   404/403 distinction (return 403 uniformly); (b) no filesystem/DB/MCP code path
   accepts a path or slug that didn't come off an ownership-checked `instance`
   bean; (c) the picker/API never enumerates outside `getAccessibleInstanceIds()`.
   Ship a small integration test that logs in as member A and attempts member B's
   slug across every endpoint (`graph/rows/node/ask/fix`) expecting 403. Also:
   collect team ids with `array_values()` before any `IN (?,?)` binding
   (`TaskAccessControl.php:278-287`; CLAUDE.md trap).
3. **SSO trust model.** A shared static HMAC secret means either side's config leak
   forges identity for the *other* side's endpoints. Contained by: 120s TTL,
   single-use nonce, `aud` separation (explorer-tokens can't replay against core
   and vice versa), gitignored ini, and sidecar re-verifying member status +
   feature flag against the core DB on consume. Open question: rotate the secret on
   a schedule (dual-secret grace window like key rotation) — Phase 5.
4. **Same-user filesystem access is coarse.** The fpm user can technically read any
   instance dir; the ownership gate is application-layer. This matches the existing
   trust model (core's PlanRunner/Aibuilder already read instance trees as this
   user), but if explorer's exposure grows, migrate to option (b) (per-instance MCP
   with broker-class keys, hash-stored per `Mcp.php:1762-1766`) for process-level
   separation. The `ExplorerGraph` transport seam exists for exactly this.
5. **Instance DB locking / load.** Reading a live instance's SQLite while its app
   writes: open read-only with a busy timeout; the Explorer must never hold long
   transactions. `rows()` is LIMIT-capped; graph builds stat-scan first and skip
   unchanged trees. "Heavy" is why the feature is flagged ADMIN-first.
6. **Stale-graph grounding for AI/fix.** An ask/fix against an outdated `codeHash`
   can mislead the planner. Mitigation: `fix` briefs embed the `codeHash` +
   built-at, and `explorerbrief` rebuilds nothing — the planner re-grounds itself
   live via `codebaseDigest()` (`PlanRunner.php:275-286`), so staleness affects
   only the *scope hint*, not the plan's ground truth.
7. **Sidecar drift.** As a tiknix clone, explorer.tiknix must `git pull` to pick up
   Introspector/CallGraph improvements. Open question: pin the sidecar to core's
   release cadence, or have `ExplorerGraph` prefer the *target instance's* copy of
   CallGraph when newer (version constant in the class) — recommend the latter.
8. **Level semantics on the sidecar.** SSO level is a snapshot; re-check on consume
   + hourly per session keeps a demoted admin from keeping ADMIN-scoped explorer
   powers for a whole session. Accept ≤1h staleness on demotion; revocation of the
   feature flag itself has the same window (documented).
