<?php
/**
 * 2007-2024 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2024 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Include Composer autoloader
if (file_exists(dirname(__FILE__).'/vendor/autoload.php')) {
    require_once dirname(__FILE__).'/vendor/autoload.php';
}


class MyRestApi extends Module
{
    public function __construct()
    {
        $this->name = 'myrestapi';
        $this->tab = 'webservice';
        $this->version = '1.0.0';
        $this->author = 'Jules';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '9.0.0', // PrestaShop 9.0.x
            'max' => _PS_VERSION_,
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('My REST API');
        $this->description = $this->l('Provides a comprehensive REST API for PrestaShop.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }

    public function install()
    {
        if (!parent::install() ||
            !$this->registerHook('moduleRoutes') // For defining API routes
        ) {
            return false;
        }
        // Generate a JWT secret if it doesn't exist
        if (!Configuration::get('MYRESTAPI_JWT_SECRET')) {
            Configuration::updateValue('MYRESTAPI_JWT_SECRET', bin2hex(random_bytes(32)));
        }
        if (!Configuration::get('MYRESTAPI_API_KEY')) {
            Configuration::updateValue('MYRESTAPI_API_KEY', 'changeme_'.bin2hex(random_bytes(16)));
        }
        return true;
    }

    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }
        // Clean up configuration
        Configuration::deleteByName('MYRESTAPI_API_KEY');
        Configuration::deleteByName('MYRESTAPI_JWT_SECRET');
        return true;
    }

    /**
     * Add new routes for the API.
     * @param array $params
     * @return array
     */
    public function hookModuleRoutes($params)
    {
        // Example route definition - this will be expanded significantly
        // Routes will be defined in a dedicated service or configuration file later
        $routes = [
            // Example: /api/products
            'myrestapi_products_list' => [
                'controller' => 'products', // This will map to MyRestApiProductsModuleFrontController
                'rule' => 'myrestapi/products',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'myrestapi',
                    'controller' => 'products', // Name of the front controller without 'ModuleFrontController'
                ],
            ],
            // Example: /api/products/1
            'myrestapi_products_item' => [
                'controller' => 'products',
                'rule' => 'myrestapi/products/{id}',
                'keywords' => [
                    'id' => ['regexp' => '[0-9]+', 'param' => 'id_product'],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => 'myrestapi',
                    'controller' => 'products',
                ],
            ],
            // Route for JWT token generation - will be an AdminController initially for security
            // Or a specific front controller if we want public client authentication
             'myrestapi_token_generate' => [
                'controller' => 'token', // MyRestApiTokenModuleFrontController
                'rule' => 'myrestapi/token',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'myrestapi',
                    'controller' => 'token',
                ],
            ],

            // Category Routes
            'myrestapi_categories_list' => [
                'controller' => 'categories',
                'rule' => 'myrestapi/categories',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'myrestapi',
                    'controller' => 'categories',
                ],
            ],
            'myrestapi_categories_item' => [
                'controller' => 'categories',
                'rule' => 'myrestapi/categories/{id_category}',
                'keywords' => [
                    'id_category' => ['regexp' => '[0-9]+', 'param' => 'id_category'],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => 'myrestapi',
                    'controller' => 'categories',
                ],
            ],

            // CMS Page Routes
            'myrestapi_cms_pages_list' => [
                'controller' => 'cms',
                'rule' => 'myrestapi/cms/pages', // Using /cms/pages for clarity
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'myrestapi',
                    'controller' => 'cms',
                ],
            ],
            'myrestapi_cms_pages_item' => [
                'controller' => 'cms',
                'rule' => 'myrestapi/cms/pages/{id_cms_page}',
                'keywords' => [
                    'id_cms_page' => ['regexp' => '[0-9]+', 'param' => 'id_cms_page'],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => 'myrestapi',
                    'controller' => 'cms',
                ],
            ],

            // CMS Category Routes
            'myrestapi_cms_categories_list' => [
                'controller' => 'cms', // Still uses CmsController
                'rule' => 'myrestapi/cms/categories',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'myrestapi',
                    'controller' => 'cms',
                ],
            ],
            'myrestapi_cms_categories_item' => [
                'controller' => 'cms', // Still uses CmsController
                'rule' => 'myrestapi/cms/categories/{id_cms_category_object}', // param name used by CmsController
                'keywords' => [
                    'id_cms_category_object' => ['regexp' => '[0-9]+', 'param' => 'id_cms_category_object'],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => 'myrestapi',
                    'controller' => 'cms',
                ],
            ],
        ];
        return $routes;
    }

    /**
     * Module configuration page
     */
    public function getContent()
    {
        // This is where you would put module configuration options
        // For example, API key settings, JWT secret key, etc.
        $output = '';
        if (Tools::isSubmit('submit'.$this->name)) {
            // Process form submission
            $myApiKey = strval(Tools::getValue('MYRESTAPI_API_KEY'));
            Configuration::updateValue('MYRESTAPI_API_KEY', $myApiKey);
            $myJwtSecret = strval(Tools::getValue('MYRESTAPI_JWT_SECRET'));
            if (!$myJwtSecret) {
                // Generate a strong secret if not provided or empty
                $myJwtSecret = bin2hex(random_bytes(32));
            }
            Configuration::updateValue('MYRESTAPI_JWT_SECRET', $myJwtSecret);
            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }

        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        // Default Admin Form
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $fields_form[0]['form'] = [
            'legend' => [
                'title' => $this->l('API Settings'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('API Key (for token generation)'),
                    'name' => 'MYRESTAPI_API_KEY',
                    'size' => 60,
                    'required' => true,
                    'desc' => $this->l('Enter a secure API key. This key will be used by clients to request a JWT.'),
                ],
                [
                    'type' => 'textarea',
                    'label' => $this->l('JWT Secret Key'),
                    'name' => 'MYRESTAPI_JWT_SECRET',
                    'cols' => 60,
                    'rows' => 3,
                    'required' => true,
                    'desc' => $this->l('Enter a strong secret key for signing JWTs. If left empty, a secure one will be generated. Minimum 32 characters recommended.'),
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                '&token='.Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            ]
        ];

        // Load current values
        $helper->fields_value['MYRESTAPI_API_KEY'] = Configuration::get('MYRESTAPI_API_KEY');
        $helper->fields_value['MYRESTAPI_JWT_SECRET'] = Configuration::get('MYRESTAPI_JWT_SECRET');

        return $helper->generateForm($fields_form);
    }
}
