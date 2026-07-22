<?php
/**
 * Architecture Explorer — front controller (on the Sidecar Kit).
 *
 * The hard allowlist gate runs BEFORE any app code (a missing authcontrol row
 * defaults to PUBLIC in this framework, so deny is active). Then the shared
 * Kernel boots and dispatches to the plugin's own controllers.
 */

$cfg      = @parse_ini_file(dirname(__DIR__) . '/conf/config.ini', true) ?: [];
$coreRoot = rtrim($cfg['sidecar']['core_root'] ?? '/var/www/html/default/tiknix', '/');

require $coreRoot . '/lib/Sidecar/Kernel.php';

app\Sidecar\Kernel::guard(['', 'sso', 'explore', 'index', 'error']);

(new app\Sidecar\Kernel(dirname(__DIR__), [
    'index'   => 'Index',
    'sso'     => 'Sso',
    'explore' => 'Explore',
]))->run();
