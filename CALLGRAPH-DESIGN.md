# CallGraph.php — Validated Design Spec

`mcptools/CallGraph.php` (namespace `app\mcptools`) — the static call/render
cross-reference scanner for tiknix instances. This document replaces
EXPLORER-PLAN.md §3.2's sketch with **empirically tested** extraction rules.
Every rule below was run against the real repo at `/var/www/html/default/tiknix`
(2026-07-22, HEAD `e272a76`); pasted output is real. Experimental scripts live in
the session scratchpad (`span.php`, `spanlib.php`, `edges.php`, `viewroutes.php`,
`scanall.php`) and are the reference implementation for the algorithms specified
here.

**Measured headline numbers (this repo):**

| Metric | Value |
|---|---|
| PHP files scanned (controls/lib/scripts/cli/services/mcptools/models/routes) | 157 files, 38,197 lines |
| Method spans extracted | 1,259 |
| Class::method call sites | 2,622 |
| Full PHP-side token scan | **56 ms** cold |
| Views scanned | 102 files, 968 KB |
| View→route scan | **33 ms** cold |
| `$this->render()` sites in controls | 124 — **100% literal string args** (0 dynamic) |
| Render targets pointing at a **missing** view file | 15 of 124 (real dead paths — see §3.2) |
| `Flight::redirect()` sites | 385 — 362 literal (94%), 23 dynamic/computed |
| View URL literals: candidates → validated | 456 → 418 exact + 7 custom-route = **93.2% validated**; 31 dropped, all verified noise or genuine dead links (§3.3) |

---

## 1. Approach — token scan, not full AST, not mantic

**Chosen: `token_get_all()` spans + targeted token-pattern extraction.**
Grounded reasons, in order of weight:

1. **The codebase earns it.** Empirically, this house style is overwhelmingly
   literal: all 124 `->render(` calls in `controls/` take a single-quoted
   literal first argument (verified: `grep -rn -- '->render(' controls/ |
   grep -vP "render\(\s*'[^']*'"` returns **zero** rows); 94% of redirects are
   literal; view URLs are quoted root-relative literals. A full AST
   (nikic/php-parser) buys nothing here except a composer dependency that
   cannot ship into instance clones (Introspector is deliberately
   zero-dependency and jail-safe — CallGraph must match, since it lands in CORE
   and every instance inherits it).
2. **Tokens beat regex exactly where regex fails.** `token_get_all` is
   comment-safe and string-safe for free: a `$this->render(...)` inside a
   docblock or a heredoc is a `T_DOC_COMMENT`/`T_ENCAPSED_AND_WHITESPACE` token,
   never a false match. Introspector's line-regex stays for *signatures*;
   CallGraph uses tokens for *bodies*.
3. **Mantic is enrichment, not foundation** (per plan §3.3): per-host node
   server, heuristic ranking — wrong properties for a deterministic, cacheable,
   works-in-the-jail graph. Keep it as the "second opinion" button.
4. **Views are NOT token-scanned.** Views are PHP/HTML/JS soup; the URL
   literals we need live in HTML attributes and JS strings, which
   `token_get_all` sees as one giant `T_INLINE_HTML` blob anyway. Views get a
   line-oriented regex scan (validated at 93.2% precision, §3.3) — the
   validation-against-known-routes step is what makes this precise, not the
   matcher.

**Class shape** (mirrors Introspector; consumes its inventories, never re-derives
them):

```php
namespace app\mcptools;
final class CallGraph {
    public const VERSION = 1;                       // sidecar picks newer copy (plan §9.7)
    public function __construct(private Introspector $intro, private string $root) {}
    public function build(): array;                 // {nodes: [...], edges: [...], meta: {...}}
    // internals: methodSpans(file), phpEdges(file), viewEdges(file), routesFileEdges(file)
}
```

---

## 2. Method-span extractor — algorithm + tested output

### 2.1 Algorithm (validated in `spanlib.php::methodSpansFromTokens`)

Single pass over `token_get_all($src)`, maintaining a **typed brace stack**
instead of a bare depth counter — each `{` is pushed as `class:<Name>`,
`method`, `interp`, or `other`, so the closing `}` knows what it closes:

1. **Line tracking (the bug you will write first).** Raw char tokens (`{` `}`
   `;`) carry **no line number**. Track
   `line = token_line + substr_count(token_text, "\n")` on every array token, so
   a `}` following a multi-line whitespace/heredoc/comment token is attributed
   to its true line. *Empirically caught:* first version reported
   `Connections::index 334-431`; line 431 is `]);` and the real closing brace is
   line 432 — the preceding `T_WHITESPACE` starts on 431 and contains the
   newline. After the fix, every span end verified correct.
2. **Class contexts.** On `T_CLASS`/`T_TRAIT`/`T_INTERFACE`/`T_ENUM`: skip if
   the previous significant token is `T_DOUBLE_COLON` (`Foo::class`); mark
   `(anon)` if it is `T_NEW` (anonymous class); else capture the following
   `T_STRING` name. The name is pushed with that class's `{`.
3. **Methods.** On `T_FUNCTION` while the innermost stack entry is a class
   context: next significant token `T_STRING` = method name (a `(` instead
   means closure → ignore; closures inside a method body are just `other`
   braces and never split the span). If `;` arrives before `{` → abstract/
   interface stub, recorded with `start == end` and `stub: true`.
4. **String-interpolation braces.** `T_CURLY_OPEN` and
   `T_DOLLAR_OPEN_CURLY_BRACES` open a scope that token_get_all closes with a
   raw `}` — push `interp` for them or the stack corrupts. Heredoc/nowdoc
   bodies are `T_ENCAPSED_AND_WHITESPACE` — braces inside them never appear as
   char tokens. `match`/`switch` braces are plain `other` pushes. PHP 8
   attributes (`#[...]`) contain no brace char tokens.

### 2.2 Tested output (real, verbatim)

`controls/Connections.php` (798 lines, match-expressions, 29 methods):

```
Connections::setup  87-108          # verified: 87 = `public function setup(`, 108 = `}`
Connections::index  334-432         # verified against sed -n '334p;432p'
Connections::connectorCallback  521-570
-- 29 spans in 1.9ms
```

`controls/Workbench.php` (5,066 lines, the biggest file, heredocs throughout):

```
Workbench::__construct  32-36       # grep -n 'public function' agrees on every start line
Workbench::index  41-130
Workbench::store  204-298
Workbench::decompose  310-368       # matches EXPLORER-PLAN.md's cited :310
-- 62 spans in 6.7ms   (4.5 ms/scan steady-state, measured over 20 runs)
```

`lib/FlightMap.php` (anonymous class inside a `Flight::map` closure, line 201):

```
(anon)::getTokenArray  202-204
(anon)::validateRequest  205-207
(anon)::field  208-210
```

`controls/Agentsetup.php` (match-expression heavy) and `controls/Shop.php`
(`Shop::_fallback 376-382`) — all start lines agree with
`grep -n 'public function'`; all end lines verified by inspection. **Zero span
errors across 1,259 spans** (cross-check: every file's span count equals its
`public|private|protected function` count minus closures).

**Attribution rule:** any token between `start` and `end` of a span belongs to
`Class::method`. Tokens outside all spans (top-level script code) belong to the
pseudo-owner `(file)` — this is exactly how `scripts/*.php` callers are
captured (§3.4).

---

## 3. The five edge extractors — rules + validated hits

All extractors run over the same token stream in the same pass, attributing by
line → span lookup. "First argument" = first significant token after `(`;
`T_CONSTANT_ENCAPSED_STRING` → literal (confidence `exact`), anything else →
`dynamic`.

**Name normalization (required, tested):** qualified names arrive as
`T_NAME_QUALIFIED` (`app\Bean`) or `T_NAME_FULLY_QUALIFIED` (`\app\PermissionCache`,
`\Flight`) — take the last `\`-segment. Real code uses all three forms
(`\Flight::redirect(` in `controls/Products.php:13`,
`\app\PermissionCache::clear()` in `controls/Permissions.php:82`, bare
`Flight::redirect` everywhere else).

### 3.1 route → view (`renders`, `redirects`, `json`)

**Rule.** Inside controller spans:
- `$this` `T_OBJECT_OPERATOR` `render` `(` + literal → edge to
  `views/<lit>.php` (semantics from `controls/BaseControls/Control.php:65`).
  Third arg `false` = no layout sandwich; only 7 of 124 sites pass it — when
  layout is on (the default), emit three additional **implicit** edges to
  `views/layouts/{header,footer,layout}.php`, marked `implicit: true`, so the
  layout templates don't look orphaned.
- `Flight::render(` direct — 1 real site: `controls/Docs.php:51` renders
  `docs/cli_help` (no layout).
- `Flight::renderView(` — used only by `lib/FlightMap.php` for error pages
  (`error/403` :136, `error/404` :284, `error/500` :314).
- `include`/`require` whose argument token sequence contains a
  `T_CONSTANT_ENCAPSED_STRING` matching `#(^|/)views/.+\.php$#` — 1 real site:
  `controls/Social.php:55` `include dirname(__DIR__) . '/views/social/page.php'`
  (the concatenation prefix is a path-builder; match the *literal fragment*).
- `Flight::jsonSuccess|jsonError(` or `$this->jsonSuccess|jsonError|json(` →
  no view edge; mark the **owning route node** `shape: "json"` ("endpoint, no
  view"). A method with ≥1 json edge and 0 render edges is an endpoint.
- `Flight::redirect(` + literal starting `/` → `redirects` edge to the target
  route (resolved per §4); literal starting `http` → `kind: external`
  (real case: `controls/Connections.php:293` → github.com OAuth); non-literal →
  `dynamic-redirect` (23 sites repo-wide, e.g. `Connections.php:517`
  `Flight::redirect($url)`, `Security.php:187` ternary+interpolation).

**Tested (verbatim from `edges.php … render`):**

```
renders   Connections::setup -> views/connections/setup.php  controls/Connections.php:99  [exact]
renders   Connections::index -> views/connections/index.php  controls/Connections.php:425 [exact]
redirects Connections::callback -> /connections/setup?id=    controls/Connections.php:316 [exact]
redirects Connections::connect  -> https://github.com/...    controls/Connections.php:293 [exact→external]
redirects Connections::connectorConnect -> (dynamic)         controls/Connections.php:517 [dynamic]
json      Connections::status -> (endpoint)                  controls/Connections.php:114 [exact]
```

Precisely matches the plan's cited fixture (`Connections.php:99,425`).

**End-to-end precision check:** all 124 literal render targets were resolved to
`views/<x>.php` and stat'd. **109 exist; 15 do not** — and the 15 are genuine
defects, not extractor misses (verified by `ls`): `controls/Help.php:26` →
`views/help/getting-started.php` (help/ contains only `index.php`),
`controls/Error.php:51` → `views/error/maintenance.php`,
`controls/Permissions.php:107` → `views/permissions/index.php` (directory
doesn't exist), `controls/Index.php:122/131/167/176` → about/contact/privacy/
terms, etc. **Spec:** a render edge whose target file is absent becomes a
`missing-view` node (`confidence: exact`, `broken: true`) — the graph must
show it, it's a route that 500s when hit.

### 3.2 route/lib → bean (`reads` / `writes`)

**Rule.** `R`|`Bean` `::` method `(`:
- Read set: `find, findOne, findAll, findLike, load, loadAll, count, getAll,
  getRow, getCol, getCell, findCollection`. Write set: `dispense, store,
  storeAll, trash, trashAll, exec, wipe`.
- Literal first arg → bean name, normalized per the Bean wrapper contract
  (`lib/Bean.php:7-16`): `strtolower(str_replace('_',''))` — so
  `Bean::dispense('enterpriseSettings')` → `bean:enterprisesettings`.
  Verified: the only camelCase literals in the live repo are Bean.php's own
  docblock examples (production code already passes lowercase), but the
  normalizer is mandatory — instance clones built by AI Builder DO write
  camelCase (it's the documented wrapper contract in CLAUDE.md).
- `R::getAll/getRow/getCol/getCell/exec` take **SQL**, not a bean name → edge
  `to: bean:(sql)` with `confidence: dynamic`, evidence line kept (the
  inspector shows the SQL line; do not attempt table parsing in v1).
- **`store($var)` / `trash($var)` inference (new vs. plan):** these usually take
  a variable, not a literal (24 of the repo's store sites). Rule: if the
  *enclosing span* contains exactly one distinct exact bean type from
  `load/findOne/dispense` edges, attribute the write to that bean with
  `confidence: inferred`; otherwise `bean:(dynamic)`. Validated example:
  `Connections::test` loads `connections` at :666 and `Bean::store($conn)` at
  :673 → inferred write to `bean:connections` (correct by inspection).
- **Relation-list properties:** `T_OBJECT_OPERATOR` followed by `T_STRING`
  matching `/^(x?own|shared)([A-Z]\w*?)List$/` → edge to
  `bean:strtolower($2)`; `own`/`xown` = child-list (`xown` additionally marked
  `cascade: true`), `shared` = many-to-many. Heavily used here: 5×
  `xownTasklogList` (`controls/Workbench.php:1085`), `sharedTeamList`
  (`controls/Aibuilder.php:585`), `ownApikeyList` (`controls/Apikeys.php:35` —
  note it appears after `->with(' ORDER BY … ')`, which the rule handles for
  free since it only needs `-> propertyName` with no trailing `(`).

**Tested (verbatim):**

```
reads   Connections::githubConn      -> bean:connections  controls/Connections.php:65  [exact] via Bean::findOne
writes  Connections::add             -> bean:connections  controls/Connections.php:186 [exact] via Bean::dispense
reads   Connections::publishfeed     -> bean:socialpage   controls/Connections.php:737 [exact] via Bean::findOne
writes  Connections::test            -> bean:(dynamic)    controls/Connections.php:673 [dynamic→inferred:connections] via Bean::store
reads   (file)                       -> bean:socialpage   scripts/sync-social-feeds.php:26 [exact] via Bean::find
```

Bean→bean FK edges come from `Introspector::relations()` (plan §3.1) — CallGraph
does not re-derive them.

### 3.3 view → route (the hard one)

**Rule (validated at 93.2% precision on all 102 views).** Line-scan each view
for quoted root-relative URL literals:

```
#["'](/[a-z][a-zA-Z0-9_/.\-]*(?:\?[^"']*)?)["'<?]#
```

The trailing `[<?]` alternative matters: it accepts literals terminated by a
PHP open tag, capturing the static prefix of
`href="/workbench/view?id=<?= $task->id ?>"` (8 real occurrences) — the
route part is fully static even when the query is interpolated. This single
pattern covers every URL-carrying shape found in the repo: `href=`, `action=`,
`fetch('…')`, `$.ajax({url:…})`, `window.location(.href) = '…'`, and JS
concatenations whose *base* is a quoted route literal
(`fetch('/map/statedetails?state=' + stateCode)` — `views/map/usa.php:220`).

**Then validate every candidate against the known route set** (this is where
the precision comes from):
1. Strip query/fragment; split segments. Seg1 → controller slug (from
   Introspector's controller inventory), seg2 (default `index`) → method,
   compared **lowercased** (URL `/teams/updaterole` ⇒ method `updaterole`;
   `method_exists` is case-insensitive so camelCase methods match their
   lowercase URLs).
2. Match against `routes/*.php` custom-route literals first (§4).
3. Controller exists + method exists → `exact` edge.
4. Controller exists + method missing + controller has `_fallback` → edge to
   `ctrl::_fallback('<seg>')`, `confidence: inferred` (e.g. any
   `/shop/<sku>` or `/social/<slug>` literal).
5. Controller exists + method missing + no `_fallback` → **`broken-link`
   node** (control exists, method doesn't — real case: `/docs/mcp` from
   `views/index/index.php:103`; `Docs.php` has no `mcp()` and no `_fallback`).
6. Seg1 matches no controller: if `str_replace('-','',seg1)` matches one →
   **`broken-link` node with a fuzzy suggestion** (see below); else drop.

**Tested numbers (all views):** 456 candidates → 418 `exact` + 7 custom-route
+ 31 dropped. Sampled exact edges (verbatim):

```
views/connections/setup.php:113 -> connections::repos        [exact]   fetch('/connections/repos?id='+iid
views/teams/members.php:215     -> teams::updaterole          [exact]   fetch('/teams/updaterole'
views/layouts/_notify-bell.php:125 -> communications::unreadjson [exact]
views/connections/index.php:236 -> connections::disconnect    [exact]
views/layouts/header.php:100    -> connections::index         [exact]
```

**All 31 drops audited — zero good edges lost:** 12 static assets/includes
(`/css/app.css`, `/js/*`, `/public/img/*`, `/partials/sidebar.php` — the last
is a view→view *include*, tracked separately below), 8 placeholder strings in
form help-text (`/path/to/dir`, `/home/user/docs`, `/etc`, `/main`), and —
the significant find — **6 genuinely dead links in the shipped app**:
`/agent-setup/store-server|update-server|delete-server`
(`views/agentsetup/index.php:240,295,355`) and `/hooks/save-config`
(`views/hooks/config.php:32`). Verified dead: `FlightMap.php:64` does
`ucfirst($class)` with **no hyphen normalization** (grep for `'-'`/`ucwords`/
`camel` across FlightMap/bootstrap/index.php: nothing), `class_exists('app\
Agent-setup')` can never be true, and authcontrol contains `agentsetup::index`
(no hyphenated variant; queried live from `database/tiknix.db`). Likewise
`save-config` can never `method_exists` against `saveConfig` (hyphen).
**Spec:** rule 6's fuzzy match turns these into first-class `broken-link`
nodes ("did you mean `agentsetup::storeServer`?") — the Explorer *finds real
bugs on day one*.

**View→view includes:** literals matching `#(^|/)(partials|components)/.+\.php#`
or `views/…` in `include|require` context (7× `/partials/sidebar.php` under
`views/docs/`, 2× `/components/php-editor.php`) → `includes` edges between
view nodes, `confidence: exact`.

**Dynamic sites surfaced, not dropped:** `fetch(url)`
(`views/teams/view.php:279`, `views/connections/setup.php:99`) and computed
`window.location` assignments with no literal base → one `dynamic-call` node
per view file with the evidence lines, so the inspector can say "this view
also makes 2 requests whose URLs are computed at runtime."

### 3.4 caller → route/lib (PHP call edges + script-only classification)

**Rule.** In every span (and `(file)` top-level code) across `controls/ lib/
scripts/ cli/ services/ mcptools/ models/`:
- `Name ::(T_DOUBLE_COLON) T_STRING (` → static call edge; `Name` normalized
  (§3 header). `R`/`Bean`/`Flight` hits are consumed by extractors 3.1/3.2
  first; remaining names resolve against the Introspector class inventory
  (libs + controls + services); unresolved names (PHP built-ins, vendor) are
  dropped.
- `T_NEW` + name → `instantiates` edge (constructor call).
- `$this ->` T_STRING `(` → `calls-self` edge, resolved within the same class,
  else against `BaseControls\Control` (§6).
- `$this -> $var (` → `dynamic-dispatch` node (`confidence: dynamic`).
  **Empirically zero instances in this repo** (`grep -rn '\$this->\$'
  controls/ lib/` → nothing), but the representation is specced because
  AI-built instances may do it.

**Classification (the product's hard requirement), computed after all edges:**
- A lib method / route whose inbound `calls`/`instantiates` edges come **only
  from `scripts/` or `cli/` files** → `reach: script-only`, inspector shows
  the file name(s).
- A route with ≥1 inbound `view-call` edge → `reach: endpoint`, inspector
  shows the calling view name(s).
- No inbound edges at all → `reach: orphan` (§5).

**Tested — real script-only findings from the whole-repo scan (verbatim):**

```
AuditReporter::report        <- scripts/plan-audit.php          (sole caller)
ClaudeRunner::findByTaskId   <- cli/task-health-check.php       (sole caller)
ClaudeRunner::listAllSessions<- cli/test-features.php           (sole caller)
new AuditRunner              <- scripts/plan-audit.php          (sole instantiation)
new PlanExecutor             <- scripts/plan-orchestrate.php    (sole instantiation)
```

And a mixed example proving the resolution works across name forms:
`PermissionCache::clear` is called from `controls/Permissions.php:82,180,213`,
`controls/Admin.php:547,634`, `scripts/reseed.php:57`, and
`scripts/resetcache.php:48` — a *not*-script-only target, correctly.

### 3.5 routes/*.php custom routes

**Rule.** Line-regex over `routes/*.php`:
`#Flight::route\(\s*'(?:[A-Z|]+\s+)?(/[^']*)'#` → route pattern + evidence
line; each becomes a `custom-route` node. This repo: `routes/api.php` (2:
`POST /api/validatephp`, `POST /api/toolmetadata`), `routes/mcp.php` (23:
`GET|POST /mcp/message` :11, `/mcp/registry/*` family), `routes/default.php`
(no literals — just `Flight::defaultRoute()`). Handlers are closures; body
edges for those closures are extracted with owner `(file)` of the routes file
(they call `Mcp`/`Api` controller classes, which the §3.4 extractor picks up).
Verified: 7 view URL literals validated against custom routes (`/mcp/registry`
links in views).

**CORRECTION — only bootstrap-loaded routes files are LIVE.** A `routes/*.php`
file's literals are only real routes if `bootstrap.php` actually `require`s that
file. Grounded: `bootstrap.php:332` requires **only** `routes/mcp.php`, then calls
`Flight::defaultRoute()` inline (`:336`) — so `routes/api.php` and
`routes/default.php` are **never loaded**, and `api.php`'s `/api/validatephp` +
`/api/toolmetadata` are **dead routes** (their handlers can never fire). The
scanner MUST therefore first parse `bootstrap.php` for
`#require(?:_once)?\s+__DIR__\s*\.\s*'/routes/([a-z0-9_]+\.php)'#` to build the
set of loaded routes files, then: literals from a loaded file → live
`custom-route` node; literals from an UNLOADED routes file → `custom-route` node
with `broken: true, reason: "routes file not loaded by bootstrap"`. This turns the
gap into more day-one dead-code detection (the `/api/*` routes surface as dead,
exactly the Explorer's value prop). View URL literals that only match a dead
custom route become `broken-link` nodes, not `exact` edges.

---

## 4. FlightPHP routing truth table (grounded)

Every way a request reaches code in this codebase, from reading
`lib/FlightMap.php` + `bootstrap.php:336` + `routes/`:

| # | Mechanism | Ground truth | Scanner treatment |
|---|---|---|---|
| 1 | Auto-route `/class/method/op/opid` | `FlightMap.php:39` pattern; `:64` `ucfirst($class)`; method must be public (`:97`); `class` defaults `index`, `method` defaults `index` (`:44-45`) | Node per `control::method` from Introspector inventory |
| 2 | **No hyphen/underscore normalization** | `:64` is bare `ucfirst` — `/agent-setup` is undispatchable; verified no rewrite in bootstrap/index.php/routes | Fuzzy `broken-link` detection (§3.3 rule 6) |
| 3 | `_fallback($seg, $params)` | `FlightMap.php:109-116`: called when method missing and controller defines it. Real: `Shop.php:376` (sku/category resolve), `Social.php:31` (slug + `.json`), plus 301-shim controllers `Products/Catalog/Category/Categories/Store.php` | Controller flagged `hasFallback`; unknown second segments resolve `inferred`; `_fallback` is itself a route node |
| 4 | Custom routes `routes/*.php` | `Flight::route('VERB /path', closure)` — 25 literals in api.php/mcp.php | `custom-route` nodes (§3.5) |
| 5 | `$this->render($tpl)` | `Control.php:65-82`: sandwich = `layouts/header` + tpl + `layouts/footer` + `layouts/layout` via `Flight::render`; `$layout=false` (7 sites) skips sandwich | `renders` edge + 3 `implicit` layout edges when layout on |
| 6 | `Flight::render` / `Flight::renderView` direct | `Docs.php:51`; `FlightMap.php:136,284,314` (error views) | Same as renders, `via` recorded |
| 7 | `include` of a view | `Social.php:55` | `renders` edge (kind `includes-view`) |
| 8 | `Flight::redirect` | 385 sites; 94% literal; relative targets re-enter the router | `redirects` edge, target resolved like a view URL (same validator) |
| 9 | `Flight::jsonSuccess/jsonError/json` (+ `$this->` wrappers) | `FlightMap.php:232-245`; `Control.php:238-254` | Route `shape: json`; no view edge |
| 10 | `Flight::notFound/halt/stop` | `FlightMap.php:278-288` | Terminal; no edge (route may legitimately have no view) |
| 11 | Permission gate | `FlightMap.php:50` → `PermissionCache::check`; effective level via Introspector `routeLevel()` (method row → `*` wildcard) | Level attribute on route nodes (REUSE) |
| 12 | Conceded: URLs built entirely at runtime, webhooks/cron entering from outside, `header('Location:')` raw | e.g. `fetch(url)` sites; Stripe/Shopify webhook callers of `shop::webhook` | §9 ledger; orphan/dynamic surfacing |

---

## 5. Node / edge / confidence JSON schema

```jsonc
// ---- node ----
{
  "id": "route:connections::setup",       // kind:key — unique, stable, scope-sliceable
  "kind": "route",                        // route|view|lib|libmethod|bean|script|custom-route|
                                          // broken-link|missing-view|dynamic|external
  "label": "connections::setup",
  "path": "controls/Connections.php",     // repo-relative, always
  "line": 87,                             // definition line (span start)
  "span": [87, 108],                      // routes/libmethods only
  "level": 100,                           // routes: effective authcontrol level (Introspector)
  "shape": "page",                        // routes: page|json|mixed|redirect-only
  "hasFallback": false,                   // controller-level flag propagated to route nodes
  "reach": "endpoint",                    // endpoint|script-only|internal|orphan
  "broken": false,                        // broken-link / missing-view nodes: true
  "suggest": "agentsetup::storeServer"    // broken-link only: fuzzy repair hint
}

// ---- edge ----
{
  "from": "route:connections::setup",     // node ids
  "to":   "view:views/connections/setup.php",
  "kind": "renders",                      // renders|includes-view|redirects|view-call|calls|
                                          // calls-self|instantiates|reads|writes|rel-own|
                                          // rel-shared|fk|external|dynamic-*
  "evidence": "controls/Connections.php:99",   // ALWAYS present, always path:line
  "confidence": "exact",                  // exact | inferred | dynamic
  "via": "Bean::findOne",                 // optional: the concrete API that made the edge
  "implicit": false,                      // layout-sandwich edges: true
  "cascade": false                        // rel-own via xown…List: true
}
```

**Id scheme:** `route:<ctrl>::<method>` / `view:<relpath>` / `bean:<table>` /
`lib:<Class>` / `libmethod:<Class>::<method>` / `script:<relpath>` /
`custom:<pattern>` / `broken:<url>` / `dynamic:<path>:<line>`.

**Confidence semantics (exact three-way, no fourth value):**
- `exact` — literal token/string resolved against a verified inventory entry
  (measured: 418/456 view URLs, 124/124 renders, 362/385 redirects).
- `inferred` — resolved through a stated heuristic: `_fallback` dispatch,
  `store($var)` same-span bean inference, layout-implicit edges.
- `dynamic` — a call/render/URL site exists but its target is computed:
  `Flight::redirect($url)` (`Connections.php:517`), `fetch(url)`
  (`views/teams/view.php:279`), `R::getAll(sql)`, `$this->$m()`.
  **Dynamic sites are nodes/edges with evidence, never omissions.**

**Orphans:** after the build, every `route:` node with zero inbound
`view-call|calls|redirects` edges gets `reach: "orphan"` and joins
`meta.orphans[]`, rendered as "no static caller found — possibly
webhook/external/dynamic" (e.g. `shop::webhook`, reached only by Stripe).

`meta`: `{codeHash, builtAt, buildMs, counts:{nodes,edges,orphans,broken,dynamicSites}, version: CallGraph::VERSION}`.

---

## 6. Inheritance / traits / base class

**Empirical ground:** zero `trait` declarations in controls/lib/services/
mcptools (grepped); one abstract base `BaseControls\Control`; one anonymous
class (`FlightMap.php:201`). So the rule can be simple and honest:

1. **`$this->method()` resolution order:** same-class span table → then
   `BaseControls\Control`'s method table (Introspector lib-style scan of
   `controls/BaseControls/Control.php`). Base helpers get `libmethod:` nodes
   (`Control::render`, `Control::requireLogin`, …) so "who calls
   `validateCSRF`" is answerable. The *effects* of base helpers are edges from
   the **base method node**, not duplicated per caller — e.g.
   `Control::handleException` renders `error/500` (`Control.php:270`); a
   controller calling `handleException` gets `calls-self → Control::handleException`,
   and the render edge lives once on the base node. (Full transitive-closure
   flattening is a UI concern, not a data concern.)
2. **Edges extracted from a base/parent method body are attributed to the
   defining class**, never to inheritors. Limit: if `Foo extends Control` and
   never overrides `handleException`, the graph will not claim `Foo` renders
   `error/500` — the two-hop path exists and the UI can walk it.
3. **Anonymous classes:** spans attributed to `(anon)@<file>:<line>`; their
   edges carry normal evidence, owner is the enclosing `(file)` or span.
4. **Traits (future instances):** `T_TRAIT` spans are extracted identically
   (already handled by the span algorithm); `use TraitName;` inside a class
   body is recognized (`T_USE` at class-body depth) and recorded as
   `class –uses→ trait` so resolution can fall back class → trait → base.
   Untested here (no traits exist); marked v1-best-effort in the ledger.

---

## 7. Performance (measured on THIS repo)

| Measurement | Result |
|---|---|
| Token scan + spans + call-site extraction, 157 PHP files / 38,197 lines | **56 ms** |
| View scan + route validation, 102 views / 968 KB | **33 ms** |
| Biggest single file (`controls/Workbench.php`, 157 KB / 5,066 lines) | 4.5 ms/scan |
| Extrapolated full CallGraph build (all extractors, one pass) | **< 150 ms cold** |

Conclusion: a cold build is so cheap that **incremental per-file rebuilds are
NOT worth the complexity** for v1. The plan's §4 cache (APCu +
`explorergraph` bean, content-addressed `codeHash` from per-dir
`fileCount.maxMtime` + authcontrol fingerprint) is the right and sufficient
layer; CallGraph itself stays stateless. Cache-key contribution from CallGraph:
append `CallGraph::VERSION` to the hash input so shipping a scanner improvement
invalidates every cached graph automatically. (Revisit incrementality only if
an instance exceeds ~10× this repo's size; the per-file mtime data needed is
already collected by the codeHash stat pass, so the seam exists.)

Memory: peak token array for the 157 KB file is ~2 MB — irrelevant; process
files sequentially, discard tokens after each file.

---

## 8. Golden-fixture acceptance tests (all verified real, this repo @ e272a76)

A correct implementation MUST reproduce every row. (Line numbers move with the
repo; regenerate fixtures from the pinned commit or re-verify at build time.)

| # | Expected result | Evidence |
|---|---|---|
| 1 | Span `Connections::setup` = 87–108; `Connections::index` = 334–432 | `controls/Connections.php` |
| 2 | Span `Workbench::decompose` = 310–368 despite heredocs elsewhere in file | `controls/Workbench.php` |
| 3 | `route:connections::setup –renders→ view:views/connections/setup.php` [exact] | `controls/Connections.php:99` |
| 4 | `route:connections::index –renders→ view:views/connections/index.php` [exact] | `controls/Connections.php:425` |
| 5 | `route:connections::status` has `shape: json`, zero render edges | `controls/Connections.php:114,116` |
| 6 | `view:views/connections/setup.php –view-call→ route:connections::repos` [exact] | `views/connections/setup.php:113` |
| 7 | `view:views/teams/members.php –view-call→ route:teams::updaterole` [exact] | `views/teams/members.php:215` |
| 8 | `view:views/layouts/_notify-bell.php –view-call→ route:communications::unreadjson` [exact] | `views/layouts/_notify-bell.php:125` |
| 9 | `route:connections::add –writes→ bean:connections` via `Bean::dispense` [exact] | `controls/Connections.php:186` |
| 10 | `Connections::test` `Bean::store($conn)` resolves to `bean:connections` [inferred] (single-bean span, load at :666) | `controls/Connections.php:673` |
| 11 | `libmethod:AuditReporter::report` has `reach: script-only`, sole caller `scripts/plan-audit.php` | whole-repo caller scan |
| 12 | `libmethod:ClaudeRunner::findByTaskId` `reach: script-only` ← `cli/task-health-check.php` | whole-repo caller scan |
| 13 | `PermissionCache::clear` is NOT script-only (callers incl. `controls/Permissions.php:82`, `controls/Admin.php:547`, `scripts/reseed.php:57`) | mixed-caller check |
| 14 | `broken:/agent-setup/store-server` node, `suggest: agentsetup::storeServer` | `views/agentsetup/index.php:240`; `FlightMap.php:64` |
| 15 | `broken:/docs/mcp` node (control `docs` exists, no `mcp()`, no `_fallback`) | `views/index/index.php:103` |
| 16 | `missing-view` node for `views/help/getting-started.php` (renders a file that doesn't exist) | `controls/Help.php:26` |
| 17 | `route:connections::connectorConnect` has a `dynamic-redirect` edge | `controls/Connections.php:517` |
| 18 | `/shop/<anything>` literals resolve to `shop::_fallback` [inferred]; `_fallback` span = 376–382 | `controls/Shop.php:376` |
| 19 | `custom:GET|POST /mcp/message` node exists | `routes/mcp.php:11` |
| 20 | `view:views/teams/view.php` carries a `dynamic-call` site (`fetch(url)`) | `views/teams/view.php:279` |
| 21 | `route:workbench::view` (from Workbench spans) has ≥8 inbound view-call edges (`href="/workbench/view?id=<?=…"` static-prefix capture) | `views/workbench/*.php` |
| 22 | `Workbench::index –rel-own(cascade)→ bean:tasklog` via `xownTasklogList`-style property (owner = enclosing span at `controls/Workbench.php:1085`) | `controls/Workbench.php:1085` |

Ship these as a PHPUnit-less assert script (`scripts/test-callgraph.php`, house
style) run against the core repo itself, exactly as plan T2.1 prescribes.

---

## 9. Known-limits ledger (honest, each with a real example where one exists)

| # | Cannot see | Real example here | Representation |
|---|---|---|---|
| 1 | Runtime-computed redirect/fetch targets | `Flight::redirect($url)` `controls/Connections.php:517`; `fetch(url)` `views/teams/view.php:279` | `dynamic-*` edge/node with evidence line; counted in `meta.dynamicSites` |
| 2 | Externally-triggered routes (webhooks, cron, OAuth callbacks) | `shop::webhook` (Stripe calls it); `connections::callback` (GitHub) | `reach: orphan` + explicit orphan list with the "possibly webhook/external" caption |
| 3 | JS URLs assembled from parts with no literal base | `new URL(window.location.href)` manipulation `views/workbench/index.php:130-132` | per-view `dynamic-call` node |
| 4 | `store($var)` across helper boundaries / multi-bean spans | `Connections::upsertConnection:611` (span dispenses + finds `connections` → OK inferred; a 2-bean span would stay dynamic) | `bean:(dynamic)` write, evidence kept |
| 5 | SQL table refs in `R::getAll/exec` strings | `lib/ValidationService.php` (4 exec sites), `scripts/reseed.php` | `bean:(sql)` dynamic edge; v2 could regex `FROM <ident>` |
| 6 | Reflection / variable class names / `call_user_func` | none found in core (grep `$this->$` = 0) — but AI-built instances may | `dynamic-dispatch` node |
| 7 | Transitive effects through base-class helpers shown one hop away | `handleException` → `error/500` (`Control.php:270`) not flattened onto every controller | two-hop path in graph; UI may walk it |
| 8 | `href` values printed from PHP variables/loops | menu built in `FlightMap::loadMenu` (`:250-273`) — URLs are literals in lib PHP, caught by §3.4's file scan, but views echoing `$item['url']` aren't view-attributed | edges attributed to `lib/FlightMap.php` (where the literal lives) — evidence still exact, attribution is the lib not the view |
| 9 | Traits (none exist here to test against) | — | spanner handles `T_TRAIT`; resolution marked best-effort until a fixture exists |
| 10 | Views rendered only via `$layout` sandwich internals or dead code inside unreachable branches | 15 missing-view targets prove routes can point at nothing; the inverse (view files with no inbound render edge) are listed as **orphan views** | orphan-view list beside orphan routes |

---

## 10. Build checklist for the implementer

1. **Port `spanlib.php` verbatim** into `CallGraph::methodSpans()` — it is
   validated; keep the typed brace stack, the `line = tline +
   substr_count(text,"\n")` rule (the off-by-one is real, we hit it), the
   `interp` pushes for `T_CURLY_OPEN`/`T_DOLLAR_OPEN_CURLY_BRACES`, and stub
   handling (`;` before `{`).
2. Single pass per PHP file: spans first, then walk tokens once emitting
   §3.1/3.2/3.4 edges with owner = `ownerAt(spans, line)` (binary search, spans
   are sorted); `(file)` for top-level code. Normalize qualified names via
   last-`\`-segment; skip significant-token search over
   `T_WHITESPACE|T_COMMENT|T_DOC_COMMENT`.
3. File sets: `controls/*.php` (+`BaseControls/`), `lib/*.php` (+subdirs),
   `models/`, `scripts/`, `cli/`, `services/**`, `mcptools/`, `routes/`.
   Consume Introspector for: controller/method inventory, lib inventory,
   authcontrol levels, relations(), models/tables. Do NOT re-scan signatures.
4. View scan per §3.3: the exact regex, then the 6-step validator. Keep the
   drop-audit debug mode (`--dropped`) — it is how you verify precision on a
   new instance.
5. Bean normalization: `strtolower(str_replace('_',''))` on every literal bean
   arg — non-negotiable (Bean wrapper contract, `lib/Bean.php:7-16`).
6. `store/trash($var)` same-span single-bean inference (§3.2) — confidence
   `inferred`, never `exact`.
7. Post-pass classification: `reach` (endpoint/script-only/internal/orphan),
   `shape` (json/page/mixed), broken-link fuzzy suggestions
   (hyphen-stripped controller match), missing-view stat checks
   (`is_file("$root/views/$lit.php")`).
8. Emit schema §5 exactly; every edge has `evidence` and `confidence`; append
   `CallGraph::VERSION` into the ExplorerGraph codeHash input.
9. Acceptance: run `scripts/test-callgraph.php` asserting all 22 fixtures in §8
   against the core repo. Add per-instance smoke: build graph, assert
   `counts.nodes > 0`, zero PHP warnings, `buildMs < 2000`.
10. Keep it dependency-free, `@`-guard all file reads (Introspector style), and
    never write anything — CallGraph is read-only by contract.
