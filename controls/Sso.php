<?php
/**
 * Sso — the Explorer's identity boundary. All logic is the shared Sidecar Kit base
 * (verify handoff token, burn nonce, re-check member + `explorer` feature grant vs
 * core, establish the session). Config ([sidecar] name/feature/landing) drives it.
 */

namespace app;

class Sso extends \app\Sidecar\Sso {}
