<?php

// Define _PS_VERSION_ if not already defined
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '9.0.0'); // Or your target PrestaShop version
}

// Define path to PrestaShop root directory (adjust as necessary for your environment)
// This is tricky for a module that's meant to be installed *inside* PrestaShop.
// For true unit tests, we'd mock PrestaShop dependencies.
// For integration tests, PHPUnit would typically be run from the PrestaShop root.
if (!defined('_PS_ROOT_DIR_')) {
    // This assumes tests are run from the module's root directory.
    // You might need to adjust this path based on your actual test execution context.
    // Example: __DIR__ . '/../../..' if module is in modules/myrestapi and PS root is three levels up.
    // For now, we'll define a placeholder. A real setup needs careful path management.
    define('_PS_ROOT_DIR_', __DIR__ . '/../../..');
}

// Attempt to load the module's composer autoloader
$module_autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($module_autoloader)) {
    require_once $module_autoloader;
} else {
    // Fallback if composer install hasn't been run in the module for some reason
    // This is not ideal for actual testing but helps define classes for static analysis.
    $project_autoloader = __DIR__ . '/../../../vendor/autoload.php'; // Assuming PS project root
    if (file_exists($project_autoloader)) {
        require_once $project_autoloader;
    } else {
        die("Composer autoload not found. Please run 'composer install' in the module directory and/or the PrestaShop root directory.\n");
    }
}


// Mock PrestaShop's Configuration class for JwtService tests if not in a full PS environment
if (!class_exists('Configuration')) {
    class Configuration
    {
        private static $config = [];

        public static function get($key, $id_lang = null, $id_shop_group = null, $id_shop = null, $default = false)
        {
            return self::$config[$key] ?? $default;
        }

        public static function set($key, $value) // Helper for tests
        {
            self::$config[$key] = $value;
        }

        public static function updateValue($key, $values, $html = false, $id_shop_group = null, $id_shop = null)
        {
            self::$config[$key] = $values;
            return true;
        }
         public static function deleteByName($key)
        {
            unset(self::$config[$key]);
            return true;
        }
    }
}

// Mock PrestaShop's Context class for JwtService tests
if (!class_exists('Context')) {
    class Context
    {
        public $shop;
        private static $instance;

        public function __construct()
        {
            $this->shop = new class {
                public function getBaseURL($ssl = true) {
                    return 'http://mockshop.com/';
                }
            };
        }

        public static function getContext()
        {
            if (!self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }
    }
}

// Add other mocks for PrestaShop specific classes if needed by the units under test
// e.g., Db, Validate, Tools, Language, Link, Product, StockAvailable etc. for controller/RTO tests.
// This bootstrap is primarily for services like JwtService that have fewer PS dependencies.

// You would typically load PrestaShop's config/config.inc.php here for integration tests
// require_once _PS_ROOT_DIR_ . '/config/config.inc.php';
// However, that makes them integration tests, not unit tests, and requires a PS database.

echo "PHPUnit Bootstrap loaded.\n";
echo "PHP Version: " . phpversion() . "\n";
// Ensure MYRESTAPI_JWT_SECRET has a default for tests if not set by PrestaShop.
// This is important if JwtService is instantiated outside a full PS init.
if (class_exists('Configuration') && Configuration::get('MYRESTAPI_JWT_SECRET') === false) {
    Configuration::set('MYRESTAPI_JWT_SECRET', 'default-test-secret-key-for-phpunit-myrestapi-!@#$%^&*()');
}

?>
