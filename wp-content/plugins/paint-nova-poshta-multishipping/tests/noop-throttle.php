<?php
namespace Paint\NovaPoshta\Infrastructure;

// Standalone transport fixtures have no database. The real limiter has its own tests.
if (PHP_SAPI !== 'cli' || !defined('ABSPATH')) throw new \RuntimeException('CLI fixture only');
class ApiRequestThrottle
{
    public function run(string $key, callable $request) { return $request(); }
}
