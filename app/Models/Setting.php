<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The backing row for `App\Support\InstanceSettings` (Plan 06 Phase 4b
 * design §3.3). A plain key/value row — callers never touch this model
 * directly; go through `InstanceSettings::get()`/`put()`, which adds the
 * cache layer described in the design.
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * `value` is stored as JSON text so it can hold any JSON-serializable
     * scalar or array (an int workspace id today; an IP-allowlist array or
     * similar tomorrow) without a schema change.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
