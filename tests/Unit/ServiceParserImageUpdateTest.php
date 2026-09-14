<?php

/**
 * Unit tests to verify that service parser correctly handles image updates
 * without creating duplicate ServiceApplication or ServiceDatabase records.
 *
 * These tests verify the fix for the issue where changing an image in a
 * docker-compose file would create a new service instead of updating the existing one.
 */
it('ensures service parser does not include image in trusted service creation query', function () {
    // Read the serviceParser function from parsers.php
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that existing sub-resources are looked up by name in either table before creating one,
    // and that creation does not include image in the creation payload
    expect($parsersFile)
        ->toContain('function findServiceSubResource(Service $resource, string $serviceName): ServiceApplication|ServiceDatabase|null')
        ->toContain("return ServiceApplication::where('name', \$serviceName)->where('service_id', \$resource->id)->first()\n        ?? ServiceDatabase::where('name', \$serviceName)->where('service_id', \$resource->id)->first();")
        ->toContain('$savedService = findServiceSubResource($resource, $serviceName)')
        ->not->toMatch('/::(create|firstOrCreate)\(\[\s*\'name\' => \$serviceName,\s*\'image\' => \$image,/');
});

it('ensures parseDockerComposeFile reuses stored sub-resources and labels them with their identity', function () {
    $sharedFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/shared.php');

    expect($sharedFile)
        ->toContain('$savedService = findServiceSubResource($resource, $serviceName);')
        ->toContain("subType: \$savedService instanceof ServiceDatabase ? 'database' : 'application',")
        ->toContain('subUuid: $savedService->uuid,')
        ->not->toContain("subType: \$isDatabase ? 'database' : 'application',");
});

it('ensures service parser updates image after finding or creating service', function () {
    // Read the serviceParser function from parsers.php
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that image update logic exists after the trusted create/find branch
    expect($parsersFile)
        ->toContain('// Update image if it changed')
        ->toContain('if ($savedService->image !== $image) {')
        ->toContain('$savedService->image = $image;')
        ->toContain('$savedService->save();');
});

it('ensures parseDockerComposeFile does not create duplicates on null savedService', function () {
    // Read the parseDockerComposeFile function from shared.php
    $sharedFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/shared.php');

    // Check that the duplicate creation logic after is_null check has been fixed
    // The old code would create a duplicate if savedService was null
    // The new code checks for null within the else block and creates only if needed
    expect($sharedFile)
        ->toContain('if (is_null($savedService)) {')
        ->toContain('$savedService = ServiceDatabase::create([')
        ->toContain('$savedService = ServiceApplication::create([');
});

it('verifies image update logic is present in parseDockerComposeFile', function () {
    // Read the parseDockerComposeFile function from shared.php
    $sharedFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/shared.php');

    // Verify the image update logic exists
    expect($sharedFile)
        ->toContain('// Check if image changed')
        ->toContain('if ($savedService->image !== $image) {')
        ->toContain('$savedService->image = $image;')
        ->toContain('$savedService->save();');
});
