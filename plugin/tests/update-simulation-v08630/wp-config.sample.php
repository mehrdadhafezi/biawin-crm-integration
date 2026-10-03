<?php
// Throwaway QA install for the v0.8.63.0 update simulation (scratch MySQL on 127.0.0.1:3399).
define('DB_NAME', 'bcrm_upgrade_test');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', '127.0.0.1:3399');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_KEY', 'qa-only-a'); define('SECURE_AUTH_KEY', 'qa-only-b'); define('LOGGED_IN_KEY', 'qa-only-c'); define('NONCE_KEY', 'qa-only-d');
define('AUTH_SALT', 'qa-only-e'); define('SECURE_AUTH_SALT', 'qa-only-f'); define('LOGGED_IN_SALT', 'qa-only-g'); define('NONCE_SALT', 'qa-only-h');
$table_prefix = 'wp_';
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', __DIR__ . '/debug.log');
define('DISABLE_WP_CRON', true);
define('WP_HOME', 'http://bcrm-upgrade.test');
define('WP_SITEURL', 'http://bcrm-upgrade.test');
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
