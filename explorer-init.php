<?php
/**
 * Explorer sidecar initializer (the sidecar's bootstrap).
 *
 * The Explorer is a lean, hardened FlightPHP app that REUSES core tiknix's vendor
 * (Flight, RedBean) and shared classes (Introspector, ExplorerToken, Bean,
 * FlightMap, functions), while its OWN controls/ + lib/ take precedence. It uses
 * its own SQLite db for sessions/nonces/graph-cache and opens a READ-ONLY handle
 * to core's db for identity + ownership (ExplorerAccess).
 *
 * Security posture: builder tooling off, no login/registration routes, and a
 * hard allowlist gate in public/index.php (a missing authcontrol row defaults to
 * PUBLIC in this framework, so deny is ACTIVE, not implied).
 */

namespace app;

use \Flight as Flight;
use RedBeanPHP\R;

class ExplorerInit {

    private array $config;
    private string $root;
    private string $coreRoot;

    public function __construct(string $configFile = 'conf/config.ini') {
        $this->root     = __DIR__;
        $this->config   = @parse_ini_file($this->root . '/' . $configFile, true) ?: [];
        $this->coreRoot = rtrim((string) ($this->config['explorer']['core_root'] ?? '/var/www/html/default/tiknix'), '/');

        // ORDER MATTERS: require Composer's autoloader FIRST — it registers itself with
        // prepend=true. THEN register ours (also prepend), so ours lands ahead of it and
        // sidecar controllers (app\Index, …) win over core's identically-named classes.
        require $this->coreRoot . '/vendor/autoload.php';       // vendor + core composer PSR-4 (fallback)
        $this->registerAutoloader();          // sidecar-first (must be after composer's register)
        require_once $this->coreRoot . '/lib/FlightMap.php';    // LEVELS, CLASS_NAMESPACE, Flight maps
        require_once $this->coreRoot . '/lib/functions.php';    // is_control_plane(), h(), …

        $this->flattenConfigIntoFlight();
        $this->connectDatabases();
        $this->startSession();

        Flight::set('flight.views.path', $this->root . '/views');
        Flight::set('build', false);                            // never auto-provision permissions here
        Flight::set('explorer.core_root', $this->coreRoot);

        // Minimal logger stub — core's shared code calls Flight::get('log')->debug/error/…
        // The sidecar has no Monolog stack; no-op everything, surface errors to error_log.
        Flight::set('log', new class {
            public function __call($m, $a) {
                if (in_array($m, ['error', 'critical', 'alert', 'emergency'], true)) {
                    error_log('[explorer] ' . (is_string($a[0] ?? null) ? $a[0] : json_encode($a[0] ?? null)));
                }
            }
        });

        $this->registerRoutes();
    }

    public function run(): void { Flight::start(); }

    /**
     * Explicit routes to the SIDECAR's own controllers. We do NOT use core's
     * Flight::defaultRoute() — its security check only dispatches classes under
     * core's controls/ (rejecting the sidecar's). The allowlist gate
     * (public/index.php) + per-controller session/ownership guards are the
     * security here, not authcontrol.
     */
    private function registerRoutes(): void {
        // Single catch-all in the SAME shape core's defaultRoute uses (proven to match
        // in this Flight version), but dispatching to the sidecar's OWN controllers via
        // an explicit allowlist map — never core's controls/. Defense-in-depth with the
        // public/index.php gate.
        $map = ['index' => 'Index', 'sso' => 'Sso', 'explore' => 'Explore'];
        Flight::route('/(@class(/@method(/@op(/@opid(/.*?)))))',
            function ($class = null, $method = null) use ($map) {
                $class  = strtolower($class ?: 'index');
                $method = preg_replace('/[^a-z0-9]/i', '', (string) ($method ?: 'index')) ?: 'index';
                $c = $map[$class] ?? null;
                if (!$c) { Flight::notFound(); return; }
                $fq = 'app\\' . $c;
                if (!class_exists($fq) || !method_exists($fq, $method)) { Flight::notFound(); return; }
                (new $fq())->$method([]);
            });
    }

    /** Read-only PDO handle to CORE's SQLite db (identity + ownership scoping). */
    public static function coreDb(): ?\PDO {
        $root = rtrim((string) (Flight::get('explorer.core_root') ?: ''), '/');
        $ini  = @parse_ini_file("$root/conf/config.ini", true) ?: [];
        $path = $ini['database']['path'] ?? '';
        if (($ini['database']['type'] ?? '') !== 'sqlite' || $path === '') return null;
        $abs = $path[0] === '/' ? $path : "$root/$path";
        if (!is_file($abs)) return null;
        try {
            $pdo = new \PDO('sqlite:' . $abs);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
            return $pdo;   // read-only by convention: the sidecar never writes core
        } catch (\Throwable $e) { return null; }
    }

    /** app\* classes resolve from the SIDECAR tree first; unresolved fall through to core. */
    private function registerAutoloader(): void {
        $root = $this->root;
        spl_autoload_register(function (string $class) use ($root): void {
            if (strncmp($class, 'app\\', 4) !== 0) return;
            foreach (self::candidatePaths($class) as $rel) {
                $f = "$root/$rel";
                if (is_file($f)) { require $f; return; }
            }
        }, true, true);  // prepend so the sidecar wins over composer's core PSR-4
    }

    /** Mirror composer's PSR-4 map so a sidecar override lands in the same place. */
    private static function candidatePaths(string $class): array {
        if (strncmp($class, 'app\\mcptools\\', 13) === 0)
            return ['mcptools/' . str_replace('\\', '/', substr($class, 13)) . '.php'];
        if (strncmp($class, 'app\\BaseControls\\', 17) === 0)
            return ['controls/BaseControls/' . str_replace('\\', '/', substr($class, 17)) . '.php'];
        if (strncmp($class, 'app\\services\\', 13) === 0)
            return ['services/' . str_replace('\\', '/', substr($class, 13)) . '.php'];
        $tail = str_replace('\\', '/', substr($class, 4)) . '.php';
        return ["controls/$tail", "lib/$tail"];
    }

    private function flattenConfigIntoFlight(): void {
        foreach ($this->config as $section => $values) {
            if (is_array($values)) {
                foreach ($values as $key => $value) Flight::set("{$section}.{$key}", $value);
            } else {
                Flight::set($section, $values);
            }
        }
    }

    private function connectDatabases(): void {
        // Primary: the sidecar's OWN sqlite (sessions/nonces/graph cache).
        $db  = $this->config['database']['path'] ?? 'data/explorer.db';
        $abs = $db[0] === '/' ? $db : $this->root . '/' . $db;
        @mkdir(dirname($abs), 0775, true);
        if (!R::hasDatabase('default')) {
            R::setup('sqlite:' . $abs);
            R::freeze(false);   // fluid: auto-create explorergraph/explorerask/ssononce tables
        }
    }

    private function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_name((string) ($this->config['app']['session_name'] ?? 'EXPLORER_SESSION'));
            session_start();
        }
    }
}
