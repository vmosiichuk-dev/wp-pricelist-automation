<?php

require_once __DIR__ . '/../vendor/autoload.php';

\Brain\Monkey\setUp();

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('STOCK_SYNC_PLUGIN_DIR')) {
    define('STOCK_SYNC_PLUGIN_DIR', __DIR__ . '/../');
}
if (!defined('STOCK_SYNC_PLUGIN_URL')) {
    define('STOCK_SYNC_PLUGIN_URL', 'http://example.com/wp-content/plugins/stock-sync/');
}
if (!defined('STOCK_SYNC_VERSION')) {
    define('STOCK_SYNC_VERSION', '1.0.0');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public function __construct($code, $message) {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_message() {
            return $this->message;
        }
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query {
        public $posts = [];
        public static $test_posts = [];
        public static $test_have_posts = false;
        public static $last_args = [];

        public function __construct($args = []) {
            self::$last_args = $args;
            $this->posts = self::$test_posts;
        }

        public function have_posts() {
            return self::$test_have_posts;
        }
    }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
