<?php
/**
 * ExplorerAccess — the Explorer's owner/team scoping gate (fail-closed).
 *
 * Reproduces, verbatim in raw read-only SQL, the exact ownership model the core
 * Workbench uses (`lib/TaskAccessControl` getMemberTeamIds / getSharedInstanceIds
 * / getAccessibleInstanceIds / canAccessInstance). A member may only ever see an
 * instance they OWN or that is shared with one of their teams — never "all
 * instances on the box". Every Explorer request re-derives the allowed set
 * server-side from the CORE database; a slug/URL from the client is a lookup hint,
 * never an authorization input.
 *
 * Core tables (read-only): instance(member_id, slug, app), teammember(member_id,
 * team_id), instance_team(instance_id, team_id), member(status, level), settings.
 */

namespace app;

class ExplorerAccess {

    public function __construct(private \PDO $core) {}

    /** Team ids the member belongs to. array_values-safe (sequential). */
    public function teamIds(int $memberId): array {
        $st = $this->core->prepare('SELECT team_id FROM teammember WHERE member_id = ?');
        $st->execute([$memberId]);
        return array_values(array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** Instance ids shared (m2m) with any of the member's teams. */
    public function sharedInstanceIds(int $memberId): array {
        $teamIds = $this->teamIds($memberId);
        if (!$teamIds || !$this->tableExists('instance_team')) return [];
        $ph = implode(',', array_fill(0, count($teamIds), '?'));
        $st = $this->core->prepare("SELECT DISTINCT instance_id FROM instance_team WHERE team_id IN ($ph)");
        $st->execute(array_values($teamIds));
        return array_values(array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** Instance ids the member OWNS ∪ ones shared with their teams — the allowed set. */
    public function accessibleInstanceIds(int $memberId): array {
        $owned = [];
        if ($this->tableExists('instance')) {
            $st = $this->core->prepare('SELECT id FROM instance WHERE member_id = ?');
            $st->execute([$memberId]);
            $owned = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
        }
        return array_values(array_unique(array_merge($owned, $this->sharedInstanceIds($memberId))));
    }

    /** True iff the member owns the instance or it is shared with one of their teams. */
    public function canAccess(int $memberId, int $instanceId): bool {
        return $instanceId > 0 && in_array($instanceId, $this->accessibleInstanceIds($memberId), true);
    }

    /** Accessible instances as [{id, slug, app, name, owned}] for the picker (owned set only). */
    public function instances(int $memberId): array {
        $ids = $this->accessibleInstanceIds($memberId);
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->core->prepare("SELECT id, slug, app, display_name, member_id FROM instance WHERE id IN ($ph) ORDER BY slug");
        $st->execute(array_values($ids));
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'    => (int) $r['id'],
                'slug'  => (string) $r['slug'],
                'app'   => (string) ($r['app'] ?? ''),
                'name'  => (string) ($r['display_name'] ?? $r['slug']),
                'owned' => (int) $r['member_id'] === $memberId,
            ];
        }
        return $out;
    }

    /**
     * Resolve a client-supplied URL/host/slug to an instance the member may access,
     * or null (→ 403). The slug is validated, matched to an instance BEAN, and the
     * ownership check applied. The caller builds any filesystem path from the
     * returned row's own slug/app — NEVER from client input. Fail-closed.
     */
    public function resolveInstance(string $urlOrSlug, int $memberId): ?array {
        $slug = $this->slugFromInput($urlOrSlug);
        if ($slug === null) return null;
        if (!$this->tableExists('instance')) return null;
        $st = $this->core->prepare('SELECT id, slug, app, display_name, member_id FROM instance WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;                                        // unknown slug → 403 (uniform)
        if (!$this->canAccess($memberId, (int) $r['id'])) return null; // un-owned → 403 (uniform)
        return [
            'id'   => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'app'  => (string) ($r['app'] ?? ''),
            'name' => (string) ($r['display_name'] ?? $r['slug']),
        ];
    }

    /**
     * Extract a validated instance slug from a URL, host, or bare slug. Returns null
     * on anything malformed. Accepts "https://<slug>.tiknix.com/…", "<slug>.tiknix.com",
     * or "<slug>". Never returns a path fragment.
     */
    public function slugFromInput(string $in): ?string {
        $in = trim($in);
        if ($in === '' || $in === '/') return null;
        // Full URL or host → take the first label of the host.
        if (preg_match('#^https?://#i', $in) || strpos($in, '.') !== false || strpos($in, '/') !== false) {
            $host = parse_url(preg_match('#^https?://#i', $in) ? $in : "https://{$in}", PHP_URL_HOST) ?: '';
            $label = explode('.', $host)[0] ?? '';
            $in = $label;
        }
        $in = strtolower($in);
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,48}[a-z0-9])?$/', $in) ? $in : null;
    }

    /** Re-check at SSO consume: member exists + is active. Returns row or null. */
    public function memberIfActive(int $memberId): ?array {
        $st = $this->core->prepare('SELECT id, level, status, email FROM member WHERE id = ? LIMIT 1');
        $st->execute([$memberId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;
        $status = strtolower((string) ($r['status'] ?? 'active'));
        if ($status !== '' && !in_array($status, ['active', 'enabled', '1'], true)) return null;
        return ['id' => (int) $r['id'], 'level' => (int) $r['level'], 'email' => (string) ($r['email'] ?? '')];
    }

    /** Re-check the `explorer` feature grant against the core settings table. */
    public function featureEnabled(int $memberId): bool {
        $st = $this->core->prepare("SELECT setting_value FROM settings WHERE member_id = ? AND setting_key = 'feature.explorer' LIMIT 1");
        $st->execute([$memberId]);
        return (string) $st->fetchColumn() === '1';
    }

    private function tableExists(string $t): bool {
        $st = $this->core->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1");
        $st->execute([$t]);
        return (bool) $st->fetchColumn();
    }
}
