<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER POLICY workspace_memberships_select ON workspace_memberships USING (
                EXISTS (
                    SELECT 1 FROM builderad_signed_context() context
                    WHERE context.actor_user_id = workspace_memberships.user_id
                      AND workspace_memberships.status = 'active'
                      AND (context.phase = 'principal' AND context.workspace_id IS NULL
                           OR context.phase = 'workspace'
                              AND context.workspace_id = workspace_memberships.workspace_id)
                )
            )
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reopening the membership isolation boundary requires an explicit migration.');
    }
};
