#!/bin/bash
export SYMFONY_DEPRECATIONS_HELPER=disabled
php -d error_reporting=22527 -d display_errors=On vendor/bin/phpunit --testsuite Unit "$@"
