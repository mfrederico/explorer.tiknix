<?php
/**
 * ExplorerGraph — Phase 1 model builder for the Explorer page.
 *
 * Pure, cacheable transform: an instance directory → the grid model the page
 * needs (authcontrol grouped by control for the top ribbon, each control's
 * methods+levels, and the table list for the `select *` drill). Built entirely
 * from the core Introspector (mcptools/Introspector) pointed at the instance root
 * — NO call graph yet (that is Phase 2's CallGraph). The caller passes a
 * pre-resolved, ownership-checked instance directory; ExplorerGraph never touches
 * access control.
 *
 * codeHash() is a content fingerprint (per-dir fileCount.maxMtime + authcontrol
 * shape) so a `git pull` or an authcontrol change in the instance invalidates the
 * cached model on the next view — the same content-addressed strategy as
 * lib/PermissionCache. Rows are fetched on demand (Introspector::rows), not baked
 * into the model.
 */

namespace app;

use app\mcptools\Introspector;

class ExplorerGraph {

    /** Bump when the model shape changes so cached rows invalidate. */
    public const VERSION = 1;

    /** Level → short label, for the ribbon color chips (mirrors LEVELS). */
    private const LEVEL_LABEL = [1 => 'ROOT', 50 => 'ADMIN', 100 => 'MEMBER', 101 => 'PUBLIC'];

    public function __construct(private string $instanceDir) {}

    /** The grid model: {controls:[...], tables:[...], meta:{...}}. */
    public function build(): array {
        $intro = new Introspector($this->instanceDir);

        // Group authcontrol rows by control for the ribbon; carry per-method levels.
        $byControl = [];
        foreach ($intro->authcontrol() as $r) {
            $c = (string) $r['control'];
            $byControl[$c][] = ['method' => (string) $r['method'], 'level' => (int) $r['level']];
        }

        // Which controls actually have a controller file (vs authcontrol-only rows).
        $ctrlFiles = [];
        foreach (glob(rtrim($this->instanceDir, '/') . '/controls/*.php') ?: [] as $f) {
            $ctrlFiles[strtolower(basename($f, '.php'))] = true;
        }

        $controls = [];
        foreach ($byControl as $control => $methods) {
            usort($methods, fn($a, $b) => [$a['level'], $a['method']] <=> [$b['level'], $b['method']]);
            $levels = array_column($methods, 'level');
            $dominant = $levels ? min($levels) : 101;   // most-privileged wins the chip
            $controls[] = [
                'control'       => $control,
                'methods'       => $methods,
                'routeCount'    => count($methods),
                'dominantLevel' => $dominant,
                'levelLabel'    => self::LEVEL_LABEL[$dominant] ?? (string) $dominant,
                'hasController' => isset($ctrlFiles[strtolower($control)]),
            ];
        }
        usort($controls, fn($a, $b) => strcmp($a['control'], $b['control']));

        return [
            'controls' => $controls,
            'tables'   => $intro->tables(),
            'routes'   => $intro->routeLiterals(),
            'meta'     => [
                'codeHash' => $this->codeHash(),
                'version'  => self::VERSION,
                'controlCount' => count($controls),
            ],
        ];
    }

    /**
     * Content fingerprint of the instance for cache invalidation. Cheap stat pass
     * over the code dirs + the authcontrol shape; no file bodies read.
     */
    public function codeHash(): string {
        $root = rtrim($this->instanceDir, '/');
        $parts = ['v' . self::VERSION];
        foreach (['controls', 'views', 'lib', 'models', 'routes', 'mcptools', 'services'] as $dir) {
            $count = 0; $maxMtime = 0;
            foreach ($this->walk("$root/$dir") as $f) {
                $count++;
                $m = @filemtime($f) ?: 0;
                if ($m > $maxMtime) $maxMtime = $m;
            }
            $parts[] = "$dir:$count.$maxMtime";
        }
        // authcontrol shape (rows + max id + level sum) so a permission seed invalidates.
        $db = "$root/database";
        $parts[] = 'ac:' . $this->authcontrolFingerprint($root);
        return sha1(implode('|', $parts));
    }

    /** Recursively yield *.php files under a dir (bounded, cheap). */
    private function walk(string $dir): \Generator {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -4) === '.php') yield $f->getPathname();
        }
    }

    /** rows.maxid.levelsum of the instance's authcontrol, or '0' if unreadable. */
    private function authcontrolFingerprint(string $root): string {
        $ini  = @parse_ini_file("$root/conf/config.ini", true) ?: [];
        $path = $ini['database']['path'] ?? '';
        if (($ini['database']['type'] ?? '') !== 'sqlite' || $path === '') return '0';
        $abs = $path[0] === '/' ? $path : "$root/$path";
        if (!is_file($abs)) return '0';
        try {
            $db = new \PDO('sqlite:' . $abs);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
            $r = $db->query('SELECT COUNT(*) c, COALESCE(MAX(id),0) m, COALESCE(SUM(level),0) s FROM authcontrol')
                ->fetch(\PDO::FETCH_ASSOC);
            return "{$r['c']}.{$r['m']}.{$r['s']}";
        } catch (\Throwable $e) { return '0'; }
    }
}
