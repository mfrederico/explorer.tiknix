<?php
/**
 * Explore — the Architecture Explorer page + its JSON endpoints (Phase 1).
 *
 * index() renders the single wide page. graph()/rows() are same-origin JSON.
 * EVERY request: (1) requires the sidecar session (set by Sso::consume); (2)
 * re-derives the member's accessible instance set from CORE and resolves the
 * requested instance through the ownership gate (ExplorerAccess) — a slug/URL is
 * a lookup hint, never authorization; un-owned/unknown → 403 uniformly; (3) builds
 * the instance filesystem path ONLY from the resolved bean's own slug/app.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Bean;
use app\mcptools\Introspector;
use app\Sidecar\Kernel;
use app\Sidecar\Access;
use app\Sidecar\Sso;

class Explore extends Control {

    /** GET /explore — the page. */
    public function index($params = []) {
        $s = $this->session();
        if (!$s) { $this->requireLaunch(); return; }
        $access = new Access($this->core());
        $list   = $access->instances((int) $s['member_id']);

        // ONE resolution point — never a local match, never a default.
        $project = Sso::projectInstance($access, (int) $s['member_id']);

        $this->render('explore/index', [
            'instances'   => $list,
            'project'     => $project,
            'projectsUrl' => Sso::projectPickerUrl(),
            'email'       => $s['email'],
            'initial'     => (string) (Flight::request()->query->url ?? ''),
        ], false);
    }

    /** GET /explore/graph?url=<instance-url|slug> — the cached grid model (JSON). */
    public function graph($params = []) {
        [$s, $inst] = $this->guardInstance();
        if (!$inst) return;
        $dir = $this->instanceDir($inst);
        if (!is_dir($dir)) { Flight::jsonError('Instance workspace not found on disk.', 404); return; }

        $codeHash = (new ExplorerGraph($dir))->codeHash();
        $cached = Bean::findOne('explorergraph',
            'instance_id = ? AND code_hash = ?', [(int) $inst['id'], $codeHash]);
        if ($cached && $cached->id) {
            $model = json_decode(gzinflate(base64_decode((string) $cached->graphJson)), true) ?: [];
        } else {
            $model = (new ExplorerGraph($dir))->build();
            $row = Bean::dispense('explorergraph');
            $row->instanceId = (int) $inst['id'];
            $row->codeHash   = $codeHash;
            $row->graphJson  = base64_encode(gzdeflate((string) json_encode($model)));
            $row->builtAt    = date('Y-m-d H:i:s');
            Bean::store($row);
            $this->pruneGraphCache((int) $inst['id'], $codeHash);
        }
        Flight::json(['instance' => ['id' => $inst['id'], 'slug' => $inst['slug'], 'name' => $inst['name']], 'model' => $model]);
    }

    /** GET /explore/rows?url=<inst>&table=<t>&offset=N — paginated select * (JSON). */
    public function rows($params = []) {
        [$s, $inst] = $this->guardInstance();
        if (!$inst) return;
        $dir = $this->instanceDir($inst);
        $table  = (string) (Flight::request()->query->table ?? '');
        $offset = max(0, (int) (Flight::request()->query->offset ?? 0));
        $data = (new Introspector($dir))->rows($table, 50, $offset);   // guarded to real tables
        Flight::json(['table' => $table, 'offset' => $offset] + $data);
    }

    // ---- guards / helpers --------------------------------------------------

    /** The sidecar session, or null. */
    private function session(): ?array {
        return Sso::session();
    }

    private function core(): ?\PDO {
        return Kernel::coreDb();
    }

    /**
     * Require a session AND resolve+authorize the requested instance. Returns
     * [session, instanceRow] or [session, null] having already emitted the error.
     */
    private function guardInstance(): array {
        $s = $this->session();
        if (!$s) { Flight::jsonError('Not signed in.', 401); return [null, null]; }
        $core = $this->core();
        if (!$core) { Flight::jsonError('Core directory unavailable.', 503); return [$s, null]; }
        $access = new Access($core);
        $url = (string) (Flight::request()->query->url ?? '');
        if ($url === '' || $url === '/') {
            // No explicit target → the selected project. Never "first accessible".
            $inst = Sso::projectInstance($access, (int) $s['member_id']);
            if (!$inst) {
                Flight::jsonError('No project selected — choose one at ' . Sso::projectPickerUrl(), 409);
                return [$s, null];
            }
            return [$s, $inst];
        }
        $inst = $access->resolveInstance($url, (int) $s['member_id']);
        if (!$inst) { Flight::jsonError('That instance was not found or you do not have access to it.', 403); return [$s, null]; }
        return [$s, $inst];
    }

    /** Instance dir built ONLY from the resolved bean's slug/app (never client input). */
    private function instanceDir(array $inst): string {
        $parent = dirname(rtrim((string) Flight::get('sidecar.core_root'), '/'));  // /var/www/html/default
        $app = $inst['app'] !== '' ? $inst['app'] : 'tiknix';
        return $parent . '/' . $inst['slug'] . '.' . $app;
    }

    private function requireLaunch(): void {
        $coreUrl = rtrim((string) (Flight::get('sidecar.core_url') ?? ''), '/');
        if ($coreUrl !== '') { Flight::redirect($coreUrl . '/sidecar/launch/explorer'); return; }
        Flight::halt(403, 'Launch the Architecture Explorer from your tiknix dashboard.');
    }

    /** Keep only the newest cache row per instance (codeHash changes on any edit). */
    private function pruneGraphCache(int $instanceId, string $keepHash): void {
        try {
            foreach (Bean::find('explorergraph', 'instance_id = ? AND code_hash != ?', [$instanceId, $keepHash]) as $old) {
                Bean::trash($old);
            }
        } catch (\Throwable $e) {}
    }
}
