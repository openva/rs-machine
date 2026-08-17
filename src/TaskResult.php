<?php

declare(strict_types=1);

namespace RsMachine;

/**
 * The outcome of a single update task.
 *
 * Task files historically signalled their outcome by falling off the end of the file, or with a
 * bare file-scope `return;` / `return false;`. Both are invisible to the caller: `require` returns
 * 1 on a plain fall-through, so there was no way to distinguish "did the work", "correctly decided
 * there was nothing to do" and "bailed out because something was wrong".
 *
 * That distinction matters operationally. A task that skips because an unchanged CSV hash means
 * there is no new data is healthy. A task that skips because the CSV could not be read is not.
 * Both used to look identical in the logs.
 */
class TaskResult
{
    public const COMPLETED = 'completed';
    public const SKIPPED   = 'skipped';
    public const FAILED    = 'failed';

    /**
     * @param string               $status  One of the class constants.
     * @param string               $reason  Human-readable explanation, shown in logs.
     * @param array<string, mixed> $metrics Arbitrary counters, e.g. ['bills_updated' => 12].
     */
    private function __construct(
        public readonly string $status,
        public readonly string $reason = '',
        public readonly array $metrics = []
    ) {
    }

    /**
     * The task did its work.
     *
     * @param array<string, mixed> $metrics
     */
    public static function completed(array $metrics = []): self
    {
        return new self(self::COMPLETED, '', $metrics);
    }

    /**
     * The task correctly determined there was nothing to do. This is a success, not a problem:
     * an unchanged source file or an out-of-session date is the normal case most of the year.
     */
    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, $reason);
    }

    /**
     * The task could not do its work. Use this for a missing input file, an unreachable API, or
     * any other condition where the run should be visible as a problem. Throwing an exception
     * works too — the runner converts uncaught throwables into a failed result.
     */
    public static function failed(string $reason): self
    {
        return new self(self::FAILED, $reason);
    }

    public function isFailure(): bool
    {
        return $this->status === self::FAILED;
    }

    /**
     * Render the metrics for a log line, e.g. "bills_updated=12 skipped=3".
     */
    public function metricsSummary(): string
    {
        if ($this->metrics === []) {
            return '';
        }

        $pairs = [];
        foreach ($this->metrics as $key => $value) {
            $pairs[] = $key . '=' . (is_scalar($value) ? (string) $value : gettype($value));
        }

        return implode(' ', $pairs);
    }
}
