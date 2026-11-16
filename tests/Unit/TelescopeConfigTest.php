<?php

use Symfony\Component\Process\Process;

it('keeps Telescope off when TELESCOPE_ENABLED is not set', function () {
    $process = new Process(
        [PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo json_encode((require "config/telescope.php")["enabled"]);'],
        dirname(__DIR__, 2),
        ['TELESCOPE_ENABLED' => false],
    );

    expect($process->mustRun()->getOutput())->toBe('false');
});
