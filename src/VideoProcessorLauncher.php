<?php

declare(strict_types=1);

namespace RsMachine;

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

/**
 * Launches disposable video processors from the rs-video-processor launch template
 * (rs-video-processor's terraform/launch_template.tf), and finds the ones running.
 *
 * Each instance runs the pipeline once and then deletes itself, disk included. Spot is asked for
 * first: in November 2026 it cost $0.068–0.10 an hour for a c7a.xlarge (on demand: $0.205) and
 * $0.0124 for a t3a.medium ($0.0376). AWS may reclaim a Spot instance, which the pipeline
 * survives: its claimed jobs are released and retried. If Spot is refused for any reason (no
 * capacity, the price, a quota), the same launch is made on demand, so work never waits on Spot.
 */
final class VideoProcessorLauncher
{
    /** The tag the launch template puts on every processor. */
    private const ROLE_TAG = 'video-processor';

    public function __construct(
        private readonly Ec2Client $ec2,
        private readonly string $launchTemplate
    ) {
    }

    /**
     * Processors starting up or running.
     *
     * @return list<array{id:string,type:string,state:string}>
     */
    public function running(): array
    {
        $result = $this->ec2->describeInstances([
            'Filters' => [
                ['Name' => 'tag:Role', 'Values' => [self::ROLE_TAG]],
                ['Name' => 'instance-state-name', 'Values' => ['pending', 'running']],
            ],
        ]);

        $running = [];
        foreach ($result->get('Reservations') ?? [] as $reservation) {
            foreach ($reservation['Instances'] ?? [] as $instance) {
                $running[] = [
                    'id' => (string) $instance['InstanceId'],
                    'type' => (string) ($instance['InstanceType'] ?? ''),
                    'state' => (string) ($instance['State']['Name'] ?? ''),
                ];
            }
        }

        return $running;
    }

    /**
     * Launch one processor of this type, on Spot if possible.
     *
     * @return array{id:string,market:string,spotRefusal:?string} spotRefusal is EC2's reason
     *         for refusing Spot, when the processor was launched on demand instead
     * @throws AwsException when on demand is refused too
     */
    public function launch(string $instanceType): array
    {
        try {
            return ['id' => $this->run($instanceType, true), 'market' => 'spot', 'spotRefusal' => null];
        } catch (AwsException $e) {
            $refusal = $e->getAwsErrorCode() ?? $e->getMessage();
        }

        return ['id' => $this->run($instanceType, false), 'market' => 'on-demand', 'spotRefusal' => $refusal];
    }

    private function run(string $instanceType, bool $spot): string
    {
        $request = [
            'LaunchTemplate' => ['LaunchTemplateName' => $this->launchTemplate, 'Version' => '$Default'],
            'InstanceType' => $instanceType,
            'MinCount' => 1,
            'MaxCount' => 1,
        ];
        if ($spot) {
            $request['InstanceMarketOptions'] = [
                'MarketType' => 'spot',
                'SpotOptions' => [
                    // Not re-requested once reclaimed: the next cron run launches afresh.
                    'SpotInstanceType' => 'one-time',
                    'InstanceInterruptionBehavior' => 'terminate',
                ],
            ];
        }

        $result = $this->ec2->runInstances($request);

        return (string) $result->get('Instances')[0]['InstanceId'];
    }
}
