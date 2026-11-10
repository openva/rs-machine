<?php

namespace RsMachine;

use Closure;
use Log;

/**
 * Whether OpenAI's spend limit has been reached, for the tasks that use it (tags.php,
 * summarize_fis.php, bills_status_narratives.php and update_places.php).
 *
 * When it has, OpenAI refuses every request until the limit is raised or the month ends, so a task
 * that finds it reached stops, and all of them skip their requests for PAUSE_SECONDS, rather than
 * each logging an error for every bill. That's alerted once a day, at most; the pause is in
 * cron/openai-quota.json, and a successful request ends it.
 */
class OpenAiQuota
{
    /** How long to stop asking OpenAI, once its limit has been reached. */
    public const PAUSE_SECONDS = 6 * 3600;

    /** How often to alert that the limit is still reached. */
    public const ALERT_SECONDS = 24 * 3600;

    /** The error codes that OpenAI gives when the limit has been reached. */
    private const EXHAUSTED_CODES = ['insufficient_quota', 'billing_hard_limit_reached'];

    /**
     * @param string        $path  The file to keep the pause in.
     * @param ?Closure(): int $clock The time now, for tests.
     */
    public function __construct(private string $path, private ?Closure $clock = null)
    {
    }

    /** The pause shared by every task, in cron/. */
    public static function shared(): self
    {
        return new self(dirname(__DIR__) . '/cron/openai-quota.json');
    }

    /**
     * Whether an OpenAI response (decoded) says that the spend limit has been reached. (OpenAI
     * gives HTTP 429 for that, as for rate limiting, so the error's code is what tells them apart.)
     */
    public static function isExhausted(mixed $response): bool
    {
        $error = is_array($response) ? ($response['error'] ?? null) : null;
        if (!is_array($error)) {
            return false;
        }

        return in_array($error['code'] ?? null, self::EXHAUSTED_CODES, true)
            || in_array($error['type'] ?? null, self::EXHAUSTED_CODES, true);
    }

    /** When the pause ends, or null if requests aren't paused. */
    public function pausedUntil(): ?int
    {
        $until = (int) ($this->read()['paused_until'] ?? 0);

        return $until > $this->now() ? $until : null;
    }

    /** The result for a task that's skipping its requests because of the pause. */
    public function skipped(): TaskResult
    {
        return TaskResult::skipped('OpenAI’s spend limit has been reached; it will be tried again '
            . 'after ' . date('Y-m-d g:i A', $this->pausedUntil() ?? $this->now()));
    }

    /**
     * Record that the spend limit has been reached, pausing requests, and log it: as an alert if
     * it hasn't been alerted in the last day, and as routine otherwise.
     *
     * @param string $task The task that found it, for the log.
     */
    public function recordExhausted(Log $log, string $task, mixed $response = null): void
    {
        $now = $this->now();
        $state = $this->read();
        $alert = $now - (int) ($state['alerted_at'] ?? 0) >= self::ALERT_SECONDS;

        $state['paused_until'] = $now + self::PAUSE_SECONDS;
        if ($alert) {
            $state['alerted_at'] = $now;
        }
        $this->write($state);

        $reason = is_array($response) ? trim((string) ($response['error']['message'] ?? '')) : '';
        $log->put(
            'OpenAI’s spend limit has been reached' . ($reason !== '' ? ' (“' . $reason . '”)' : '')
                . ', according to ' . $task . ', so the tasks that use OpenAI won’t until '
                . date('Y-m-d g:i A', $state['paused_until']) . '.',
            $alert ? 6 : 2
        );
    }

    /** End any pause, after a request succeeded, so that a new one is alerted right away. */
    public function clear(): void
    {
        if (file_exists($this->path)) {
            @unlink($this->path);
        }
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    /** @return array<string, int> */
    private function read(): array
    {
        if (!is_readable($this->path)) {
            return [];
        }
        $state = json_decode((string) file_get_contents($this->path), true);

        return is_array($state) ? $state : [];
    }

    /** @param array<string, int> $state */
    private function write(array $state): void
    {
        $temporary = $this->path . '.' . getmypid();
        if (file_put_contents($temporary, json_encode($state)) !== false) {
            rename($temporary, $this->path);
        }
    }
}
