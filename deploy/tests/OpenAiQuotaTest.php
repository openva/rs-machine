<?php

use PHPUnit\Framework\TestCase;
use RsMachine\OpenAiQuota;

require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Log.php';

/**
 * How the tasks that use OpenAI stop when its spend limit has been reached, and how often that's
 * alerted.
 */
class OpenAiQuotaTest extends TestCase
{
    /** What OpenAI returns (with HTTP 429) when the spend limit has been reached. */
    private const EXHAUSTED = ['error' => [
        'message' => 'You exceeded your current quota, please check your plan and billing details.',
        'type' => 'insufficient_quota',
        'param' => null,
        'code' => 'insufficient_quota',
    ]];

    private string $path;
    private int $now = 1800000000;

    /** @var array<int, array{0: string, 1: int}> What was logged, and at what level. */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/openai-quota-test-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testRecognizesTheSpendLimit(): void
    {
        $this->assertTrue(OpenAiQuota::isExhausted(self::EXHAUSTED));
        $this->assertTrue(OpenAiQuota::isExhausted(['error' => ['code' => 'billing_hard_limit_reached']]));
    }

    /**
     * Rate limiting is also HTTP 429, but passes in moments, so it isn't the spend limit.
     */
    public function testOtherErrorsArentTheSpendLimit(): void
    {
        $this->assertFalse(OpenAiQuota::isExhausted(['error' => [
            'message' => 'Rate limit reached for gpt-4o', 'type' => 'requests', 'code' => 'rate_limit_exceeded',
        ]]));
        $this->assertFalse(OpenAiQuota::isExhausted(['choices' => [['message' => ['content' => 'fees']]]]));
        $this->assertFalse(OpenAiQuota::isExhausted(null));
        $this->assertFalse(OpenAiQuota::isExhausted('not JSON'));
    }

    public function testReachingTheLimitPausesRequests(): void
    {
        $quota = $this->quota();
        $this->assertNull($quota->pausedUntil());

        $quota->recordExhausted($this->log(), 'tags', self::EXHAUSTED);

        $this->assertSame($this->now + OpenAiQuota::PAUSE_SECONDS, $quota->pausedUntil());
        $this->assertSame('skipped', $quota->skipped()->status);
        $this->assertStringContainsString('You exceeded your current quota', $this->logged[0][0]);
    }

    /**
     * Another task, in another process, sees the pause.
     */
    public function testThePauseIsShared(): void
    {
        $this->quota()->recordExhausted($this->log(), 'tags');

        $this->assertNotNull($this->quota()->pausedUntil());
    }

    public function testThePauseEnds(): void
    {
        $this->quota()->recordExhausted($this->log(), 'tags');

        $this->now += OpenAiQuota::PAUSE_SECONDS;
        $this->assertNull($this->quota()->pausedUntil());
    }

    /**
     * The first time, it's an alert; when it's still reached after a pause, it's routine, until a
     * day has passed.
     */
    public function testItsAlertedOnceADay(): void
    {
        $this->quota()->recordExhausted($this->log(), 'tags');
        $this->now += OpenAiQuota::PAUSE_SECONDS;
        $this->quota()->recordExhausted($this->log(), 'summarize_fis');
        $this->now += OpenAiQuota::ALERT_SECONDS;
        $this->quota()->recordExhausted($this->log(), 'tags');

        $this->assertSame([6, 2, 6], array_column($this->logged, 1));
    }

    /**
     * Once a request succeeds, the pause is over, and reaching the limit again is alerted.
     */
    public function testASuccessfulRequestEndsThePause(): void
    {
        $this->quota()->recordExhausted($this->log(), 'tags');
        $this->quota()->clear();

        $this->assertNull($this->quota()->pausedUntil());
        $this->assertFileDoesNotExist($this->path);

        $this->quota()->recordExhausted($this->log(), 'tags');
        $this->assertSame([6, 6], array_column($this->logged, 1));
    }

    public function testADamagedFileIsNoPause(): void
    {
        file_put_contents($this->path, 'not JSON');

        $this->assertNull($this->quota()->pausedUntil());
    }

    private function quota(): OpenAiQuota
    {
        return new OpenAiQuota($this->path, fn() => $this->now);
    }

    private function log(): Log
    {
        return new class ($this->logged) extends Log {
            public function __construct(private array &$logged)
            {
            }

            public function put($message, $level = 3)
            {
                $this->logged[] = [$message, $level];
                return true;
            }
        };
    }
}
