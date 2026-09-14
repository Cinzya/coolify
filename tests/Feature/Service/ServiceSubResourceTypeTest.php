<?php

use App\Livewire\Project\Service\Index;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function serviceSubResourceLabels(Service $service, string $serviceName, string $image): Collection
{
    $service->update(['docker_compose_raw' => <<<YAML
services:
  {$serviceName}:
    image: {$image}
YAML]);

    return collect(data_get(serviceParser($service->fresh()), "services.{$serviceName}.labels"))->values();
}

it('keeps a service application when its image changes to a database image', function () {
    $serviceApplication = ServiceApplication::create([
        'name' => 'database',
        'service_id' => $this->service->id,
        'image' => 'docker.io/tensorchord/pgvecto-rs:pg14-v0.2.0',
    ]);

    $labels = serviceSubResourceLabels($this->service, 'database', 'ghcr.io/immich-app/postgres:14-vectorchord0.4.3-pgvectors0.2.0');

    expect(ServiceDatabase::where('service_id', $this->service->id)->count())->toBe(0)
        ->and($serviceApplication->fresh()->image)->toBe('ghcr.io/immich-app/postgres:14-vectorchord0.4.3-pgvectors0.2.0')
        ->and($labels->all())
        ->toContain('coolify.service.subType=application')
        ->toContain("coolify.service.subId={$serviceApplication->id}")
        ->toContain("coolify.service.subUuid={$serviceApplication->uuid}");
});

it('keeps a service database when its image changes to a non-database image', function () {
    $serviceDatabase = ServiceDatabase::create([
        'name' => 'database',
        'service_id' => $this->service->id,
        'image' => 'postgres:17',
    ]);

    $labels = serviceSubResourceLabels($this->service, 'database', 'ghcr.io/example/custom-postgres-fork:17');

    expect(ServiceApplication::where('service_id', $this->service->id)->count())->toBe(0)
        ->and($serviceDatabase->fresh()->image)->toBe('ghcr.io/example/custom-postgres-fork:17')
        ->and($labels->all())
        ->toContain('coolify.service.subType=database')
        ->toContain("coolify.service.subId={$serviceDatabase->id}")
        ->toContain("coolify.service.subUuid={$serviceDatabase->uuid}");
});

it('keeps the service resource identity when converting between application and database', function () {
    $serviceApplication = ServiceApplication::create([
        'name' => 'database',
        'service_id' => $this->service->id,
        'image' => 'ghcr.io/immich-app/postgres:14',
    ]);
    $identity = $serviceApplication->uuid;
    $deployedLabels = serviceSubResourceLabels($this->service, 'database', 'ghcr.io/immich-app/postgres:14');

    Livewire::test(Index::class, ['serviceApplication' => $serviceApplication])
        ->call('convertToDatabase')
        ->assertHasNoErrors();

    $serviceDatabase = ServiceDatabase::where('service_id', $this->service->id)->where('name', 'database')->sole();

    expect($serviceDatabase->uuid)->toBe($identity)
        ->and($serviceDatabase->is_migrated)->toBeTrue()
        ->and($deployedLabels->all())->toContain("coolify.service.subUuid={$identity}")
        ->and(serviceSubResourceLabels($this->service, 'database', 'ghcr.io/immich-app/postgres:14')->all())
        ->toContain('coolify.service.subType=database')
        ->toContain("coolify.service.subUuid={$identity}");

    Livewire::test(Index::class, ['serviceApplication' => $serviceDatabase])
        ->call('convertToApplication')
        ->assertHasNoErrors();

    expect(ServiceApplication::where('service_id', $this->service->id)->where('name', 'database')->sole()->uuid)->toBe($identity)
        ->and(ServiceDatabase::where('service_id', $this->service->id)->count())->toBe(0);
});
