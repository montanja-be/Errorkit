<?php

namespace LaraBug\Support;

use Closure;
use Throwable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Bundles duplicates and keeps one site from flooding the server.
 *
 * State lives in the application cache so it is shared between requests and
 * workers. The cache can be the very thing that is failing (a database cache
 * while the database is down), so every cache call falls back to state kept
 * in this process: duplicates and the rate limit then still hold per process.
 */
class Throttle
{
    const PREFIX = 'errorkit.';

    /** @var array */
    private static $local = [];

    /**
     * Count an occurrence of the error with this fingerprint.
     *
     * Returns 0 while the error is inside its window, which means: do not send.
     * Otherwise it opens a new window and returns how many times the error
     * happened since the previous report, this occurrence included.
     *
     * @param string $fingerprint
     * @param int $window seconds
     * @return int
     */
    public function occurrence(string $fingerprint, int $window): int
    {
        if ($window <= 0) {
            return 1;
        }

        $key = self::PREFIX.'fp.'.$fingerprint;
        $now = $this->now();
        $state = $this->get($key);

        if (is_array($state) && ($state['until'] ?? 0) > $now) {
            $state['suppressed'] = ($state['suppressed'] ?? 0) + 1;
            $this->put($key, $state, 86400);

            return 0;
        }

        $this->put($key, ['until' => $now + $window, 'suppressed' => 0], 86400);

        return 1 + (is_array($state) ? (int) ($state['suppressed'] ?? 0) : 0);
    }

    /**
     * @param string $fingerprint
     * @return void
     */
    public function forget(string $fingerprint)
    {
        $key = self::PREFIX.'fp.'.$fingerprint;

        unset(self::$local[$key]);

        $this->attempt(function () use ($key) {
            Cache::forget($key);
        });
    }

    /**
     * Whether another report may go out this minute.
     *
     * @param int $perMinute
     * @return bool
     */
    public function allow(int $perMinute): bool
    {
        if ($this->isBackingOff()) {
            return false;
        }

        if ($perMinute <= 0) {
            return true;
        }

        $key = self::PREFIX.'rate.'.floor($this->now() / 60);

        $count = $this->attempt(function () use ($key) {
            Cache::add($key, 0, 120);

            return Cache::increment($key);
        }, function () use ($key) {
            self::$local[$key] = (self::$local[$key] ?? 0) + 1;

            return self::$local[$key];
        });

        return $count <= $perMinute;
    }

    /**
     * @param int $seconds
     * @return void
     */
    public function backOff(int $seconds)
    {
        $until = $this->now() + max(1, $seconds);

        $this->put(self::PREFIX.'backoff', $until, max(1, $seconds));
    }

    /**
     * @return bool
     */
    public function isBackingOff(): bool
    {
        $until = $this->get(self::PREFIX.'backoff');

        return is_int($until) && $until > $this->now();
    }

    /**
     * Remember reports that were dropped, so the server still learns how many.
     *
     * @param int $count
     * @return void
     */
    public function addDropped(int $count)
    {
        if ($count <= 0) {
            return;
        }

        $key = self::PREFIX.'dropped';

        $this->put($key, (int) $this->get($key) + $count, 86400);
    }

    /**
     * @return int
     */
    public function pullDropped(): int
    {
        $key = self::PREFIX.'dropped';
        $dropped = (int) $this->get($key);

        if ($dropped > 0) {
            $this->put($key, 0, 86400);
        }

        return $dropped;
    }

    /**
     * @internal
     */
    public static function reset()
    {
        self::$local = [];
    }

    /**
     * @return int
     */
    private function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    /**
     * @param string $key
     * @return mixed
     */
    private function get(string $key)
    {
        return $this->attempt(function () use ($key) {
            return Cache::get($key);
        }, function () use ($key) {
            $entry = self::$local[$key] ?? null;

            return is_array($entry) && isset($entry['expires']) && $entry['expires'] > $this->now()
                ? $entry['value']
                : null;
        });
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $seconds
     * @return void
     */
    private function put(string $key, $value, int $seconds)
    {
        $this->attempt(function () use ($key, $value, $seconds) {
            Cache::put($key, $value, $seconds);
        }, function () use ($key, $value, $seconds) {
            self::$local[$key] = ['value' => $value, 'expires' => $this->now() + $seconds];
        });
    }

    /**
     * @param Closure $callback
     * @param Closure|null $fallback
     * @return mixed
     */
    private function attempt(Closure $callback, ?Closure $fallback = null)
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            return $fallback ? $fallback() : null;
        }
    }
}
