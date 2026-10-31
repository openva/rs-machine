<?php

declare(strict_types=1);

namespace RsMachine;

use Log;
use Memcached;
use mysqli;
use PDO;

/**
 * The dependencies a task needs, passed in explicitly.
 *
 * The imports above are the shared classes from includes/, which are all in the global namespace.
 * They are imported by name rather than referenced with a leading backslash so that the constructor
 * signature stays readable.
 *
 * Task files currently reach into their parent's variable scope for $log, $GLOBALS['db'], $dbh,
 * $session_id and $mc. That works only because update.php happens to have defined them before the
 * `require`, which makes each task file untestable in isolation and makes the coupling invisible —
 * you cannot tell what a task needs without reading all of it.
 *
 * Converted tasks receive this object instead. The properties are deliberately named to match the
 * variables the legacy files already use, so the mechanical conversion is:
 *
 *     return function (TaskContext $ctx): TaskResult {
 *         $log        = $ctx->log;
 *         $session_id = $ctx->sessionId;
 *         $mc         = $ctx->memcached;
 *         ... unchanged file body ...
 *     };
 *
 * which keeps the diff to the top of the file rather than threading a new variable name through
 * hundreds of lines.
 */
class TaskContext
{
    /**
     * @param Log           $log       Shared logger.
     * @param mysqli|null   $db        The mysqli handle legacy tasks use via $GLOBALS['db'].
     * @param PDO|null      $dbh       The PDO handle newer tasks use.
     * @param Memcached|null $memcached Cache handle, or null when Memcached is unconfigured.
     * @param int           $sessionId Richmond Sunlight's internal session ID.
     * @param int           $sessionYear Calendar year of the session.
     * @param string        $lisSessionId LIS's own session identifier, e.g. '251'.
     * @param string        $lisApiSessionId LIS API session ID, from SESSION_LIS_API_ID. Distinct
     *                                       from $lisSessionId: bills.php posts this to the
     *                                       GetLegislationIdsListAsync endpoint, and skips the
     *                                       lookup entirely when it is empty.
     * @param string        $cronDir   Absolute path to cron/, for locating CSVs and hash files.
     */
    public function __construct(
        public readonly Log $log,
        public readonly ?mysqli $db,
        public readonly ?PDO $dbh,
        public readonly ?Memcached $memcached,
        public readonly int $sessionId,
        public readonly int $sessionYear,
        public readonly string $lisSessionId,
        public readonly string $lisApiSessionId,
        public readonly string $cronDir
    ) {
    }

    /**
     * This context with new database connections, after MySQL closed the old ones.
     */
    public function withConnections(?mysqli $db, ?PDO $dbh): self
    {
        return new self(
            $this->log,
            $db,
            $dbh,
            $this->memcached,
            $this->sessionId,
            $this->sessionYear,
            $this->lisSessionId,
            $this->lisApiSessionId,
            $this->cronDir
        );
    }

    /**
     * Absolute path to a file in cron/, e.g. $ctx->cronPath('summaries.csv').
     */
    public function cronPath(string $filename): string
    {
        return $this->cronDir . '/' . ltrim($filename, '/');
    }

    /**
     * Absolute path to a session-scoped hash file, following the existing
     * cron/hashes/{type}-{SESSION_ID}.md5 convention.
     */
    public function hashPath(string $type): string
    {
        return $this->cronDir . '/hashes/' . $type . '-' . $this->sessionId . '.md5';
    }
}
