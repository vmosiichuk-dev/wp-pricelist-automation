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
    define('STOCK_SYNC_VERSION', '0.5.2');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        /**
         * Create a WP_Error containing an error code and human-readable message.
         *
         * @param mixed  $code    Error code or identifier.
         * @param string $message Human-readable error message.
         */
        public function __construct($code, $message) {
            $this->code = $code;
            $this->message = $message;
        }
        /**
         * Retrieve the error message.
         *
         * @return string The error message.
         */
        public function get_error_message() {
            return $this->message;
        }
        public function get_error_code() {
            return $this->code;
        }
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query {
        public $posts = [];
        public static $test_posts = [];
        public static $test_have_posts = false;
        public static $last_args = [];

        /**
         * Initialize the test WP_Query stub.
         *
         * Records the provided query arguments to WP_Query::$last_args and sets the instance
         * posts from WP_Query::$test_posts so tests can control query results.
         *
         * @param array $args Query arguments to record for inspection.
         */
        public function __construct($args = []) {
            self::$last_args = $args;
            $this->posts = self::$test_posts;
        }

        /**
         * Indicates whether the test query has posts.
         *
         * @return bool `true` if the query has posts, `false` otherwise.
         */
        public function have_posts() {
            return self::$test_have_posts;
        }
    }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
