<?php

require __DIR__ . '/../vendor/autoload.php';

if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'test-auth-key-that-is-at-least-thirty-two-bytes-long');
}
if (!defined('MB_IN_BYTES')) {
    define('MB_IN_BYTES', 1048576);
}
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('G2A_POS_CORE_VERSION')) {
    define('G2A_POS_CORE_VERSION', 'test');
}
if (!defined('G2A_POS_CORE_PATH')) {
    define('G2A_POS_CORE_PATH', dirname(__DIR__) . '/');
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
// wpdb output formats. Defined here rather than inline in a single test so any
// repository calling get_row()/get_results() with ARRAY_A works regardless of
// which tests happen to load first.
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

require __DIR__ . '/wp-stubs.php';

\Brain\Monkey\setUp();

register_shutdown_function(static function (): void {
    \Brain\Monkey\tearDown();
});
