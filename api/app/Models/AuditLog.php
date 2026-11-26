<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One money change: who, what, old and new values. Append-only (a database trigger rejects UPDATE and DELETE). */
class AuditLog extends Model
{
    const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['changes' => 'array', 'auditable_id' => 'integer', 'actor_id' => 'integer'];
    }
}
