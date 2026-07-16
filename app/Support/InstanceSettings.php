<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Instance-wide key/value accessor for Community: a `settings` table read
 * through a cache, so hot paths (chiefly
 * `CommunityWorkspaceContext::current()`, resolved on every request) don't
 * hit the database every time.
 *
 * The primary key stored here is `workspace_id` (the singleton workspace
 * pointer, written by `sendtrap:install`). Further instance-config keys can
 * be added through the same accessor.
 */
class InstanceSettings
{
    private const CACHE_PREFIX = 'instance-setting:';

    /**
     * Read a key, falling back to $default when the row doesn't exist.
     *
     * Deliberately does not permanently cache a "missing" result: Laravel's
     * cache stores treat a cached `null` as absent on the next `get()`
     * (isset()-based on the array/file stores), so an unset key keeps
     * re-checking the database rather than wedging a stale miss into the
     * cache forever. That is the right trade for a value written exactly
     * once at install and read constantly afterward.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever(
            self::cacheKey($key),
            fn () => Setting::query()->where('key', $key)->value('value'),
        );

        return $value ?? $default;
    }

    /**
     * Upsert a key's value and refresh the cache in the same call, so a
     * reader never observes a stale value after a write (no cache
     * invalidation race within a single process).
     */
    public static function put(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forever(self::cacheKey($key), $value);
    }

    /**
     * Remove a key entirely (row + cache). Not used by `sendtrap:install`
     * itself; exists for the pointer-drift self-heal test (§10.10) to
     * simulate a lost `workspace_id` pointer.
     */
    public static function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();

        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return self::CACHE_PREFIX.$key;
    }
}
