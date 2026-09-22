<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class WorkspaceMembership extends Pivot
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'workspace_memberships';

    protected $fillable = ['workspace_id', 'user_id', 'role', 'status', 'created_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
