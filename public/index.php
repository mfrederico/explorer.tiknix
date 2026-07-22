<?php
/**
 * Explorer sidecar front controller.
 *
 * HARD ALLOWLIST GATE (security-critical): this framework defaults an undefined
 * route to PUBLIC (PermissionCache::check → LEVELS['PUBLIC']), so on a tiknix
 * clone every core controller would be guest-reachable. Deny is therefore ACTIVE:
 * only the explorer's own controllers may run here. Anything else 403s BEFORE any
 * app code loads — independent of authcontrol rows. (Belt-and-suspenders: the
 * provisioning step also deletes non-explorer controllers from the clone.)
 */

// First path segment (the controller slug the auto-router would dispatch).
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$seg  = strtolower(explode('/', trim($path, '/'))[0] ?? '');

const EXPLORER_ALLOW = ['', 'sso', 'explore', 'index', 'error'];
if (!in_array($seg, EXPLORER_ALLOW, true)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit("Not available on the Architecture Explorer sidecar.\n");
}

require dirname(__DIR__) . '/explorer-init.php';
(new app\ExplorerInit('conf/config.ini'))->run();
