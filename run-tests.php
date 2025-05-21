#!/usr/bin/env php
<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
putenv("SYMFONY_DEPRECATIONS_HELPER=disabled");
$cmd = "vendor/bin/phpunit --testsuite Unit";
passthru($cmd, $exitCode);
exit($exitCode);
