<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only a current founder with an active trusted bootstrap reading grant
        // retains content access. Human staff.view grants never imply publishing.
        DB::unprepared(<<<'SQL'
INSERT INTO security_action_grants
    (subject_user_id, granted_by, source, action, scope_kind, workspace_id,
     subject_generation, reason, status, version, created_at, updated_at)
SELECT p.user_id, NULL, 'founder_bootstrap', action.name, 'platform', NULL,
       p.generation, 'content_authority_transition', 'active', 1,
       clock_timestamp(), clock_timestamp()
FROM principal_lifecycles p
JOIN internal_personnel i ON i.user_id = p.user_id AND i.kind = 'founder' AND i.status = 'active'
CROSS JOIN (VALUES ('content.read'), ('content.edit'), ('content.publish')) AS action(name)
WHERE p.principal_class = 'staff' AND p.status = 'current'
  AND EXISTS (
    SELECT 1 FROM security_action_grants g
    WHERE g.subject_user_id = p.user_id AND g.subject_generation = p.generation
      AND g.source = 'founder_bootstrap' AND g.action = 'staff.view'
      AND g.scope_kind = 'platform' AND g.status = 'active' AND g.revoked_at IS NULL
      AND (g.expires_at IS NULL OR g.expires_at > clock_timestamp())
  )
  AND NOT EXISTS (
    SELECT 1 FROM security_action_grants existing
    WHERE existing.subject_user_id = p.user_id AND existing.subject_generation = p.generation
      AND existing.action = action.name AND existing.scope_kind = 'platform'
  );
SQL);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM security_action_grants WHERE source = 'founder_bootstrap' AND reason = 'content_authority_transition' AND action IN ('content.read', 'content.edit', 'content.publish')");
    }
};
