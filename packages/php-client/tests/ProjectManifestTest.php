<?php

declare(strict_types=1);

use Vaults\Project\ProjectManifest;

it('round trips the project uuid', function () {
    $directory = sys_get_temp_dir().'/vaults-client-tests/'.uniqid();
    mkdir($directory, 0755, true);

    $manifest = new ProjectManifest;

    expect($manifest->load($directory))->toBeNull();

    $manifest->write($directory, 'project-uuid');

    expect($manifest->load($directory))->toBe('project-uuid')
        ->and($manifest->team($directory))->toBeNull()
        ->and(json_decode((string) file_get_contents($directory.'/.vaults.json'), true))->toBe(['project' => 'project-uuid']);

    $manifest->write($directory, 'project-uuid', 'team-uuid');

    expect($manifest->team($directory))->toBe('team-uuid')
        ->and(json_decode((string) file_get_contents($directory.'/.vaults.json'), true))->toBe(['project' => 'project-uuid', 'team' => 'team-uuid']);
});
