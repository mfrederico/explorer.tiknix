<?php
/**
 * Sso — the sidecar's identity boundary.
 *
 * consume() is the ONLY way a member gets a session on the Explorer. It verifies
 * the signed handoff token core minted (lib/ExplorerToken, shared secret), burns
 * the nonce single-use (replay → reject), re-checks against core's db that the
 * member is still active AND still has the `explorer` grant (so a revoke on core
 * propagates), regenerates the session id, and stores ONLY {member_id, level}.
 * From there every request re-derives access from core (ExplorerAccess).
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Bean;

class Sso extends Control {

    /** GET /sso/consume?token=… — validate the handoff and establish the session. */
    public function consume($params = []) {
        $token  = (string) (Flight::request()->query->token ?? '');
        $secret = (string) (Flight::get('explorer.sso_secret') ?? '');

        $claims = $token !== '' ? ExplorerToken::verify($token, $secret, 'explorer') : null;
        if (!$claims) { $this->deny('This sign-in link is invalid or has expired.'); return; }

        // Burn the nonce single-use.
        $nonce = (string) $claims['nonce'];
        if (Bean::findOne('ssononce', 'nonce = ?', [$nonce])) {
            $this->deny('This sign-in link has already been used.'); return;
        }
        $n = Bean::dispense('ssononce');
        $n->nonce = $nonce;
        $n->expiresAt = date('Y-m-d H:i:s', (int) $claims['exp']);
        $n->createdAt = date('Y-m-d H:i:s');
        Bean::store($n);
        $this->pruneNonces();

        // Re-verify member + feature grant against CORE (never trust the token alone).
        $core = ExplorerInit::coreDb();
        if (!$core) { $this->deny('The Explorer cannot reach the core directory right now.'); return; }
        $access = new ExplorerAccess($core);
        $memberId = (int) $claims['member_id'];
        $member = $access->memberIfActive($memberId);
        if (!$member)               { $this->deny('Your account is not active.'); return; }
        if (!$access->featureEnabled($memberId)) { $this->deny('The Architecture Explorer is not enabled for your account.'); return; }

        // Establish the session — the token/level from CORE, not the token's snapshot.
        session_regenerate_id(true);
        $_SESSION['explorer'] = [
            'member_id'  => $memberId,
            'level'      => (int) $member['level'],
            'email'      => (string) $member['email'],
            'checked_at' => time(),
        ];
        Flight::redirect('/explore');
    }

    /** GET /sso/logout — drop the session. */
    public function logout($params = []) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        $core = rtrim((string) Flight::get('explorer.core_root'), '/');
        Flight::redirect('/');
    }

    private function deny(string $msg): void {
        Flight::halt(403, '<!doctype html><meta charset="utf-8"><title>Explorer</title>'
            . '<body style="font-family:system-ui;background:#0b1530;color:#eaedf5;display:flex;'
            . 'min-height:100vh;align-items:center;justify-content:center;text-align:center">'
            . '<div><h1 style="font-weight:800">Architecture Explorer</h1><p style="color:#9ba4bd">'
            . htmlspecialchars($msg) . '</p></div>');
    }

    /** Opportunistically drop expired nonces so the table stays tiny. */
    private function pruneNonces(): void {
        try {
            foreach (Bean::find('ssononce', 'expires_at < ?', [date('Y-m-d H:i:s')]) as $old) {
                Bean::trash($old);
            }
        } catch (\Throwable $e) {}
    }
}
