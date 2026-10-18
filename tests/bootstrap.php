<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Code under test that logs directly (outside the web server) writes to a temp dir, not the console.
App\Logger::configure(['log' => ['path' => sys_get_temp_dir() . '/rentnreturn-test-logs', 'level' => 'info']]);
