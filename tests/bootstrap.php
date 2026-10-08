<?php

require __DIR__.'/../vendor/autoload.php';

// PHPUnit and browser fixtures share a destructive test database. Hold this
// lock for the entire process, including migration tests and worker children.
$GLOBALS['habitLogTestLock'] = fopen(__DIR__.'/../storage/framework/testing.lock', 'c');
if (! flock($GLOBALS['habitLogTestLock'], LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another test or browser run owns habit_log_test. Finish it before retrying.');
}
