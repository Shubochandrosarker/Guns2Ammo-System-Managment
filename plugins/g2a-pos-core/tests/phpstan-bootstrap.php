<?php

// Constants defined by the main plugin file; surfaced to PHPStan so includes/* analysis is clean.
if (!defined('G2A_POS_CORE_VERSION')) {
    define('G2A_POS_CORE_VERSION', '0.0.0-static-analysis');
}
if (!defined('G2A_POS_CORE_PATH')) {
    define('G2A_POS_CORE_PATH', __DIR__ . '/../');
}
if (!defined('G2A_POS_CORE_URL')) {
    define('G2A_POS_CORE_URL', 'https://example.test/wp-content/plugins/g2a-pos-core/');
}
if (!defined('G2A_POS_CORE_FILE')) {
    define('G2A_POS_CORE_FILE', __DIR__ . '/../g2a-pos-core.php');
}
