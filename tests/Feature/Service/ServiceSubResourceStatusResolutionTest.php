<?php

use App\Actions\Docker\GetContainersStatus;
use App\Jobs\PushServerUpdateJob;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->service = Service::factory()->create(['server_id' => $this->server->id]);

    // The containers were deployed while "database" was a service application,
    // then the user converted it to a service database without redeploying.
    $convertedApplication = ServiceApplication::create([
        'service_id' => $this->service->id,
        'name' => 'database',
    ]);
    $this->staleSubId = (string) $convertedApplication->id;
    $this->identity = $convertedApplication->uuid;
    $convertedApplication->delete();

    $this->serviceDatabase = ServiceDatabase::forceCreate([
        'uuid' => $this->identity,
        'service_id' => $this->service->id,
        'name' => 'database',
        'status' => 'running:healthy',
        'is_migrated' => true,
    ]);
});

function convertedServiceContainerLabels(Service $service, string $staleSubId, ?string $subUuid): array
{
    return array_filter([
        'coolify.managed' => 'true',
        'coolify.type' => 'service',
        'coolify.serviceId' => (string) $service->id,
        'coolify.service.subType' => 'application',
        'coolify.service.subId' => $staleSubId,
        'coolify.service.subUuid' => $subUuid,
        'com.docker.compose.service' => 'database',
    ]);
}

function runSentinelUpdateForConvertedService(Server $server, array $labels): PushServerUpdateJob
{
    $job = new PushServerUpdateJob($server, [
        'containers' => [
            [
                'name' => 'database-container',
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => $labels,
            ],
        ],
    ]);
    $job->handle();

    return $job;
}

function runSshStatusCheckForConvertedService(Service $service, int $teamId, array $labels): void
{
    $services = Mockery::mock();
    $services->shouldReceive('get')->andReturn(collect([$service]));

    $server = Mockery::mock(Server::class, function (MockInterface $mock) use ($services) {
        $mock->shouldReceive('isFunctional')->andReturnTrue();
        $mock->shouldReceive('isSwarm')->andReturnFalse();
        $mock->shouldReceive('applications')->andReturn(collect());
        $mock->shouldReceive('databases')->andReturn(collect());
        $mock->shouldReceive('services')->andReturn($services);
        $mock->shouldReceive('previews')->andReturn(collect());
    })->makePartial();
    $server->setRelation('team', (object) ['id' => $teamId]);

    GetContainersStatus::run($server, collect([
        [
            'Name' => '/database-container',
            'Config' => ['Labels' => $labels],
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'RestartCount' => 0,
        ],
    ]), collect());
}

it('resolves a converted service resource from its identity label in Sentinel updates', function () {
    $this->serviceDatabase->update(['status' => 'exited']);

    $job = runSentinelUpdateForConvertedService(
        $this->server,
        convertedServiceContainerLabels($this->service, $this->staleSubId, $this->identity),
    );

    expect($job->foundServiceDatabaseIds->all())->toContain((string) $this->serviceDatabase->id)
        ->and($this->serviceDatabase->fresh()->status)->toBe('running:healthy');
});

it('resolves a converted service resource from its identity label in SSH status checks', function () {
    $this->serviceDatabase->update(['status' => 'exited']);

    runSshStatusCheckForConvertedService(
        $this->service,
        $this->team->id,
        convertedServiceContainerLabels($this->service, $this->staleSubId, $this->identity),
    );

    expect($this->serviceDatabase->fresh()->status)->toBe('running:healthy');
});

it('keeps resolving containers without an identity label by their deployed sub type and id in Sentinel updates', function () {
    runSentinelUpdateForConvertedService(
        $this->server,
        convertedServiceContainerLabels($this->service, $this->staleSubId, null),
    );

    expect($this->serviceDatabase->fresh()->status)->toBe('exited');
});

it('keeps resolving containers without an identity label by their deployed sub type and id in SSH status checks', function () {
    runSshStatusCheckForConvertedService(
        $this->service,
        $this->team->id,
        convertedServiceContainerLabels($this->service, $this->staleSubId, null),
    );

    expect($this->serviceDatabase->fresh()->status)->toBe('exited');
});

it('ignores identity labels that belong to another service', function () {
    $otherService = Service::factory()->create(['server_id' => $this->server->id]);
    $this->serviceDatabase->update(['status' => 'exited']);

    runSentinelUpdateForConvertedService(
        $this->server,
        convertedServiceContainerLabels($otherService, $this->staleSubId, $this->identity),
    );

    expect($this->serviceDatabase->fresh()->status)->toBe('exited');
});
