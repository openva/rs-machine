<?php

use Aws\CommandInterface;
use Aws\Ec2\Ec2Client;
use Aws\Ec2\Exception\Ec2Exception;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\TestCase;
use RsMachine\VideoProcessorLauncher;

require_once __DIR__ . '/../../includes/vendor/autoload.php';

/**
 * RsMachine\VideoProcessorLauncher, which launches disposable video processors from the
 * rs-video-processor launch template, on Spot when it can. EC2's replies are played by the
 * SDK's MockHandler; nothing reaches AWS.
 */
class VideoProcessorLauncherTest extends TestCase
{
    private MockHandler $mock;

    /** @var list<CommandInterface> */
    private array $commands = [];

    private VideoProcessorLauncher $launcher;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
        $ec2 = new Ec2Client([
            'region' => 'us-east-1',
            'version' => '2016-11-15',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $this->mock,
        ]);
        $this->launcher = new VideoProcessorLauncher($ec2, 'rs-video-processor');
    }

    /**
     * Queue EC2's reply to the next call, recording the call.
     */
    private function reply(Result|string $outcome): void
    {
        $this->mock->append(function (CommandInterface $command) use ($outcome) {
            $this->commands[] = $command;
            if (is_string($outcome)) {
                return new Ec2Exception('Refused: ' . $outcome, $command, ['code' => $outcome]);
            }
            return $outcome;
        });
    }

    private static function launched(string $id): Result
    {
        return new Result(['Instances' => [['InstanceId' => $id]]]);
    }

    public function testSpotIsAskedForFirst(): void
    {
        $this->reply(self::launched('i-spot'));

        $launch = $this->launcher->launch('c7a.xlarge');

        $this->assertSame(['id' => 'i-spot', 'market' => 'spot', 'spotRefusal' => null], $launch);
        $this->assertCount(1, $this->commands);
        $command = $this->commands[0];
        $this->assertSame('RunInstances', $command->getName());
        $this->assertSame(['LaunchTemplateName' => 'rs-video-processor', 'Version' => '$Default'], $command['LaunchTemplate']);
        $this->assertSame('c7a.xlarge', $command['InstanceType']);
        $this->assertSame(1, $command['MinCount']);
        $this->assertSame(1, $command['MaxCount']);
        $this->assertSame('spot', $command['InstanceMarketOptions']['MarketType']);
        $this->assertSame('one-time', $command['InstanceMarketOptions']['SpotOptions']['SpotInstanceType']);
        $this->assertSame('terminate', $command['InstanceMarketOptions']['SpotOptions']['InstanceInterruptionBehavior']);
    }

    public function testRefusedSpotFallsBackToOnDemand(): void
    {
        $this->reply('InsufficientInstanceCapacity');
        $this->reply(self::launched('i-on-demand'));

        $launch = $this->launcher->launch('t3a.medium');

        $this->assertSame(
            ['id' => 'i-on-demand', 'market' => 'on-demand', 'spotRefusal' => 'InsufficientInstanceCapacity'],
            $launch
        );
        $this->assertCount(2, $this->commands);
        $this->assertSame('t3a.medium', $this->commands[1]['InstanceType']);
        $this->assertNull($this->commands[1]['InstanceMarketOptions']);
    }

    public function testBothRefusedIsAnError(): void
    {
        $this->reply('MaxSpotInstanceCountExceeded');
        $this->reply('InsufficientInstanceCapacity');

        $this->expectException(AwsException::class);
        $this->launcher->launch('c7a.xlarge');
    }

    public function testRunningProcessorsAreFoundByTag(): void
    {
        $this->reply(new Result(['Reservations' => [
            ['Instances' => [['InstanceId' => 'i-one', 'InstanceType' => 't3a.medium', 'State' => ['Name' => 'running']]]],
            ['Instances' => [['InstanceId' => 'i-two', 'InstanceType' => 'c7a.xlarge', 'State' => ['Name' => 'pending']]]],
        ]]));

        $running = $this->launcher->running();

        $this->assertSame([
            ['id' => 'i-one', 'type' => 't3a.medium', 'state' => 'running'],
            ['id' => 'i-two', 'type' => 'c7a.xlarge', 'state' => 'pending'],
        ], $running);
        $filters = array_column($this->commands[0]['Filters'], 'Values', 'Name');
        $this->assertSame(['video-processor'], $filters['tag:Role']);
        $this->assertSame(['pending', 'running'], $filters['instance-state-name']);
    }

    public function testNoneRunning(): void
    {
        $this->reply(new Result(['Reservations' => []]));

        $this->assertSame([], $this->launcher->running());
    }
}
