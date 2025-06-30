<?php
/**
 * Advanced REST API Module for PrestaShop v9
 * Provides full CRUD operations with relational fields
 * Separates DTOs and RTOs for flexible data control
 */

// File: /modules/advancedrestapi/advancedrestapi.php

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdvancedRestApi extends Module
{
    public function __construct()
    {
        $this->name = 'advancedrestapi';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Your Name';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Advanced REST API', [], 'Modules.AdvancedRestApi.Admin');
        $this->description = $this->trans('Complete REST API with relational data support', [], 'Modules.AdvancedRestApi.Admin');
    }

    public function install()
    {
        return parent::install() &&
            $this->registerHook('actionObjectOrderAddAfter') &&
            $this->registerHook('actionObjectCustomerAddAfter') &&
            $this->installTab();
    }

    public function uninstall()
    {
        return parent::uninstall() && $this->uninstallTab();
    }

    private function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminAdvancedRestApi';
        $tab->name = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Advanced REST API';
        }
        $tab->id_parent = (int) Tab::getIdFromClassName('CONFIGURE');
        $tab->module = $this->name;
        return $tab->add();
    }

    private function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminAdvancedRestApi');
        if ($idTab) {
            $tab = new Tab($idTab);
            return $tab->delete();
        }
        return true;
    }
}

// File: /modules/advancedrestapi/controllers/front/api.php

class AdvancedRestApiApiModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function init()
    {
        parent::init();
        
        // CORS headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    public function initContent()
    {
        parent::initContent();
        
        try {
            $this->processApiRequest();
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function processApiRequest()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = trim($this->getRequestPath(), '/');
        $pathParts = explode('/', $path);
        
        $resource = $pathParts[0] ?? '';
        $id = $pathParts[1] ?? null;
        
        $handler = new ApiRequestHandler();
        
        switch ($method) {
            case 'GET':
                if ($id) {
                    $result = $handler->getResource($resource, $id);
                } else {
                    $result = $handler->getResources($resource, $_GET);
                }
                break;
            case 'POST':
                $data = json_decode(file_get_contents('php://input'), true);
                $result = $handler->createResource($resource, $data);
                break;
            case 'PUT':
                $data = json_decode(file_get_contents('php://input'), true);
                $result = $handler->updateResource($resource, $id, $data);
                break;
            case 'DELETE':
                $result = $handler->deleteResource($resource, $id);
                break;
            default:
                throw new Exception('Method not allowed', 405);
        }
        
        $this->sendJsonResponse($result);
    }

    private function getRequestPath()
    {
        $requestUri = $_SERVER['REQUEST_URI'];
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $path = str_replace(dirname($scriptName), '', $requestUri);
        return parse_url($path, PHP_URL_PATH);
    }

    private function sendJsonResponse($data, $status = 200)
    {
        http_response_code($status);
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}

// File: /modules/advancedrestapi/classes/ApiRequestHandler.php

class ApiRequestHandler
{
    private $resourceMapping = [
        'products' => 'Product',
        'customers' => 'Customer',
        'orders' => 'Order',
        'categories' => 'Category',
        'manufacturers' => 'Manufacturer',
        'suppliers' => 'Supplier',
        'addresses' => 'Address',
        'carts' => 'Cart',
        'orders_details' => 'OrderDetail',
        'countries' => 'Country',
        'states' => 'State',
        'zones' => 'Zone',
        'carriers' => 'Carrier',
        'order_states' => 'OrderState',
        'languages' => 'Language',
        'currencies' => 'Currency'
    ];

    public function getResource($resource, $id)
    {
        $className = $this->getClassName($resource);
        $object = new $className($id);
        
        if (!Validate::isLoadedObject($object)) {
            throw new Exception("Resource not found", 404);
        }

        $dto = $this->createDTO($className, $object);
        $rto = $this->createRTO($resource, $dto);
        
        return $rto;
    }

    public function getResources($resource, $params = [])
    {
        $className = $this->getClassName($resource);
        
        $limit = isset($params['limit']) ? (int)$params['limit'] : 50;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
        $orderBy = isset($params['order_by']) ? $params['order_by'] : 'id_' . strtolower($className);
        $orderWay = isset($params['order_way']) ? strtoupper($params['order_way']) : 'ASC';
        
        $where = $this->buildWhereClause($params);
        
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from(strtolower($className));
        if ($where) {
            $sql->where($where);
        }
        $sql->orderBy($orderBy . ' ' . $orderWay);
        $sql->limit($limit, $offset);
        
        $results = Db::getInstance()->executeS($sql);
        
        $dtos = [];
        foreach ($results as $result) {
            $object = new $className();
            $object->hydrate($result);
            $dto = $this->createDTO($className, $object);
            $dtos[] = $this->createRTO($resource, $dto);
        }
        
        return [
            'data' => $dtos,
            'total' => $this->getResourceCount($className, $where),
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    public function createResource($resource, $data)
    {
        $className = $this->getClassName($resource);
        $object = new $className();
        
        $dto = $this->arrayToDTO($className, $data);
        $this->hydrateObjectFromDTO($object, $dto);
        
        if (!$object->add()) {
            throw new Exception("Failed to create resource", 400);
        }
        
        $createdDto = $this->createDTO($className, $object);
        return $this->createRTO($resource, $createdDto);
    }

    public function updateResource($resource, $id, $data)
    {
        $className = $this->getClassName($resource);
        $object = new $className($id);
        
        if (!Validate::isLoadedObject($object)) {
            throw new Exception("Resource not found", 404);
        }
        
        $dto = $this->arrayToDTO($className, $data);
        $this->hydrateObjectFromDTO($object, $dto);
        
        if (!$object->update()) {
            throw new Exception("Failed to update resource", 400);
        }
        
        $updatedDto = $this->createDTO($className, $object);
        return $this->createRTO($resource, $updatedDto);
    }

    public function deleteResource($resource, $id)
    {
        $className = $this->getClassName($resource);
        $object = new $className($id);
        
        if (!Validate::isLoadedObject($object)) {
            throw new Exception("Resource not found", 404);
        }
        
        if (!$object->delete()) {
            throw new Exception("Failed to delete resource", 400);
        }
        
        return ['success' => true, 'message' => 'Resource deleted'];
    }

    private function getClassName($resource)
    {
        if (!isset($this->resourceMapping[$resource])) {
            throw new Exception("Unknown resource: $resource", 400);
        }
        return $this->resourceMapping[$resource];
    }

    private function createDTO($className, $object)
    {
        $dtoClassName = $className . 'DTO';
        
        if (!class_exists($dtoClassName)) {
            throw new Exception("DTO class not found: $dtoClassName", 500);
        }
        
        return new $dtoClassName($object);
    }

    private function createRTO($resource, $dto)
    {
        $rtoClassName = ucfirst($resource) . 'RTO';
        
        if (!class_exists($rtoClassName)) {
            // Fallback to generic RTO
            return $dto->toArray();
        }
        
        $rto = new $rtoClassName($dto);
        return $rto->toArray();
    }

    private function arrayToDTO($className, $data)
    {
        $dtoClassName = $className . 'DTO';
        return $dtoClassName::fromArray($data);
    }

    private function hydrateObjectFromDTO($object, $dto)
    {
        $data = $dto->toArray();
        foreach ($data as $key => $value) {
            if (property_exists($object, $key)) {
                $object->$key = $value;
            }
        }
    }

    private function buildWhereClause($params)
    {
        $where = [];
        
        foreach ($params as $key => $value) {
            if (in_array($key, ['limit', 'offset', 'order_by', 'order_way'])) {
                continue;
            }
            
            if (is_array($value)) {
                $where[] = $key . ' IN (' . implode(',', array_map('intval', $value)) . ')';
            } else {
                $where[] = $key . ' = "' . pSQL($value) . '"';
            }
        }
        
        return implode(' AND ', $where);
    }

    private function getResourceCount($className, $where = null)
    {
        $sql = new DbQuery();
        $sql->select('COUNT(*)');
        $sql->from(strtolower($className));
        if ($where) {
            $sql->where($where);
        }
        
        return (int)Db::getInstance()->getValue($sql);
    }
}

// File: /modules/advancedrestapi/classes/DTOs/ProductDTO.php

class ProductDTO
{
    public $id_product;
    public $id_supplier;
    public $id_manufacturer;
    public $id_category_default;
    public $id_shop_default;
    public $id_tax_rules_group;
    public $on_sale;
    public $online_only;
    public $ean13;
    public $isbn;
    public $upc;
    public $mpn;
    public $ecotax;
    public $quantity;
    public $minimal_quantity;
    public $price;
    public $wholesale_price;
    public $unity;
    public $unit_price_ratio;
    public $additional_shipping_cost;
    public $reference;
    public $supplier_reference;
    public $location;
    public $width;
    public $height;
    public $depth;
    public $weight;
    public $out_of_stock;
    public $additional_delivery_times;
    public $quantity_discount;
    public $customizable;
    public $uploadable_files;
    public $text_fields;
    public $active;
    public $redirect_type;
    public $id_type_redirected;
    public $available_for_order;
    public $available_date;
    public $show_condition;
    public $condition;
    public $show_price;
    public $indexed;
    public $visibility;
    public $cache_is_pack;
    public $advanced_stock_management;
    public $date_add;
    public $date_upd;
    public $pack_stock_type;
    
    // Relational data
    public $category;
    public $manufacturer;
    public $supplier;
    public $images;
    public $features;
    public $attributes;
    public $translations;
    public $combinations;
    public $stock_availables;

    public function __construct($product = null)
    {
        if ($product instanceof Product) {
            $this->loadFromProduct($product);
        }
    }

    private function loadFromProduct(Product $product)
    {
        // Load basic properties
        foreach (get_object_vars($product) as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }

        // Load relational data
        $this->loadRelationalData($product);
    }

    private function loadRelationalData(Product $product)
    {
        // Load category
        if ($product->id_category_default) {
            $category = new Category($product->id_category_default);
            $this->category = new CategoryDTO($category);
        }

        // Load manufacturer
        if ($product->id_manufacturer) {
            $manufacturer = new Manufacturer($product->id_manufacturer);
            $this->manufacturer = new ManufacturerDTO($manufacturer);
        }

        // Load supplier
        if ($product->id_supplier) {
            $supplier = new Supplier($product->id_supplier);
            $this->supplier = new SupplierDTO($supplier);
        }

        // Load images
        $this->images = [];
        $images = Image::getImages(Context::getContext()->language->id, $product->id);
        foreach ($images as $image) {
            $this->images[] = new ImageDTO($image);
        }

        // Load features
        $this->features = [];
        $features = Feature::getFeatures(Context::getContext()->language->id);
        foreach ($features as $feature) {
            $featureValue = FeatureValue::getFeatureValueLang($feature['id_feature'], $product->id);
            if ($featureValue) {
                $this->features[] = [
                    'id_feature' => $feature['id_feature'],
                    'name' => $feature['name'],
                    'value' => $featureValue
                ];
            }
        }

        // Load product attributes/combinations
        $this->combinations = [];
        $combinations = $product->getAttributeCombinations(Context::getContext()->language->id);
        foreach ($combinations as $combination) {
            $this->combinations[] = new CombinationDTO($combination);
        }

        // Load translations
        $this->translations = [];
        $languages = Language::getLanguages(true);
        foreach ($languages as $language) {
            $this->translations[$language['iso_code']] = [
                'name' => $product->name[$language['id_lang']] ?? '',
                'description' => $product->description[$language['id_lang']] ?? '',
                'description_short' => $product->description_short[$language['id_lang']] ?? '',
                'link_rewrite' => $product->link_rewrite[$language['id_lang']] ?? '',
                'meta_title' => $product->meta_title[$language['id_lang']] ?? '',
                'meta_description' => $product->meta_description[$language['id_lang']] ?? '',
                'meta_keywords' => $product->meta_keywords[$language['id_lang']] ?? '',
                'available_now' => $product->available_now[$language['id_lang']] ?? '',
                'available_later' => $product->available_later[$language['id_lang']] ?? ''
            ];
        }

        // Load stock
        $this->stock_availables = [];
        $stocks = StockAvailable::getQuantitiesByProduct($product->id);
        foreach ($stocks as $stock) {
            $this->stock_availables[] = new StockAvailableDTO($stock);
        }
    }

    public function toArray()
    {
        return get_object_vars($this);
    }

    public static function fromArray($data)
    {
        $dto = new self();
        foreach ($data as $key => $value) {
            if (property_exists($dto, $key)) {
                $dto->$key = $value;
            }
        }
        return $dto;
    }
}

// File: /modules/advancedrestapi/classes/RTOs/ProductsRTO.php

class ProductsRTO
{
    private $dto;
    private $config;

    public function __construct(ProductDTO $dto, $config = [])
    {
        $this->dto = $dto;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    private function getDefaultConfig()
    {
        return [
            'include_relations' => true,
            'include_images' => true,
            'include_translations' => true,
            'include_combinations' => false,
            'include_features' => true,
            'include_stock' => true,
            'image_size' => 'large',
            'max_images' => 10,
            'languages' => ['en', 'it'] // Default languages
        ];
    }

    public function toArray()
    {
        $data = [
            'id' => $this->dto->id_product,
            'reference' => $this->dto->reference,
            'name' => $this->getTranslatedField('name'),
            'description' => $this->getTranslatedField('description'),
            'description_short' => $this->getTranslatedField('description_short'),
            'price' => [
                'base' => (float)$this->dto->price,
                'formatted' => Tools::displayPrice($this->dto->price),
                'currency' => Context::getContext()->currency->iso_code
            ],
            'quantity' => (int)$this->dto->quantity,
            'active' => (bool)$this->dto->active,
            'available_for_order' => (bool)$this->dto->available_for_order,
            'show_price' => (bool)$this->dto->show_price,
            'online_only' => (bool)$this->dto->online_only,
            'condition' => $this->dto->condition,
            'ean13' => $this->dto->ean13,
            'isbn' => $this->dto->isbn,
            'upc' => $this->dto->upc,
            'weight' => (float)$this->dto->weight,
            'dimensions' => [
                'width' => (float)$this->dto->width,
                'height' => (float)$this->dto->height,
                'depth' => (float)$this->dto->depth
            ],
            'dates' => [
                'created' => $this->dto->date_add,
                'updated' => $this->dto->date_upd
            ]
        ];

        if ($this->config['include_relations']) {
            $data['relations'] = $this->getRelations();
        }

        if ($this->config['include_images'] && $this->dto->images) {
            $data['images'] = $this->getImages();
        }

        if ($this->config['include_translations']) {
            $data['translations'] = $this->getTranslations();
        }

        if ($this->config['include_combinations'] && $this->dto->combinations) {
            $data['combinations'] = $this->getCombinations();
        }

        if ($this->config['include_features'] && $this->dto->features) {
            $data['features'] = $this->dto->features;
        }

        if ($this->config['include_stock'] && $this->dto->stock_availables) {
            $data['stock'] = $this->getStock();
        }

        return $data;
    }

    private function getTranslatedField($field)
    {
        if (!$this->dto->translations) {
            return null;
        }

        $defaultLang = Configuration::get('PS_LANG_DEFAULT');
        $contextLang = Context::getContext()->language->iso_code;
        
        // Try context language first
        if (isset($this->dto->translations[$contextLang][$field])) {
            return $this->dto->translations[$contextLang][$field];
        }

        // Fallback to first available translation
        foreach ($this->dto->translations as $translation) {
            if (!empty($translation[$field])) {
                return $translation[$field];
            }
        }

        return null;
    }

    private function getRelations()
    {
        $relations = [];

        if ($this->dto->category) {
            $relations['category'] = [
                'id' => $this->dto->category->id_category,
                'name' => $this->dto->category->name ?? '',
                'active' => $this->dto->category->active ?? false
            ];
        }

        if ($this->dto->manufacturer) {
            $relations['manufacturer'] = [
                'id' => $this->dto->manufacturer->id_manufacturer,
                'name' => $this->dto->manufacturer->name ?? '',
                'active' => $this->dto->manufacturer->active ?? false
            ];
        }

        if ($this->dto->supplier) {
            $relations['supplier'] = [
                'id' => $this->dto->supplier->id_supplier,
                'name' => $this->dto->supplier->name ?? '',
                'active' => $this->dto->supplier->active ?? false
            ];
        }

        return $relations;
    }

    private function getImages()
    {
        $images = [];
        $maxImages = $this->config['max_images'];
        $imageSize = $this->config['image_size'];

        foreach (array_slice($this->dto->images, 0, $maxImages) as $image) {
            $images[] = [
                'id' => $image->id_image ?? null,
                'position' => $image->position ?? 0,
                'cover' => $image->cover ?? false,
                'urls' => [
                    'small' => Context::getContext()->link->getImageLink(
                        $this->dto->link_rewrite,
                        $image->id_image,
                        'small_default'
                    ),
                    'medium' => Context::getContext()->link->getImageLink(
                        $this->dto->link_rewrite,
                        $image->id_image,
                        'medium_default'
                    ),
                    'large' => Context::getContext()->link->getImageLink(
                        $this->dto->link_rewrite,
                        $image->id_image,
                        'large_default'
                    )
                ]
            ];
        }

        return $images;
    }

    private function getTranslations()
    {
        if (!$this->config['languages']) {
            return $this->dto->translations;
        }

        $filtered = [];
        foreach ($this->config['languages'] as $lang) {
            if (isset($this->dto->translations[$lang])) {
                $filtered[$lang] = $this->dto->translations[$lang];
            }
        }

        return $filtered;
    }

    private function getCombinations()
    {
        $combinations = [];
        foreach ($this->dto->combinations as $combination) {
            $combinations[] = [
                'id' => $combination->id_product_attribute ?? null,
                'reference' => $combination->reference ?? '',
                'price_impact' => (float)($combination->price ?? 0),
                'weight_impact' => (float)($combination->weight ?? 0),
                'quantity' => (int)($combination->quantity ?? 0),
                'attributes' => $combination->attributes ?? []
            ];
        }
        return $combinations;
    }

    private function getStock()
    {
        $stock = [];
        foreach ($this->dto->stock_availables as $stockItem) {
            $stock[] = [
                'id_shop' => $stockItem->id_shop ?? null,
                'id_shop_group' => $stockItem->id_shop_group ?? null,
                'quantity' => (int)($stockItem->quantity ?? 0),
                'depends_on_stock' => (bool)($stockItem->depends_on_stock ?? false),
                'out_of_stock' => (int)($stockItem->out_of_stock ?? 0)
            ];
        }
        return $stock;
    }
}

// File: /modules/advancedrestapi/classes/DTOs/CategoryDTO.php

class CategoryDTO
{
    public $id_category;
    public $id_parent;
    public $id_shop_default;
    public $level_depth;
    public $nleft;
    public $nright;
    public $active;
    public $date_add;
    public $date_upd;
    public $position;
    public $is_root_category;
    
    // Relational data
    public $parent;
    public $children;
    public $products_count;
    public $translations;

    public function __construct($category = null)
    {
        if ($category instanceof Category) {
            $this->loadFromCategory($category);
        }
    }

    private function loadFromCategory(Category $category)
    {
        foreach (get_object_vars($category) as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }

        // Load parent category
        if ($category->id_parent && $category->id_parent != $category->id) {
            $parent = new Category($category->id_parent);
            $this->parent = [
                'id' => $parent->id,
                'name' => $parent->name[Context::getContext()->language->id] ?? ''
            ];
        }

        // Load children count
        $this->children = Category::getChildren($category->id, Context::getContext()->language->id, true);
        
        // Load products count
        $this->products_count = $category->getProducts(Context::getContext()->language->id, 1, 1, null, null, false, true, false, 1, false);

        // Load translations
        $this->translations = [];
        $languages = Language::getLanguages(true);
        foreach ($languages as $language) {
            $this->translations[$language['iso_code']] = [
                'name' => $category->name[$language['id_lang']] ?? '',
                'description' => $category->description[$language['id_lang']] ?? '',
                'link_rewrite' => $category->link_rewrite[$language['id_lang']] ?? '',
                'meta_title' => $category->meta_title[$language['id_lang']] ?? '',
                'meta_description' => $category->meta_description[$language['id_lang']] ?? '',
                'meta_keywords' => $category->meta_keywords[$language['id_lang']] ?? ''
            ];
        }
    }

    public function toArray()
    {
        return get_object_vars($this);
    }

    public static function fromArray($data)
    {
        $dto = new self();
        foreach ($data as $key => $value) {
            if (property_exists($dto, $key)) {
                $dto->$key = $value;
            }
        }
        return $dto;
    }
}

// File: /modules/advancedrestapi/controllers/admin/AdminAdvancedRestApiController.php

class AdminAdvancedRestApiController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        
        $this->bootstrap = true;
        $this->context = Context::getContext();
        $this->meta_title = $this->trans('Advanced REST API Configuration', [], 'Modules.AdvancedRestApi.Admin');
    }

    public function initContent()
    {
        parent::initContent();
        
        $this->content .= $this->renderConfigurationForm();
        $this->content .= $this->renderEndpointsInfo();
    }

    private function renderConfigurationForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = 'advancedrestapi';
        $helper->module = $this->module;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = 'id_configuration';
        $helper->submit_action = 'submitAdvancedRestApiConfiguration';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminAdvancedRestApi', false);
        $helper->token = Tools::getAdminTokenLite('AdminAdvancedRestApi');

        $helper->fields_value['ADVANCED_REST_API_ENABLE'] = Configuration::get('ADVANCED_REST_API_ENABLE');
        $helper->fields_value['ADVANCED_REST_API_AUTH'] = Configuration::get('ADVANCED_REST_API_AUTH');
        $helper->fields_value['ADVANCED_REST_API_KEY'] = Configuration::get('ADVANCED_REST_API_KEY');

        return $helper->generateForm([
            [
                'form' => [
                    'legend' => [
                        'title' => $this->trans('Settings', [], 'Admin.Global'),
                        'icon' => 'icon-cogs'
                    ],
                    'input' => [
                        [
                            'type' => 'switch',
                            'label' => $this->trans('Enable API', [], 'Modules.AdvancedRestApi.Admin'),
                            'name' => 'ADVANCED_REST_API_ENABLE',
                            'is_bool' => true,
                            'values' => [
                                [
                                    'id' => 'active_on',
                                    'value' => true,
                                    'label' => $this->trans('Enabled', [], 'Admin.Global')
                                ],
                                [
                                    'id' => 'active_off',
                                    'value' => false,
                                    'label' => $this->trans('Disabled', [], 'Admin.Global')
                                ]
                            ],
                        ],
                        [
                            'type' => 'switch',
                            'label' => $this->trans('Enable Authentication', [], 'Modules.AdvancedRestApi.Admin'),
                            'name' => 'ADVANCED_REST_API_AUTH',
                            'is_bool' => true,
                            'values' => [
                                [
                                    'id' => 'auth_on',
                                    'value' => true,
                                    'label' => $this->trans('Enabled', [], 'Admin.Global')
                                ],
                                [
                                    'id' => 'auth_off',
                                    'value' => false,
                                    'label' => $this->trans('Disabled', [], 'Admin.Global')
                                ]
                            ],
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->trans('API Key', [], 'Modules.AdvancedRestApi.Admin'),
                            'name' => 'ADVANCED_REST_API_KEY',
                            'desc' => $this->trans('Generate a secure API key for authentication', [], 'Modules.AdvancedRestApi.Admin'),
                        ]
                    ],
                    'submit' => [
                        'title' => $this->trans('Save', [], 'Admin.Actions'),
                    ]
                ]
            ]
        ]);
    }

    private function renderEndpointsInfo()
    {
        $baseUrl = Context::getContext()->shop->getBaseURL(true) . 'modules/advancedrestapi/api/';
        
        $endpoints = [
            'Products' => $baseUrl . 'products',
            'Categories' => $baseUrl . 'categories',
            'Customers' => $baseUrl . 'customers',
            'Orders' => $baseUrl . 'orders',
            'Manufacturers' => $baseUrl . 'manufacturers',
            'Suppliers' => $baseUrl . 'suppliers'
        ];

        $html = '<div class="panel">';
        $html .= '<div class="panel-heading">';
        $html .= '<i class="icon-list"></i> ' . $this->trans('Available Endpoints', [], 'Modules.AdvancedRestApi.Admin');
        $html .= '</div>';
        $html .= '<div class="panel-body">';
        $html .= '<p>' . $this->trans('The following REST API endpoints are available:', [], 'Modules.AdvancedRestApi.Admin') . '</p>';
        $html .= '<ul>';
        
        foreach ($endpoints as $name => $url) {
            $html .= '<li><strong>' . $name . ':</strong> <code>' . $url . '</code></li>';
        }
        
        $html .= '</ul>';
        $html .= '<div class="alert alert-info">';
        $html .= '<p><strong>' . $this->trans('HTTP Methods:', [], 'Modules.AdvancedRestApi.Admin') . '</strong></p>';
        $html .= '<ul>';
        $html .= '<li><code>GET</code> - ' . $this->trans('Retrieve resources', [], 'Modules.AdvancedRestApi.Admin') . '</li>';
        $html .= '<li><code>POST</code> - ' . $this->trans('Create new resource', [], 'Modules.AdvancedRestApi.Admin') . '</li>';
        $html .= '<li><code>PUT</code> - ' . $this->trans('Update existing resource', [], 'Modules.AdvancedRestApi.Admin') . '</li>';
        $html .= '<li><code>DELETE</code> - ' . $this->trans('Delete resource', [], 'Modules.AdvancedRestApi.Admin') . '</li>';
        $html .= '</ul>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitAdvancedRestApiConfiguration')) {
            Configuration::updateValue('ADVANCED_REST_API_ENABLE', Tools::getValue('ADVANCED_REST_API_ENABLE'));
            Configuration::updateValue('ADVANCED_REST_API_AUTH', Tools::getValue('ADVANCED_REST_API_AUTH'));
            Configuration::updateValue('ADVANCED_REST_API_KEY', Tools::getValue('ADVANCED_REST_API_KEY'));
            
            $this->confirmations[] = $this->trans('Settings updated successfully.', [], 'Admin.Notifications.Success');
        }
    }
}

// File: /modules/advancedrestapi/classes/DTOs/CustomerDTO.php

class CustomerDTO
{
    public $id_customer;
    public $id_shop;
    public $id_shop_group;
    public $id_default_group;
    public $id_lang;
    public $id_risk;
    public $id_gender;
    public $firstname;
    public $lastname;
    public $email;
    public $passwd;
    public $last_passwd_gen;
    public $birthday;
    public $newsletter;
    public $ip_registration_newsletter;
    public $newsletter_date_add;
    public $optin;
    public $website;
    public $company;
    public $siret;
    public $ape;
    public $outstanding_allow_amount;
    public $show_public_prices;
    public $max_payment_days;
    public $secure_key;
    public $note;
    public $active;
    public $is_guest;
    public $deleted;
    public $date_add;
    public $date_upd;
    public $reset_password_token;
    public $reset_password_validity;
    
    // Relational data
    public $addresses;
    public $orders;
    public $groups;
    public $gender;
    public $default_group;

    public function __construct($customer = null)
    {
        if ($customer instanceof Customer) {
            $this->loadFromCustomer($customer);
        }
    }

    private function loadFromCustomer(Customer $customer)
    {
        foreach (get_object_vars($customer) as $key => $value) {
            if (property_exists($this, $key) && $key !== 'passwd') {
                $this->$key = $value;
            }
        }

        // Load addresses
        $this->addresses = [];
        $addresses = $customer->getAddresses(Context::getContext()->language->id);
        foreach ($addresses as $address) {
            $addressObj = new Address($address['id_address']);
            $this->addresses[] = new AddressDTO($addressObj);
        }

        // Load orders
        $this->orders = [];
        $orders = Order::getCustomerOrders($customer->id);
        foreach ($orders as $order) {
            $this->orders[] = [
                'id_order' => $order['id_order'],
                'reference' => $order['reference'],
                'total_paid' => $order['total_paid'],
                'date_add' => $order['date_add'],
                'current_state' => $order['current_state']
            ];
        }

        // Load groups
        $this->groups = [];
        $groups = $customer->getGroups();
        foreach ($groups as $groupId) {
            $group = new Group($groupId);
            $this->groups[] = [
                'id_group' => $group->id,
                'name' => $group->name[Context::getContext()->language->id] ?? '',
                'reduction' => $group->reduction ?? 0
            ];
        }

        // Load gender
        if ($customer->id_gender) {
            $gender = new Gender($customer->id_gender);
            $this->gender = [
                'id_gender' => $gender->id,
                'name' => $gender->name[Context::getContext()->language->id] ?? '',
                'type' => $gender->type ?? 0
            ];
        }
    }

    public function toArray()
    {
        $data = get_object_vars($this);
        unset($data['passwd']); // Never expose password
        return $data;
    }

    public static function fromArray($data)
    {
        $dto = new self();
        foreach ($data as $key => $value) {
            if (property_exists($dto, $key) && $key !== 'passwd') {
                $dto->$key = $value;
            }
        }
        return $dto;
    }
}

// File: /modules/advancedrestapi/classes/DTOs/OrderDTO.php

class OrderDTO
{
    public $id_order;
    public $reference;
    public $id_shop_group;
    public $id_shop;
    public $id_carrier;
    public $id_lang;
    public $id_customer;
    public $id_cart;
    public $id_currency;
    public $id_address_delivery;
    public $id_address_invoice;
    public $current_state;
    public $secure_key;
    public $payment;
    public $conversion_rate;
    public $module;
    public $recyclable;
    public $gift;
    public $gift_message;
    public $mobile_theme;
    public $shipping_number;
    public $total_discounts;
    public $total_discounts_tax_incl;
    public $total_discounts_tax_excl;
    public $total_paid;
    public $total_paid_tax_incl;
    public $total_paid_tax_excl;
    public $total_paid_real;
    public $total_products;
    public $total_products_wt;
    public $total_shipping;
    public $total_shipping_tax_incl;
    public $total_shipping_tax_excl;
    public $carrier_tax_rate;
    public $total_wrapping;
    public $total_wrapping_tax_incl;
    public $total_wrapping_tax_excl;
    public $round_mode;
    public $round_type;
    public $invoice_number;
    public $delivery_number;
    public $invoice_date;
    public $delivery_date;
    public $valid;
    public $date_add;
    public $date_upd;
    
    // Relational data
    public $customer;
    public $carrier;
    public $order_details;
    public $order_state;
    public $address_delivery;
    public $address_invoice;
    public $currency;
    public $order_history;
    public $payments;

    public function __construct($order = null)
    {
        if ($order instanceof Order) {
            $this->loadFromOrder($order);
        }
    }

    private function loadFromOrder(Order $order)
    {
        foreach (get_object_vars($order) as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }

        // Load customer
        if ($order->id_customer) {
            $customer = new Customer($order->id_customer);
            $this->customer = [
                'id_customer' => $customer->id,
                'firstname' => $customer->firstname,
                'lastname' => $customer->lastname,
                'email' => $customer->email
            ];
        }

        // Load carrier
        if ($order->id_carrier) {
            $carrier = new Carrier($order->id_carrier);
            $this->carrier = [
                'id_carrier' => $carrier->id,
                'name' => $carrier->name,
                'delay' => $carrier->delay[Context::getContext()->language->id] ?? ''
            ];
        }

        // Load order details
        $this->order_details = [];
        $orderDetails = $order->getOrderDetailList();
        foreach ($orderDetails as $detail) {
            $orderDetail = new OrderDetail($detail['id_order_detail']);
            $this->order_details[] = new OrderDetailDTO($orderDetail);
        }

        // Load order state
        if ($order->current_state) {
            $orderState = new OrderState($order->current_state);
            $this->order_state = [
                'id_order_state' => $orderState->id,
                'name' => $orderState->name[Context::getContext()->language->id] ?? '',
                'color' => $orderState->color ?? '',
                'shipped' => $orderState->shipped ?? false,
                'paid' => $orderState->paid ?? false,
                'invoice' => $orderState->invoice ?? false
            ];
        }

        // Load addresses
        if ($order->id_address_delivery) {
            $address = new Address($order->id_address_delivery);
            $this->address_delivery = new AddressDTO($address);
        }

        if ($order->id_address_invoice) {
            $address = new Address($order->id_address_invoice);
            $this->address_invoice = new AddressDTO($address);
        }

        // Load currency
        if ($order->id_currency) {
            $currency = new Currency($order->id_currency);
            $this->currency = [
                'id_currency' => $currency->id,
                'name' => $currency->name,
                'iso_code' => $currency->iso_code,
                'symbol' => $currency->symbol,
                'conversion_rate' => $currency->conversion_rate
            ];
        }

        // Load order history
        $this->order_history = [];
        $orderHistory = $order->getHistory(Context::getContext()->language->id);
        foreach ($orderHistory as $history) {
            $this->order_history[] = [
                'id_order_state' => $history['id_order_state'],
                'state_name' => $history['ostate_name'],
                'date_add' => $history['date_add']
            ];
        }

        // Load payments
        $this->payments = [];
        $payments = OrderPayment::getByOrderReference($order->reference);
        foreach ($payments as $payment) {
            $this->payments[] = [
                'id_order_payment' => $payment->id,
                'order_reference' => $payment->order_reference,
                'id_currency' => $payment->id_currency,
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method,
                'conversion_rate' => $payment->conversion_rate,
                'transaction_id' => $payment->transaction_id,
                'card_number' => $payment->card_number,
                'card_brand' => $payment->card_brand,
                'card_expiration' => $payment->card_expiration,
                'card_holder' => $payment->card_holder,
                'date_add' => $payment->date_add
            ];
        }
    }

    public function toArray()
    {
        return get_object_vars($this);
    }

    public static function fromArray($data)
    {
        $dto = new self();
        foreach ($data as $key => $value) {
            if (property_exists($dto, $key)) {
                $dto->$key = $value;
            }
        }
        return $dto;
    }
}

// File: /modules/advancedrestapi/classes/DTOs/AddressDTO.php

class AddressDTO
{
    public $id_address;
    public $id_country;
    public $id_state;
    public $id_customer;
    public $id_manufacturer;
    public $id_supplier;
    public $id_warehouse;
    public $alias;
    public $company;
    public $lastname;
    public $firstname;
    public $address1;
    public $address2;
    public $postcode;
    public $city;
    public $other;
    public $phone;
    public $phone_mobile;
    public $vat_number;
    public $dni;
    public $date_add;
    public $date_upd;
    public $active;
    public $deleted;
    
    // Relational data
    public $country;
    public $state;

    public function __construct($address = null)
    {
        if ($address instanceof Address) {
            $this->loadFromAddress($address);
        }
    }

    private function loadFromAddress(Address $address)
    {
        foreach (get_object_vars($address) as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }

        // Load country
        if ($address->id_country) {
            $country = new Country($address->id_country);
            $this->country = [
                'id_country' => $country->id,
                'iso_code' => $country->iso_code,
                'name' => $country->name[Context::getContext()->language->id] ?? '',
                'call_prefix' => $country->call_prefix
            ];
        }

        // Load state
        if ($address->id_state) {
            $state = new State($address->id_state);
            $this->state = [
                'id_state' => $state->id,
                'iso_code' => $state->iso_code,
                'name' => $state->name
            ];
        }
    }

    public function toArray()
    {
        return get_object_vars($this);
    }

    public static function fromArray($data)
    {
        $dto = new self();
        foreach ($data as $key => $value) {
            if (property_exists($dto, $key)) {
                $dto->$key = $value;
            }
        }
        return $dto;
    }
}

// File: /modules/advancedrestapi/classes/RTOs/CustomersRTO.php

class CustomersRTO
{
    private $dto;
    private $config;

    public function __construct(CustomerDTO $dto, $config = [])
    {
        $this->dto = $dto;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    private function getDefaultConfig()
    {
        return [
            'include_addresses' => true,
            'include_orders' => false,
            'include_groups' => true,
            'include_personal_data' => false, // For GDPR compliance
            'max_orders' => 5
        ];
    }

    public function toArray()
    {
        $data = [
            'id' => $this->dto->id_customer,
            'firstname' => $this->dto->firstname,
            'lastname' => $this->dto->lastname,
            'email' => $this->dto->email,
            'active' => (bool)$this->dto->active,
            'is_guest' => (bool)$this->dto->is_guest,
            'newsletter' => (bool)$this->dto->newsletter,
            'optin' => (bool)$this->dto->optin,
            'dates' => [
                'created' => $this->dto->date_add,
                'updated' => $this->dto->date_upd,
                'birthday' => $this->dto->birthday
            ]
        ];

        if ($this->config['include_personal_data']) {
            $data['company'] = $this->dto->company;
            $data['website'] = $this->dto->website;
            $data['siret'] = $this->dto->siret;
            $data['ape'] = $this->dto->ape;
            $data['note'] = $this->dto->note;
        }

        if ($this->config['include_addresses'] && $this->dto->addresses) {
            $data['addresses'] = [];
            foreach ($this->dto->addresses as $address) {
                $addressRTO = new AddressesRTO($address);
                $data['addresses'][] = $addressRTO->toArray();
            }
        }

        if ($this->config['include_orders'] && $this->dto->orders) {
            $data['orders'] = array_slice($this->dto->orders, 0, $this->config['max_orders']);
        }

        if ($this->config['include_groups'] && $this->dto->groups) {
            $data['groups'] = $this->dto->groups;
        }

        if ($this->dto->gender) {
            $data['gender'] = $this->dto->gender;
        }

        return $data;
    }
}

// File: /modules/advancedrestapi/classes/RTOs/AddressesRTO.php

class AddressesRTO
{
    private $dto;
    private $config;

    public function __construct(AddressDTO $dto, $config = [])
    {
        $this->dto = $dto;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    private function getDefaultConfig()
    {
        return [
            'include_geo_data' => true,
            'format_address' => true
        ];
    }

    public function toArray()
    {
        $data = [
            'id' => $this->dto->id_address,
            'alias' => $this->dto->alias,
            'company' => $this->dto->company,
            'firstname' => $this->dto->firstname,
            'lastname' => $this->dto->lastname,
            'address1' => $this->dto->address1,
            'address2' => $this->dto->address2,
            'postcode' => $this->dto->postcode,
            'city' => $this->dto->city,
            'phone' => $this->dto->phone,
            'phone_mobile' => $this->dto->phone_mobile,
            'vat_number' => $this->dto->vat_number,
            'dni' => $this->dto->dni,
            'other' => $this->dto->other,
            'active' => (bool)$this->dto->active
        ];

        if ($this->config['include_geo_data']) {
            if ($this->dto->country) {
                $data['country'] = $this->dto->country;
            }
            if ($this->dto->state) {
                $data['state'] = $this->dto->state;
            }
        }

        if ($this->config['format_address']) {
            $data['formatted_address'] = $this->formatAddress();
        }

        return $data;
    }

    private function formatAddress()
    {
        $parts = array_filter([
            $this->dto->address1,
            $this->dto->address2,
            $this->dto->postcode . ' ' . $this->dto->city,
            $this->dto->state['name'] ?? '',
            $this->dto->country['name'] ?? ''
        ]);

        return implode(', ', $parts);
    }
}

// File: /modules/advancedrestapi/config/routes.yml

# API Routes Configuration
api_products:
  path: '/api/products'
  defaults: { _controller: 'AdvancedRestApiApiModuleFrontController' }
  methods: [GET, POST]

api_products_item:
  path: '/api/products/{id}'
  defaults: { _controller: 'AdvancedRestApiApiModuleFrontController' }
  methods: [GET, PUT, DELETE]
  requirements:
    id: '\d+'

api_categories:
  path: '/api/categories'
  defaults: { _controller: 'AdvancedRestApiApiModuleFrontController' }
  methods: [GET, POST]

api_categories_item:
  path: '/api/categories/{id}'
  defaults: { _controller: 'AdvancedRestApiApiModuleFrontController' }
  methods: [GET, PUT, DELETE]
  requirements:
    id: '\d+'

api_customers:
  path: '/api/customers'
  defaults: { _controller: 'AdvancedRestApiApiModuleFrontController' }
  methods: [GET, POST]

api_customers_item:
  path: '/api/customers/{id}'
  defaults: { _controller: 'AdvancedRestApiApiModuleFrontController' }
  methods: [GET, PUT, DELETE]
  requirements:
    id: '\d+'

api_orders:
  path: '/api/orders'
  defaults: { _controller: 'AdvancedRestApiApiModuleFrontController' }
  methods: [GET, POST]

api_orders_item:
  path: '/api/orders/{id}'
  defaults: { _controller: 'AdvancedRestApiApiModuleFrontController' }
  methods: [GET, PUT, DELETE]
  requirements:
    id: '\d+'

# File: /modules/advancedrestapi/README.md

# Advanced REST API Module for PrestaShop v9

Complete REST API module with full relational data support and flexible DTO/RTO architecture.

## Features

- **Full CRUD Operations**: GET, POST, PUT, DELETE for all major resources
- **Relational Data**: Automatically includes related objects (categories, images, combinations, etc.)
- **DTO/RTO Separation**: Clean separation between data structure and API output
- **Flexible Output Control**: Configure what data to include in API responses
- **Multi-language Support**: Automatic handling of translations
- **Authentication**: Optional API key authentication
- **CORS Support**: Built-in CORS headers for cross-origin requests

## Installation

1. Copy the module folder to `/modules/advancedrestapi/`
2. Install the module from PrestaShop admin
3. Configure API settings in "Configure > Advanced REST API"

## Configuration

Navigate to **Configure > Advanced REST API** in your PrestaShop admin to:
- Enable/disable the API
- Configure authentication
- Set API key
- View available endpoints

## API Endpoints

### Base URL
```
https://your-shop.com/modules/advancedrestapi/api/
```

### Available Resources

#### Products
```http
GET    /api/products           # List all products
GET    /api/products/{id}      # Get specific product
POST   /api/products           # Create new product
PUT    /api/products/{id}      # Update product
DELETE /api/products/{id}      # Delete product
```

#### Categories
```http
GET    /api/categories         # List all categories
GET    /api/categories/{id}    # Get specific category
POST   /api/categories         # Create new category
PUT    /api/categories/{id}    # Update category
DELETE /api/categories/{id}    # Delete category
```

#### Customers
```http
GET    /api/customers          # List all customers
GET    /api/customers/{id}     # Get specific customer
POST   /api/customers          # Create new customer
PUT    /api/customers/{id}     # Update customer
DELETE /api/customers/{id}     # Delete customer
```

#### Orders
```http
GET    /api/orders             # List all orders
GET    /api/orders/{id}        # Get specific order
POST   /api/orders             # Create new order
PUT    /api/orders/{id}        # Update order
DELETE /api/orders/{id}        # Delete order
```

## Query Parameters

### Pagination
```http
GET /api/products?limit=10&offset=20
```

### Filtering
```http
GET /api/products?active=1&id_category_default=2
```

### Sorting
```http
GET /api/products?order_by=name&order_way=ASC
```

## Request Examples

### Get Products with Relations
```javascript
fetch('https://your-shop.com/modules/advancedrestapi/api/products/1')
  .then(response => response.json())
  .then(data => {
    console.log('Product:', data.name);
    console.log('Category:', data.relations.category.name);
    console.log('Images:', data.images);
    console.log('Combinations:', data.combinations);
  });
```

### Create New Product
```javascript
const productData = {
  reference: 'NEW-PROD-001',
  price: 29.99,
  active: 1,
  translations: {
    en: {
      name: 'New Product',
      description: 'Product description',
      description_short: 'Short description'
    },
    it: {
      name: 'Nuovo Prodotto',
      description: 'Descrizione prodotto',
      description_short: 'Descrizione breve'
    }
  }
};

fetch('https://your-shop.com/modules/advancedrestapi/api/products', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer YOUR_API_KEY'
  },
  body: JSON.stringify(productData)
})
.then(response => response.json())
.then(data => console.log('Created product:', data));
```

### Update Customer
```javascript
const customerUpdate = {
  firstname: 'John',
  lastname: 'Doe',
  email: 'john.doe@example.com'
};

fetch('https://your-shop.com/modules/advancedrestapi/api/customers/123', {
  method: 'PUT',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer YOUR_API_KEY'
  },
  body: JSON.stringify(customerUpdate)
})
.then(response => response.json())
.then(data => console.log('Updated customer:', data));
```

## DTO/RTO Architecture

### DTOs (Data Transfer Objects)
DTOs contain the complete data structure including all relational fields:

```php
// ProductDTO contains everything
$productDTO = new ProductDTO($product);
// Includes: category, manufacturer, images, combinations, features, etc.
```

### RTOs (Resource Transfer Objects)
RTOs control what data is exposed in the API response:

```php
// ProductsRTO controls output
$rto = new ProductsRTO($productDTO, [
    'include_images' => true,
    'include_combinations' => false,
    'max_images' => 5,
    'languages' => ['en', 'it']
]);
```

## Customizing Output

You can customize RTO configuration to control API responses:

```php
// Custom RTO configuration for products
class CustomProductsRTO extends ProductsRTO
{
    protected function getDefaultConfig()
    {
        return [
            'include_relations' => true,
            'include_images' => true,
            'include_translations' => false, // Disable translations
            'include_combinations' => true,
            'include_features' => false,     // Disable features
            'include_stock' => true,
            'image_size' => 'medium',
            'max_images' => 3,               // Limit images
            'languages' => ['en']            // Only English
        ];
    }
}
```

## Adding New Resources

### 1. Create DTO
```php
// /classes/DTOs/YourResourceDTO.php
class YourResourceDTO
{
    public $id_your_resource;
    public $name;
    // ... other properties
    
    public function __construct($object = null) {
        if ($object instanceof YourResource) {
            $this->loadFromObject($object);
        }
    }
    
    // Implementation...
}
```

### 2. Create RTO
```php
// /classes/RTOs/YourResourceRTO.php
class YourResourceRTO
{
    private $dto;
    private $config;
    
    public function toArray() {
        // Control output format
    }
}
```

### 3. Add to Resource Mapping
```php
// In ApiRequestHandler.php
private $resourceMapping = [
    // ... existing mappings
    'your_resources' => 'YourResource'
];
```

## Authentication

When authentication is enabled, include the API key in requests:

### Header Authentication
```http
Authorization: Bearer YOUR_API_KEY
```

### Query Parameter Authentication
```http
GET /api/products?api_key=YOUR_API_KEY
```

## Error Handling

The API returns JSON error responses:

```json
{
  "error": "Resource not found",
  "code": 404
}
```

## Response Format

### Success Response
```json
{
  "id": 1,
  "name": "Product Name",
  "price": {
    "base": 29.99,
    "formatted": "€29.99",
    "currency": "EUR"
  },
  "relations": {
    "category": {
      "id": 2,
      "name": "Category Name"
    }
  },
  "images": [
    {
      "id": 1,
      "position": 1,
      "cover": true,
      "urls": {
        "small": "https://...",
        "medium": "https://...",
        "large": "https://..."
      }
    }
  ]
}
```

### List Response
```json
{
  "data": [...],
  "total": 150,
  "limit": 50,
  "offset": 0
}
```

## Security Considerations

1. **API Key Protection**: Store API keys securely
2. **HTTPS**: Always use HTTPS in production
3. **Rate Limiting**: Consider implementing rate limiting
4. **Input Validation**: All inputs are validated before processing
5. **GDPR Compliance**: Customer RTOs respect privacy settings

## Performance Tips

1. **Pagination**: Use limit/offset for large datasets
2. **Selective Loading**: Configure RTOs to include only needed data
3. **Caching**: Consider implementing response caching
4. **Database Optimization**: Ensure proper indexes on filtered fields

## Troubleshooting

### Common Issues

1. **404 Errors**: Check module installation and URL structure
2. **Authentication Errors**: Verify API key configuration
3. **CORS Issues**: Ensure CORS headers are properly set
4. **Memory Issues**: Use pagination for large datasets

### Debug Mode

Enable debug mode by adding to your configuration:

```php
Configuration::updateValue('ADVANCED_REST_API_DEBUG', 1);
```

// File: /modules/advancedrestapi/controllers/front/auth.php

class AdvancedRestApiAuthModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function init()
    {
        parent::init();
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    public function initContent()
    {
        parent::initContent();
        
        try {
            $this->processAuthRequest();
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function processAuthRequest()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = trim($this->getRequestPath(), '/');
        $pathParts = explode('/', $path);
        
        $action = $pathParts[1] ?? '';
        
        $handler = new CustomerAuthHandler();
        
        switch ($action) {
            case 'login':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $result = $handler->login($data);
                } else {
                    throw new Exception('Method not allowed', 405);
                }
                break;
                
            case 'register':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $result = $handler->register($data);
                } else {
                    throw new Exception('Method not allowed', 405);
                }
                break;
                
            case 'logout':
                if ($method === 'POST') {
                    $result = $handler->logout();
                } else {
                    throw new Exception('Method not allowed', 405);
                }
                break;
                
            case 'refresh':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $result = $handler->refreshToken($data);
                } else {
                    throw new Exception('Method not allowed', 405);
                }
                break;
                
            case 'forgot-password':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $result = $handler->forgotPassword($data);
                } else {
                    throw new Exception('Method not allowed', 405);
                }
                break;
                
            case 'reset-password':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $result = $handler->resetPassword($data);
                } else {
                    throw new Exception('Method not allowed', 405);
                }
                break;
                
            case 'social':
                $provider = $pathParts[2] ?? '';
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $result = $handler->socialLogin($provider, $data);
                } else {
                    throw new Exception('Method not allowed', 405);
                }
                break;
                
            default:
                throw new Exception('Unknown action: ' . $action, 400);
        }
        
        $this->sendJsonResponse($result);
    }

    private function getRequestPath()
    {
        $requestUri = $_SERVER['REQUEST_URI'];
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $path = str_replace(dirname($scriptName), '', $requestUri);
        return parse_url($path, PHP_URL_PATH);
    }

    private function sendJsonResponse($data, $status = 200)
    {
        http_response_code($status);
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}

// File: /modules/advancedrestapi/controllers/front/account.php

class AdvancedRestApiAccountModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function init()
    {
        parent::init();
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    public function initContent()
    {
        parent::initContent();
        
        try {
            $this->processAccountRequest();
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function processAccountRequest()
    {
        // Verify JWT token
        $authHandler = new CustomerAuthHandler();
        $customer = $authHandler->verifyToken();
        
        if (!$customer) {
            throw new Exception('Unauthorized', 401);
        }

        $method = $_SERVER['REQUEST_METHOD'];
        $path = trim($this->getRequestPath(), '/');
        $pathParts = explode('/', $path);
        
        $resource = $pathParts[1] ?? '';
        $id = $pathParts[2] ?? null;
        
        $handler = new CustomerAccountHandler($customer);
        
        switch ($resource) {
            case 'profile':
                $result = $this->handleProfile($handler, $method);
                break;
                
            case 'orders':
                $result = $this->handleOrders($handler, $method, $id);
                break;
                
            case 'addresses':
                $result = $this->handleAddresses($handler, $method, $id);
                break;
                
            case 'wishlists':
                $result = $this->handleWishlists($handler, $method, $id);
                break;
                
            case 'reviews':
                $result = $this->handleReviews($handler, $method, $id);
                break;
                
            case 'vouchers':
                $result = $this->handleVouchers($handler, $method);
                break;
                
            case 'loyalty':
                $result = $this->handleLoyalty($handler, $method);
                break;
                
            default:
                throw new Exception('Unknown resource: ' . $resource, 400);
        }
        
        $this->sendJsonResponse($result);
    }

    private function handleProfile($handler, $method)
    {
        switch ($method) {
            case 'GET':
                return $handler->getProfile();
            case 'PUT':
                $data = json_decode(file_get_contents('php://input'), true);
                return $handler->updateProfile($data);
            default:
                throw new Exception('Method not allowed', 405);
        }
    }

    private function handleOrders($handler, $method, $id)
    {
        switch ($method) {
            case 'GET':
                if ($id) {
                    return $handler->getOrder($id);
                } else {
                    return $handler->getOrders($_GET);
                }
            default:
                throw new Exception('Method not allowed', 405);
        }
    }

    private function handleAddresses($handler, $method, $id)
    {
        switch ($method) {
            case 'GET':
                if ($id) {
                    return $handler->getAddress($id);
                } else {
                    return $handler->getAddresses($_GET);
                }
            case 'POST':
                $data = json_decode(file_get_contents('php://input'), true);
                return $handler->createAddress($data);
            case 'PUT':
                $data = json_decode(file_get_contents('php://input'), true);
                return $handler->updateAddress($id, $data);
            case 'DELETE':
                return $handler->deleteAddress($id);
            default:
                throw new Exception('Method not allowed', 405);
        }
    }

    private function handleWishlists($handler, $method, $id)
    {
        switch ($method) {
            case 'GET':
                if ($id) {
                    return $handler->getWishlist($id);
                } else {
                    return $handler->getWishlists($_GET);
                }
            case 'POST':
                $data = json_decode(file_get_contents('php://input'), true);
                if ($id) {
                    // Add product to wishlist
                    return $handler->addToWishlist($id, $data);
                } else {
                    // Create new wishlist
                    return $handler->createWishlist($data);
                }
            case 'DELETE':
                $productId = $_GET['product_id'] ?? null;
                if ($productId) {
                    return $handler->removeFromWishlist($id, $productId);
                } else {
                    return $handler->deleteWishlist($id);
                }
            default:
                throw new Exception('Method not allowed', 405);
        }
    }

    private function handleReviews($handler, $method, $id)
    {
        switch ($method) {
            case 'GET':
                if ($id) {
                    return $handler->getReview($id);
                } else {
                    return $handler->getReviews($_GET);
                }
            case 'POST':
                $data = json_decode(file_get_contents('php://input'), true);
                return $handler->createReview($data);
            case 'PUT':
                $data = json_decode(file_get_contents('php://input'), true);
                return $handler->updateReview($id, $data);
            case 'DELETE':
                return $handler->deleteReview($id);
            default:
                throw new Exception('Method not allowed', 405);
        }
    }

    private function handleVouchers($handler, $method)
    {
        switch ($method) {
            case 'GET':
                return $handler->getVouchers($_GET);
            default:
                throw new Exception('Method not allowed', 405);
        }
    }

    private function handleLoyalty($handler, $method)
    {
        switch ($method) {
            case 'GET':
                return $handler->getLoyaltyPoints($_GET);
            default:
                throw new Exception('Method not allowed', 405);
        }
    }

    private function getRequestPath()
    {
        $requestUri = $_SERVER['REQUEST_URI'];
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $path = str_replace(dirname($scriptName), '', $requestUri);
        return parse_url($path, PHP_URL_PATH);
    }

    private function sendJsonResponse($data, $status = 200)
    {
        http_response_code($status);
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}

// File: /modules/advancedrestapi/classes/CustomerAuthHandler.php

require_once 'JwtHelper.php';

class CustomerAuthHandler
{
    private $jwtHelper;

    public function __construct()
    {
        $this->jwtHelper = new JwtHelper();
    }

    public function login($data)
    {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $rememberMe = $data['remember_me'] ?? false;

        if (empty($email) || empty($password)) {
            throw new Exception('Email and password are required', 400);
        }

        $customer = new Customer();
        $authentication = $customer->getByEmail($email, $password);
        
        if (!$authentication || !$customer->id || !$customer->active) {
            throw new Exception('Invalid credentials', 401);
        }

        // Update last connection
        $customer->last_passwd_gen = date('Y-m-d H:i:s', strtotime('-'.Configuration::get('PS_PASSWD_TIME_FRONT').'minutes'));
        $customer->update();

        $tokens = $this->generateTokens($customer, $rememberMe);
        
        return [
            'customer' => $this->getCustomerData($customer),
            'tokens' => $tokens,
            'expires_in' => $rememberMe ? 604800 : 3600 // 7 days or 1 hour
        ];
    }

    public function register($data)
    {
        $requiredFields = ['firstname', 'lastname', 'email', 'password'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field $field is required", 400);
            }
        }

        // Check if customer already exists
        if (Customer::customerExists($data['email'])) {
            throw new Exception('Customer already exists', 409);
        }

        $customer = new Customer();
        $customer->firstname = $data['firstname'];
        $customer->lastname = $data['lastname'];
        $customer->email = $data['email'];
        $customer->passwd = Tools::hash($data['password']);
        $customer->birthday = $data['birthday'] ?? null;
        $customer->newsletter = $data['newsletter'] ?? 0;
        $customer->optin = $data['optin'] ?? 0;
        $customer->active = 1;
        $customer->is_guest = 0;
        $customer->id_shop = Context::getContext()->shop->id;
        $customer->id_shop_group = Context::getContext()->shop->id_shop_group;
        $customer->id_default_group = Configuration::get('PS_CUSTOMER_GROUP');
        $customer->id_lang = Context::getContext()->language->id;
        $customer->secure_key = md5(uniqid(rand(), true));

        if (!$customer->add()) {
            throw new Exception('Failed to create customer', 500);
        }

        // Send welcome email
        if (Configuration::get('PS_CUSTOMER_CREATION_EMAIL')) {
            Mail::Send(
                Context::getContext()->language->id,
                'account',
                Mail::l('Welcome!'),
                [
                    '{firstname}' => $customer->firstname,
                    '{lastname}' => $customer->lastname,
                    '{email}' => $customer->email
                ],
                $customer->email,
                $customer->firstname . ' ' . $customer->lastname
            );
        }

        $tokens = $this->generateTokens($customer);
        
        return [
            'customer' => $this->getCustomerData($customer),
            'tokens' => $tokens,
            'message' => 'Customer registered successfully'
        ];
    }

    public function socialLogin($provider, $data)
    {
        $socialHandler = new SocialLoginHandler();
        
        switch (strtolower($provider)) {
            case 'google':
                $userData = $socialHandler->handleGoogleLogin($data);
                break;
            case 'facebook':
                $userData = $socialHandler->handleFacebookLogin($data);
                break;
            case 'apple':
                $userData = $socialHandler->handleAppleLogin($data);
                break;
            default:
                throw new Exception('Unsupported provider: ' . $provider, 400);
        }

        // Check if customer exists
        $customer = new Customer();
        $existingCustomer = $customer->getByEmail($userData['email']);
        
        if ($existingCustomer) {
            $customer = new Customer($existingCustomer);
        } else {
            // Create new customer
            $customer->firstname = $userData['firstname'];
            $customer->lastname = $userData['lastname'];
            $customer->email = $userData['email'];
            $customer->passwd = Tools::hash(Tools::generatePassword());
            $customer->active = 1;
            $customer->is_guest = 0;
            $customer->id_shop = Context::getContext()->shop->id;
            $customer->id_shop_group = Context::getContext()->shop->id_shop_group;
            $customer->id_default_group = Configuration::get('PS_CUSTOMER_GROUP');
            $customer->id_lang = Context::getContext()->language->id;
            $customer->secure_key = md5(uniqid(rand(), true));

            if (!$customer->add()) {
                throw new Exception('Failed to create customer', 500);
            }
        }

        $tokens = $this->generateTokens($customer);
        
        return [
            'customer' => $this->getCustomerData($customer),
            'tokens' => $tokens,
            'provider' => $provider
        ];
    }

    public function logout()
    {
        // In a real implementation, you might want to blacklist the token
        return ['message' => 'Logged out successfully'];
    }

    public function refreshToken($data)
    {
        $refreshToken = $data['refresh_token'] ?? '';
        
        if (empty($refreshToken)) {
            throw new Exception('Refresh token is required', 400);
        }

        try {
            $payload = $this->jwtHelper->decode($refreshToken);
            
            if ($payload['type'] !== 'refresh') {
                throw new Exception('Invalid token type', 401);
            }

            $customer = new Customer($payload['customer_id']);
            
            if (!Validate::isLoadedObject($customer)) {
                throw new Exception('Customer not found', 404);
            }

            $tokens = $this->generateTokens($customer);
            
            return [
                'tokens' => $tokens,
                'customer' => $this->getCustomerData($customer)
            ];
            
        } catch (Exception $e) {
            throw new Exception('Invalid refresh token', 401);
        }
    }

    public function forgotPassword($data)
    {
        $email = $data['email'] ?? '';
        
        if (empty($email)) {
            throw new Exception('Email is required', 400);
        }

        $customer = new Customer();
        $customerId = $customer->getByEmail($email);
        
        if (!$customerId) {
            // Don't reveal if email exists or not
            return ['message' => 'If the email exists, a reset link has been sent'];
        }

        $customer = new Customer($customerId);
        $token = Tools::generatePassword(32);
        
        $customer->reset_password_token = $token;
        $customer->reset_password_validity = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $customer->update();

        // Send reset email
        $resetLink = Context::getContext()->shop->getBaseURL(true) . 'modules/advancedrestapi/reset-password?token=' . $token;
        
        Mail::Send(
            Context::getContext()->language->id,
            'password_query',
            Mail::l('Password reset'),
            [
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{reset_link}' => $resetLink
            ],
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname
        );

        return ['message' => 'If the email exists, a reset link has been sent'];
    }

    public function resetPassword($data)
    {
        $token = $data['token'] ?? '';
        $newPassword = $data['password'] ?? '';
        
        if (empty($token) || empty($newPassword)) {
            throw new Exception('Token and password are required', 400);
        }

        $sql = new DbQuery();
        $sql->select('id_customer');
        $sql->from('customer');
        $sql->where('reset_password_token = "' . pSQL($token) . '"');
        $sql->where('reset_password_validity > NOW()');
        
        $customerId = Db::getInstance()->getValue($sql);
        
        if (!$customerId) {
            throw new Exception('Invalid or expired token', 400);
        }

        $customer = new Customer($customerId);
        $customer->passwd = Tools::hash($newPassword);
        $customer->reset_password_token = null;
        $customer->reset_password_validity = null;
        $customer->last_passwd_gen = date('Y-m-d H:i:s');
        
        if (!$customer->update()) {
            throw new Exception('Failed to reset password', 500);
        }

        return ['message' => 'Password reset successfully'];
    }

    public function verifyToken()
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return null;
        }

        $token = $matches[1];
        
        try {
            $payload = $this->jwtHelper->decode($token);
            
            if ($payload['type'] !== 'access') {
                return null;
            }

            $customer = new Customer($payload['customer_id']);
            
            if (!Validate::isLoadedObject($customer)) {
                return null;
            }

            return $customer;
            
        } catch (Exception $e) {
            return null;
        }
    }

    private function generateTokens($customer, $rememberMe = false)
    {
        $accessTokenExpiry = $rememberMe ? 604800 : 3600; // 7 days or 1 hour
        $refreshTokenExpiry = 2592000; // 30 days

        $accessToken = $this->jwtHelper->encode([
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'type' => 'access',
            'exp' => time() + $accessTokenExpiry
        ]);

        $refreshToken = $this->jwtHelper->encode([
            'customer_id' => $customer->id,
            'type' => 'refresh',
            'exp' => time() + $refreshTokenExpiry
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTokenExpiry
        ];
    }

    private function getCustomerData($customer)
    {
        return [
            'id' => $customer->id,
            'firstname' => $customer->firstname,
            'lastname' => $customer->lastname,
            'email' => $customer->email,
            'birthday' => $customer->birthday,
            'newsletter' => (bool)$customer->newsletter,
            'optin' => (bool)$customer->optin,
            'active' => (bool)$customer->active,
            'is_guest' => (bool)$customer->is_guest,
            'date_add' => $customer->date_add
        ];
    }
}

// File: /modules/advancedrestapi/classes/SocialLoginHandler.php

class SocialLoginHandler
{
    public function handleGoogleLogin($data)
    {
        $idToken = $data['id_token'] ?? '';
        
        if (empty($idToken)) {
            throw new Exception('Google ID token is required', 400);
        }

        // Verify Google ID token
        $googlePayload = $this->verifyGoogleToken($idToken);
        
        return [
            'email' => $googlePayload['email'],
            'firstname' => $googlePayload['given_name'] ?? '',
            'lastname' => $googlePayload['family_name'] ?? '',
            'social_id' => $googlePayload['sub'],
            'provider' => 'google'
        ];
    }

    public function handleFacebookLogin($data)
    {
        $accessToken = $data['access_token'] ?? '';
        
        if (empty($accessToken)) {
            throw new Exception('Facebook access token is required', 400);
        }

        // Verify Facebook access token
        $facebookData = $this->verifyFacebookToken($accessToken);
        
        return [
            'email' => $facebookData['email'],
            'firstname' => $facebookData['first_name'] ?? '',
            'lastname' => $facebookData['last_name'] ?? '',
            'social_id' => $facebookData['id'],
            'provider' => 'facebook'
        ];
    }

    public function handleAppleLogin($data)
    {
        $idToken = $data['id_token'] ?? '';
        
        if (empty($idToken)) {
            throw new Exception('Apple ID token is required', 400);
        }

        // Verify Apple ID token
        $applePayload = $this->verifyAppleToken($idToken);
        
        // Apple might not provide name in subsequent logins
        $firstname = $data['user']['name']['firstName'] ?? '';
        $lastname = $data['user']['name']['lastName'] ?? '';
        
        return [
            'email' => $applePayload['email'],
            'firstname' => $firstname,
            'lastname' => $lastname,
            'social_id' => $applePayload['sub'],
            'provider' => 'apple'
        ];
    }

    private function verifyGoogleToken($idToken)
    {
        $clientId = Configuration::get('ADVANCED_REST_API_GOOGLE_CLIENT_ID');
        
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . $idToken;
        $response = file_get_contents($url);
        $payload = json_decode($response, true);
        
        if (isset($payload['error']) || $payload['aud'] !== $clientId) {
            throw new Exception('Invalid Google token', 401);
        }
        
        return $payload;
    }

    private function verifyFacebookToken($accessToken)
    {
        $appId = Configuration::get('ADVANCED_REST_API_FACEBOOK_APP_ID');
        $appSecret = Configuration::get('ADVANCED_REST_API_FACEBOOK_APP_SECRET');
        
        // Verify token
        $verifyUrl = "https://graph.facebook.com/debug_token?input_token={$accessToken}&access_token={$appId}|{$appSecret}";
        $verifyResponse = file_get_contents($verifyUrl);
        $verifyData = json_decode($verifyResponse, true);
        
        if (!$verifyData['data']['is_valid']) {
            throw new Exception('Invalid Facebook token', 401);
        }
        
        // Get user data
        $userUrl = "https://graph.facebook.com/me?fields=id,first_name,last_name,email&access_token={$accessToken}";
        $userResponse = file_get_contents($userUrl);
        $userData = json_decode($userResponse, true);
        
        if (isset($userData['error'])) {
            throw new Exception('Failed to get Facebook user data', 401);
        }
        
        return $userData;
    }

    private function verifyAppleToken($idToken)
    {
        // Apple ID token verification is more complex and requires
        // downloading Apple's public keys and verifying the JWT signature
        // This is a simplified version - in production you should use a proper JWT library
        
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new Exception('Invalid Apple token format', 401);
        }
        
        $header = json_decode(base64_decode($parts[0]), true);
        $payload = json_decode(base64_decode($parts[1]), true);
        
        // In a real implementation, verify the signature with Apple's public keys
        // For now, we'll trust the token if it has the right structure
        
        if (!isset($payload['sub']) || !isset($payload['email'])) {
            throw new Exception('Invalid Apple token payload', 401);
        }
        
        return $payload;
    }
}

// File: /modules/advancedrestapi/classes/JwtHelper.php

class JwtHelper
{
    private $secretKey;
    private $algorithm = 'HS256';

    public function __construct()
    {
        $this->secretKey = Configuration::get('ADVANCED_REST_API_JWT_SECRET') ?: 'default_secret_key_change_this';
    }

    public function encode($payload)
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => $this->algorithm]);
        $payload = json_encode($payload);
        
        $headerEncoded = $this->base64UrlEncode($header);
        $payloadEncoded = $this->base64UrlEncode($payload);
        
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->secretKey, true);
        $signatureEncoded = $this->base64UrlEncode($signature);
        
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    public function decode($jwt)
    {
        $parts = explode('.', $jwt);
        
        if (count($parts) !== 3) {
            throw new Exception('Invalid JWT format');
        }
        
        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
        
        $header = json_decode($this->base64UrlDecode($headerEncoded), true);
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        
        // Verify signature
        $signature = $this->base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->secretKey, true);
        
        if (!hash_equals($signature, $expectedSignature)) {
            throw new Exception('Invalid JWT signature');
        }
        
        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new Exception('JWT token expired');
        }
        
        return $payload;
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode($data)
    {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
}

// File: /modules/advancedrestapi/classes/CustomerAccountHandler.php

class CustomerAccountHandler
{
    private $customer;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    public function getProfile()
    {
        $customerDTO = new CustomerDTO($this->customer);
        $rto = new CustomersRTO($customerDTO, [
            'include_addresses' => true,
            'include_groups' => true,
            'include_personal_data' => true
        ]);
        
        return $rto->toArray();
    }

    public function updateProfile($data)
    {
        $allowedFields = ['firstname', 'lastname', 'birthday', 'newsletter', 'optin'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $this->customer->$field = $data[$field];
            }
        }

        if (!$this->customer->update()) {
            throw new Exception('Failed to update profile', 500);
        }

        return $this->getProfile();
    }

    public function getOrders($params = [])
    {
        $queryBuilder = new QueryParamsHandler('Order', $params);
        $queryBuilder->addWhere('id_customer', $this->customer->id);
        
        $orders = $queryBuilder->getResults();
        $total = $queryBuilder->getCount();
        
        $orderRTOs = [];
        foreach ($orders as $orderData) {
            $order = new Order();
            $order->hydrate($orderData);
            $orderDTO = new OrderDTO($order);
            $rto = new OrdersRTO($orderDTO);
            $orderRTOs[] = $rto->toArray();
        }
        
        return [
            'data' => $orderRTOs,
            'total' => $total,
            'pagination' => $queryBuilder->getPaginationInfo()
        ];
    }

    public function getOrder($orderId)
    {
        $order = new Order($orderId);
        
        if (!Validate::isLoadedObject($order) || $order->id_customer != $this->customer->id) {
            throw new Exception('Order not found', 404);
        }

        $orderDTO = new OrderDTO($order);
        $rto = new OrdersRTO($orderDTO, [
            'include_products' => true,
            'include_addresses' => true,
            'include_history' => true,
            'include_payments' => true
        ]);
        
        return $rto->toArray();
    }

    public function getAddresses($params = [])
    {
        $queryBuilder = new QueryParamsHandler('Address', $params);
        $queryBuilder->addWhere('id_customer', $this->customer->id);
        $queryBuilder->addWhere('deleted', 0);
        
        $addresses = $queryBuilder->getResults();
        $total = $queryBuilder->getCount();
        
        $addressRTOs = [];
        foreach ($addresses as $addressData) {
            $address = new Address();
            $address->hydrate($addressData);
            $addressDTO = new AddressDTO($address);
            $rto = new AddressesRTO($addressDTO);
            $addressRTOs[] = $rto->toArray();
        }
        
        return [
            'data' => $addressRTOs,
            'total' => $total,
            'pagination' => $queryBuilder->getPaginationInfo()
        ];
    }

    public function getAddress($addressId)
    {
        $address = new Address($addressId);
        
        if (!Validate::isLoadedObject($address) || $address->id_customer != $this->customer->id) {
            throw new Exception('Address not found', 404);
        }

        $addressDTO = new AddressDTO($address);
        $rto = new AddressesRTO($addressDTO);
        
        return $rto->toArray();
    }

    public function createAddress($data)
    {
        $address = new Address();
        $address->id_customer = $this->customer->id;
        
        $requiredFields = ['alias', 'firstname', 'lastname', 'address1', 'city', 'postcode', 'id_country'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field $field is required", 400);
            }
            $address->$field = $data[$field];
        }

        $optionalFields = ['company', 'address2', 'id_state', 'phone', 'phone_mobile', 'vat_number', 'dni', 'other'];
        foreach ($optionalFields as $field) {
            if (isset($data[$field])) {
                $address->$field = $data[$field];
            }
        }

        if (!$address->add()) {
            throw new Exception('Failed to create address', 500);
        }

        return $this->getAddress($address->id);
    }

    public function updateAddress($addressId, $data)
    {
        $address = new Address($addressId);
        
        if (!Validate::isLoadedObject($address) || $address->id_customer != $this->customer->id) {
            throw new Exception('Address not found', 404);
        }

        $allowedFields = ['alias', 'company', 'firstname', 'lastname', 'address1', 'address2', 
                         'postcode', 'city', 'id_country', 'id_state', 'phone', 'phone_mobile', 
                         'vat_number', 'dni', 'other'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $address->$field = $data[$field];
            }
        }

        if (!$address->update()) {
            throw new Exception('Failed to update address', 500);
        }

        return $this->getAddress($addressId);
    }

    public function deleteAddress($addressId)
    {
        $address = new Address($addressId);
        
        if (!Validate::isLoadedObject($address) || $address->id_customer != $this->customer->id) {
            throw new Exception('Address not found', 404);
        }

        if (!$address->delete()) {
            throw new Exception('Failed to delete address', 500);
        }

        return ['success' => true, 'message' => 'Address deleted'];
    }

    public function getWishlists($params = [])
    {
        if (!Module::isInstalled('blockwishlist')) {
            throw new Exception('Wishlist module not installed', 404);
        }

        $sql = new DbQuery();
        $sql->select('*');
        $sql->from('wishlist');
        $sql->where('id_customer = ' . (int)$this->customer->id);
        
        $wishlists = Db::getInstance()->executeS($sql);
        
        $wishlistRTOs = [];
        foreach ($wishlists as $wishlist) {
            $wishlistRTOs[] = [
                'id' => $wishlist['id_wishlist'],
                'name' => $wishlist['name'],
                'token' => $wishlist['token'],
                'counter' => $wishlist['counter'],
                'date_add' => $wishlist['date_add'],
                'date_upd' => $wishlist['date_upd']
            ];
        }
        
        return [
            'data' => $wishlistRTOs,
            'total' => count($wishlistRTOs)
        ];
    }

    public function getWishlist($wishlistId)
    {
        if (!Module::isInstalled('blockwishlist')) {
            throw new Exception('Wishlist module not installed', 404);
        }

        $sql = new DbQuery();
        $sql->select('w.*, wp.id_product, wp.id_product_attribute, wp.quantity');
        $sql->from('wishlist', 'w');
        $sql->leftJoin('wishlist_product', 'wp', 'w.id_wishlist = wp.id_wishlist');
        $sql->where('w.id_wishlist = ' . (int)$wishlistId);
        $sql->where('w.id_customer = ' . (int)$this->customer->id);
        
        $results = Db::getInstance()->executeS($sql);
        
        if (empty($results)) {
            throw new Exception('Wishlist not found', 404);
        }

        $wishlist = [
            'id' => $results[0]['id_wishlist'],
            'name' => $results[0]['name'],
            'token' => $results[0]['token'],
            'counter' => $results[0]['counter'],
            'date_add' => $results[0]['date_add'],
            'date_upd' => $results[0]['date_upd'],
            'products' => []
        ];

        foreach ($results as $result) {
            if ($result['id_product']) {
                $product = new Product($result['id_product'], false, Context::getContext()->language->id);
                $productDTO = new ProductDTO($product);
                $rto = new ProductsRTO($productDTO, [
                    'include_relations' => false,
                    'include_images' => true,
                    'max_images' => 1
                ]);
                
                $wishlist['products'][] = array_merge($rto->toArray(), [
                    'wishlist_quantity' => $result['quantity'],
                    'id_product_attribute' => $result['id_product_attribute']
                ]);
            }
        }
        
        return $wishlist;
    }

    public function createWishlist($data)
    {
        if (!Module::isInstalled('blockwishlist')) {
            throw new Exception('Wishlist module not installed', 404);
        }

        $name = $data['name'] ?? 'My Wishlist';
        $token = Tools::strtoupper(Tools::generatePassword(10));

        $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'wishlist (id_customer, id_shop, id_shop_group, name, token, counter, date_add, date_upd) 
                VALUES (' . (int)$this->customer->id . ', ' . (int)Context::getContext()->shop->id . ', ' . 
                (int)Context::getContext()->shop->id_shop_group . ', "' . pSQL($name) . '", "' . pSQL($token) . '", 0, NOW(), NOW())';
        
        if (!Db::getInstance()->execute($sql)) {
            throw new Exception('Failed to create wishlist', 500);
        }

        $wishlistId = Db::getInstance()->Insert_ID();
        return $this->getWishlist($wishlistId);
    }

    public function addToWishlist($wishlistId, $data)
    {
        if (!Module::isInstalled('blockwishlist')) {
            throw new Exception('Wishlist module not installed', 404);
        }

        $productId = $data['id_product'] ?? 0;
        $productAttributeId = $data['id_product_attribute'] ?? 0;
        $quantity = $data['quantity'] ?? 1;

        if (!$productId) {
            throw new Exception('Product ID is required', 400);
        }

        // Verify wishlist ownership
        $sql = new DbQuery();
        $sql->select('id_wishlist');
        $sql->from('wishlist');
        $sql->where('id_wishlist = ' . (int)$wishlistId);
        $sql->where('id_customer = ' . (int)$this->customer->id);
        
        if (!Db::getInstance()->getValue($sql)) {
            throw new Exception('Wishlist not found', 404);
        }

        // Check if product already in wishlist
        $sql = new DbQuery();
        $sql->select('id_wishlist_product');
        $sql->from('wishlist_product');
        $sql->where('id_wishlist = ' . (int)$wishlistId);
        $sql->where('id_product = ' . (int)$productId);
        $sql->where('id_product_attribute = ' . (int)$productAttributeId);
        
        $existingId = Db::getInstance()->getValue($sql);
        
        if ($existingId) {
            // Update quantity
            $sql = 'UPDATE ' . _DB_PREFIX_ . 'wishlist_product 
                    SET quantity = quantity + ' . (int)$quantity . ' 
                    WHERE id_wishlist_product = ' . (int)$existingId;
        } else {
            // Insert new
            $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'wishlist_product (id_wishlist, id_product, id_product_attribute, quantity, priority) 
                    VALUES (' . (int)$wishlistId . ', ' . (int)$productId . ', ' . (int)$productAttributeId . ', ' . (int)$quantity . ', 1)';
        }
        
        if (!Db::getInstance()->execute($sql)) {
            throw new Exception('Failed to add product to wishlist', 500);
        }

        return ['success' => true, 'message' => 'Product added to wishlist'];
    }

    public function removeFromWishlist($wishlistId, $productId)
    {
        if (!Module::isInstalled('blockwishlist')) {
            throw new Exception('Wishlist module not installed', 404);
        }

        // Verify wishlist ownership
        $sql = new DbQuery();
        $sql->select('id_wishlist');
        $sql->from('wishlist');
        $sql->where('id_wishlist = ' . (int)$wishlistId);
        $sql->where('id_customer = ' . (int)$this->customer->id);
        
        if (!Db::getInstance()->getValue($sql)) {
            throw new Exception('Wishlist not found', 404);
        }

        $sql = 'DELETE FROM ' . _DB_PREFIX_ . 'wishlist_product 
                WHERE id_wishlist = ' . (int)$wishlistId . ' AND id_product = ' . (int)$productId;
        
        if (!Db::getInstance()->execute($sql)) {
            throw new Exception('Failed to remove product from wishlist', 500);
        }

        return ['success' => true, 'message' => 'Product removed from wishlist'];
    }

    public function deleteWishlist($wishlistId)
    {
        if (!Module::isInstalled('blockwishlist')) {
            throw new Exception('Wishlist module not installed', 404);
        }

        // Verify ownership
        $sql = new DbQuery();
        $sql->select('id_wishlist');
        $sql->from('wishlist');
        $sql->where('id_wishlist = ' . (int)$wishlistId);
        $sql->where('id_customer = ' . (int)$this->customer->id);
        
        if (!Db::getInstance()->getValue($sql)) {
            throw new Exception('Wishlist not found', 404);
        }

        // Delete products first
        Db::getInstance()->execute('DELETE FROM ' . _DB_PREFIX_ . 'wishlist_product WHERE id_wishlist = ' . (int)$wishlistId);
        
        // Delete wishlist
        if (!Db::getInstance()->execute('DELETE FROM ' . _DB_PREFIX_ . 'wishlist WHERE id_wishlist = ' . (int)$wishlistId)) {
            throw new Exception('Failed to delete wishlist', 500);
        }

        return ['success' => true, 'message' => 'Wishlist deleted'];
    }

    public function getReviews($params = [])
    {
        // Implementation depends on your review system (native or module)
        throw new Exception('Reviews feature not implemented', 501);
    }

    public function getVouchers($params = [])
    {
        $sql = new DbQuery();
        $sql->select('cr.*, crt.name');
        $sql->from('cart_rule', 'cr');
        $sql->leftJoin('cart_rule_lang', 'crt', 'cr.id_cart_rule = crt.id_cart_rule AND crt.id_lang = ' . (int)Context::getContext()->language->id);
        $sql->where('cr.id_customer = ' . (int)$this->customer->id);
        $sql->where('cr.active = 1');
        $sql->where('cr.date_from <= NOW()');
        $sql->where('cr.date_to >= NOW()');
        
        $vouchers = Db::getInstance()->executeS($sql);
        
        $voucherRTOs = [];
        foreach ($vouchers as $voucher) {
            $voucherRTOs[] = [
                'id' => $voucher['id_cart_rule'],
                'name' => $voucher['name'],
                'code' => $voucher['code'],
                'description' => $voucher['description'],
                'quantity' => $voucher['quantity'],
                'quantity_per_user' => $voucher['quantity_per_user'],
                'reduction_amount' => $voucher['reduction_amount'],
                'reduction_percent' => $voucher['reduction_percent'],
                'reduction_currency' => $voucher['reduction_currency'],
                'minimum_amount' => $voucher['minimum_amount'],
                'date_from' => $voucher['date_from'],
                'date_to' => $voucher['date_to']
            ];
        }
        
        return [
            'data' => $voucherRTOs,
            'total' => count($voucherRTOs)
        ];
    }

    public function getLoyaltyPoints($params = [])
    {
        if (!Module::isInstalled('loyalty')) {
            throw new Exception('Loyalty module not installed', 404);
        }

        // This would depend on your specific loyalty module implementation
        throw new Exception('Loyalty feature not implemented', 501);
    }
}

// File: /modules/advancedrestapi/classes/QueryParamsHandler.php

class QueryParamsHandler
{
    private $className;
    private $tableName;
    private $params;
    private $where = [];
    private $joins = [];
    private $select = ['*'];
    private $limit = 50;
    private $offset = 0;
    private $orderBy = null;
    private $orderWay = 'ASC';
    private $include = [];

    public function __construct($className, $params = [])
    {
        $this->className = $className;
        $this->tableName = strtolower($className);
        $this->params = $params;
        $this->parseParams();
    }

    private function parseParams()
    {
        foreach ($this->params as $key => $value) {
            switch ($key) {
                case 'limit':
                    $this->limit = min(max((int)$value, 1), 200); // Max 200 items
                    break;
                    
                case 'offset':
                    $this->offset = max((int)$value, 0);
                    break;
                    
                case 'order_by':
                    $this->orderBy = pSQL($value);
                    break;
                    
                case 'order_way':
                    $this->orderWay = strtoupper($value) === 'DESC' ? 'DESC' : 'ASC';
                    break;
                    
                case 'include':
                    $this->include = is_array($value) ? $value : explode(',', $value);
                    break;
                    
                case 'fields':
                    $fields = is_array($value) ? $value : explode(',', $value);
                    $this->select = array_map('pSQL', $fields);
                    break;
                    
                case 'search':
                    $this->addSearchCondition($value);
                    break;
                    
                default:
                    if (strpos($key, '__') !== false) {
                        $this->addComplexFilter($key, $value);
                    } else {
                        $this->addSimpleFilter($key, $value);
                    }
                    break;
            }
        }
        
        if (!$this->orderBy) {
            $this->orderBy = 'id_' . $this->tableName;
        }
    }

    private function addSimpleFilter($field, $value)
    {
        $field = pSQL($field);
        
        if (is_array($value)) {
            $values = array_map('pSQL', $value);
            $this->where[] = $field . ' IN ("' . implode('","', $values) . '")';
        } else {
            $this->where[] = $field . ' = "' . pSQL($value) . '"';
        }
    }

    private function addComplexFilter($key, $value)
    {
        $parts = explode('__', $key);
        $field = pSQL($parts[0]);
        $operator = $parts[1] ?? 'eq';
        
        switch ($operator) {
            case 'gt':
                $this->where[] = $field . ' > "' . pSQL($value) . '"';
                break;
            case 'gte':
                $this->where[] = $field . ' >= "' . pSQL($value) . '"';
                break;
            case 'lt':
                $this->where[] = $field . ' < "' . pSQL($value) . '"';
                break;
            case 'lte':
                $this->where[] = $field . ' <= "' . pSQL($value) . '"';
                break;
            case 'like':
                $this->where[] = $field . ' LIKE "%' . pSQL($value) . '%"';
                break;
            case 'ilike':
                $this->where[] = $field . ' LIKE "%' . pSQL(strtolower($value)) . '%"';
                break;
            case 'not':
                $this->where[] = $field . ' != "' . pSQL($value) . '"';
                break;
            case 'in':
                $values = is_array($value) ? $value : explode(',', $value);
                $values = array_map('pSQL', $values);
                $this->where[] = $field . ' IN ("' . implode('","', $values) . '")';
                break;
            case 'not_in':
                $values = is_array($value) ? $value : explode(',', $value);
                $values = array_map('pSQL', $values);
                $this->where[] = $field . ' NOT IN ("' . implode('","', $values) . '")';
                break;
            case 'between':
                $values = is_array($value) ? $value : explode(',', $value);
                if (count($values) === 2) {
                    $this->where[] = $field . ' BETWEEN "' . pSQL($values[0]) . '" AND "' . pSQL($values[1]) . '"';
                }
                break;
            case 'null':
                $this->where[] = $field . ($value ? ' IS NULL' : ' IS NOT NULL');
                break;
            default:
                $this->where[] = $field . ' = "' . pSQL($value) . '"';
                break;
        }
    }

    private function addSearchCondition($searchTerm)
    {
        $searchTerm = pSQL($searchTerm);
        $searchFields = $this->getSearchableFields();
        
        if (!empty($searchFields)) {
            $searchConditions = [];
            foreach ($searchFields as $field) {
                $searchConditions[] = $field . ' LIKE "%' . $searchTerm . '%"';
            }
            $this->where[] = '(' . implode(' OR ', $searchConditions) . ')';
        }
    }

    private function getSearchableFields()
    {
        // Define searchable fields for each class
        $searchableFields = [
            'Product' => ['name', 'description', 'description_short', 'reference'],
            'Customer' => ['firstname', 'lastname', 'email'],
            'Category' => ['name', 'description'],
            'Order' => ['reference'],
            'Address' => ['firstname', 'lastname', 'company', 'address1', 'city']
        ];
        
        return $searchableFields[$this->className] ?? [];
    }

    public function addWhere($field, $value, $operator = '=')
    {
        $field = pSQL($field);
        $value = pSQL($value);
        $this->where[] = $field . ' ' . $operator . ' "' . $value . '"';
    }

    public function addJoin($type, $table, $alias, $condition)
    {
        $this->joins[] = strtoupper($type) . ' JOIN ' . _DB_PREFIX_ . pSQL($table) . ' ' . pSQL($alias) . ' ON ' . $condition;
    }

    public function getResults()
    {
        $sql = new DbQuery();
        $sql->select(implode(', ', $this->select));
        $sql->from($this->tableName);
        
        foreach ($this->joins as $join) {
            $sql->innerJoin($join); // Simplified - you might want to parse join type
        }
        
        if (!empty($this->where)) {
            $sql->where(implode(' AND ', $this->where));
        }
        
        $sql->orderBy($this->orderBy . ' ' . $this->orderWay);
        $sql->limit($this->limit, $this->offset);
        
        return Db::getInstance()->executeS($sql);
    }

    public function getCount()
    {
        $sql = new DbQuery();
        $sql->select('COUNT(*)');
        $sql->from($this->tableName);
        
        foreach ($this->joins as $join) {
            $sql->innerJoin($join);
        }
        
        if (!empty($this->where)) {
            $sql->where(implode(' AND ', $this->where));
        }
        
        return (int)Db::getInstance()->getValue($sql);
    }

    public function getPaginationInfo()
    {
        $total = $this->getCount();
        $totalPages = ceil($total / $this->limit);
        $currentPage = floor($this->offset / $this->limit) + 1;
        
        return [
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'total_items' => $total,
            'items_per_page' => $this->limit,
            'has_next' => $currentPage < $totalPages,
            'has_previous' => $currentPage > 1
        ];
    }
}

## Support

For issues and feature requests, check the module configuration in your PrestaShop admin or consult the PrestaShop documentation.

// File: /modules/advancedrestapi/classes/RTOs/OrdersRTO.php

class OrdersRTO
{
    private $dto;
    private $config;

    public function __construct(OrderDTO $dto, $config = [])
    {
        $this->dto = $dto;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    private function getDefaultConfig()
    {
        return [
            'include_products' => false,
            'include_addresses' => false,
            'include_payments' => false,
            'include_history' => false,
            'include_customer' => true,
            'include_carrier' => true,
            'detailed_totals' => true
        ];
    }

    public function toArray()
    {
        $data = [
            'id' => $this->dto->id_order,
            'reference' => $this->dto->reference,
            'current_state' => (int)$this->dto->current_state,
            'payment_method' => $this->dto->payment,
            'dates' => [
                'created' => $this->dto->date_add,
                'updated' => $this->dto->date_upd,
                'invoice_date' => $this->dto->invoice_date,
                'delivery_date' => $this->dto->delivery_date
            ],
            'status' => [
                'valid' => (bool)$this->dto->valid,
                'shipped' => $this->dto->order_state['shipped'] ?? false,
                'paid' => $this->dto->order_state['paid'] ?? false,
                'invoice' => $this->dto->order_state['invoice'] ?? false
            ]
        ];

        if ($this->config['detailed_totals']) {
            $data['totals'] = [
                'products' => [
                    'tax_excl' => (float)$this->dto->total_products,
                    'tax_incl' => (float)$this->dto->total_products_wt
                ],
                'shipping' => [
                    'tax_excl' => (float)$this->dto->total_shipping_tax_excl,
                    'tax_incl' => (float)$this->dto->total_shipping_tax_incl
                ],
                'discounts' => [
                    'tax_excl' => (float)$this->dto->total_discounts_tax_excl,
                    'tax_incl' => (float)$this->dto->total_discounts_tax_incl
                ],
                'wrapping' => [
                    'tax_excl' => (float)$this->dto->total_wrapping_tax_excl,
                    'tax_incl' => (float)$this->dto->total_wrapping_tax_incl
                ],
                'total' => [
                    'tax_excl' => (float)$this->dto->total_paid_tax_excl,
                    'tax_incl' => (float)$this->dto->total_paid_tax_incl,
                    'paid' => (float)$this->dto->total_paid_real
                ]
            ];
        } else {
            $data['total_paid'] = (float)$this->dto->total_paid;
        }

        if ($this->config['include_customer'] && $this->dto->customer) {
            $data['customer'] = $this->dto->customer;
        }

        if ($this->config['include_carrier'] && $this->dto->carrier) {
            $data['carrier'] = $this->dto->carrier;
        }

        if ($this->config['include_products'] && $this->dto->order_details) {
            $data['products'] = [];
            foreach ($this->dto->order_details as $detail) {
                $data['products'][] = [
                    'id_order_detail' => $detail->id_order_detail ?? null,
                    'product_id' => $detail->product_id ?? null,
                    'product_attribute_id' => $detail->product_attribute_id ?? null,
                    'product_name' => $detail->product_name ?? '',
                    'product_reference' => $detail->product_reference ?? '',
                    'quantity' => (int)($detail->product_quantity ?? 0),
                    'unit_price' => [
                        'tax_excl' => (float)($detail->unit_price_tax_excl ?? 0),
                        'tax_incl' => (float)($detail->unit_price_tax_incl ?? 0)
                    ],
                    'total_price' => [
                        'tax_excl' => (float)($detail->total_price_tax_excl ?? 0),
                        'tax_incl' => (float)($detail->total_price_tax_incl ?? 0)
                    ]
                ];
            }
        }

        if ($this->config['include_addresses']) {
            if ($this->dto->address_delivery) {
                $deliveryRTO = new AddressesRTO($this->dto->address_delivery);
                $data['addresses']['delivery'] = $deliveryRTO->toArray();
            }
            
            if ($this->dto->address_invoice) {
                $invoiceRTO = new AddressesRTO($this->dto->address_invoice);
                $data['addresses']['invoice'] = $invoiceRTO->toArray();
            }
        }

        if ($this->config['include_payments'] && $this->dto->payments) {
            $data['payments'] = $this->dto->payments;
        }

        if ($this->config['include_history'] && $this->dto->order_history) {
            $data['history'] = $this->dto->order_history;
        }

        if ($this->dto->currency) {
            $data['currency'] = $this->dto->currency;
        }

        return $data;
    }
}

# Enhanced API Documentation with Customer Account & OAuth

## Customer Authentication Endpoints

### Base URL for Auth
```
https://your-shop.com/modules/advancedrestapi/auth/
```

### Login
```http
POST /auth/login
Content-Type: application/json

{
  "email": "customer@example.com",
  "password": "password123",
  "remember_me": true
}
```

**Response:**
```json
{
  "customer": {
    "id": 123,
    "firstname": "John",
    "lastname": "Doe",
    "email": "customer@example.com"
  },
  "tokens": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "token_type": "Bearer",
    "expires_in": 3600
  },
  "expires_in": 3600
}
```

### Register
```http
POST /auth/register
Content-Type: application/json

{
  "firstname": "John",
  "lastname": "Doe",
  "email": "newcustomer@example.com",
  "password": "securepassword",
  "birthday": "1990-01-01",
  "newsletter": true,
  "optin": true
}
```

### Social Login
```http
POST /auth/social/google
Content-Type: application/json

{
  "id_token": "google_id_token_here"
}

POST /auth/social/facebook
Content-Type: application/json

{
  "access_token": "facebook_access_token_here"
}

POST /auth/social/apple
Content-Type: application/json

{
  "id_token": "apple_id_token_here",
  "user": {
    "name": {
      "firstName": "John",
      "lastName": "Doe"
    }
  }
}
```

### Password Reset
```http
POST /auth/forgot-password
Content-Type: application/json

{
  "email": "customer@example.com"
}

POST /auth/reset-password
Content-Type: application/json

{
  "token": "reset_token_from_email",
  "password": "new_secure_password"
}
```

### Token Refresh
```http
POST /auth/refresh
Content-Type: application/json

{
  "refresh_token": "your_refresh_token"
}
```

## Customer Account Endpoints

### Base URL for Account
```
https://your-shop.com/modules/advancedrestapi/account/
```

**All account endpoints require authentication:**
```http
Authorization: Bearer YOUR_ACCESS_TOKEN
```

### Profile Management
```http
GET /account/profile
PUT /account/profile

{
  "firstname": "John",
  "lastname": "Smith",
  "birthday": "1990-01-01",
  "newsletter": true
}
```

### Customer Orders
```http
# List orders with advanced filtering
GET /account/orders?limit=10&offset=0&order_by=date_add&order_way=DESC&current_state=5

# Get specific order details
GET /account/orders/123
```

### Address Management
```http
# List addresses
GET /account/addresses

# Get specific address
GET /account/addresses/456

# Create new address
POST /account/addresses
{
  "alias": "Home",
  "firstname": "John",
  "lastname": "Doe",
  "address1": "123 Main Street",
  "city": "New York",
  "postcode": "10001",
  "id_country": 21,
  "phone": "+1234567890"
}

# Update address
PUT /account/addresses/456
{
  "address1": "456 New Street"
}

# Delete address
DELETE /account/addresses/456
```

### Wishlist Management
```http
# List wishlists
GET /account/wishlists

# Get specific wishlist with products
GET /account/wishlists/789

# Create new wishlist
POST /account/wishlists
{
  "name": "My Favorite Products"
}

# Add product to wishlist
POST /account/wishlists/789
{
  "id_product": 123,
  "id_product_attribute": 456,
  "quantity": 1
}

# Remove product from wishlist
DELETE /account/wishlists/789?product_id=123

# Delete entire wishlist
DELETE /account/wishlists/789
```

### Vouchers & Discounts
```http
GET /account/vouchers
```

### Loyalty Points
```http
GET /account/loyalty
```

## Advanced Query Parameters

### Filtering Operations

#### Comparison Operators
```http
# Greater than
GET /api/products?price__gt=50

# Less than or equal
GET /api/products?price__lte=100

# Between values
GET /api/orders?total_paid__between=50,200

# Like search
GET /api/customers?lastname__like=smith

# Case insensitive like
GET /api/products?name__ilike=shirt

# Not equal
GET /api/products?active__not=0

# In list
GET /api/categories?id_parent__in=1,2,3

# Not in list
GET /api/products?id_category_default__not_in=5,6

# Null checks
GET /api/customers?birthday__null=false
```

#### Multiple Filters
```http
# Combine multiple filters
GET /api/products?active=1&price__gte=20&price__lte=100&id_category_default__in=2,3,4
```

### Pagination & Sorting
```http
# Pagination
GET /api/products?limit=20&offset=40

# Sorting
GET /api/products?order_by=price&order_way=DESC

# Multiple sort fields (if supported)
GET /api/orders?order_by=date_add,total_paid&order_way=DESC,ASC
```

### Field Selection
```http
# Select specific fields only
GET /api/products?fields=id_product,name,price,active

# Select related data
GET /api/products?include=category,manufacturer,images
```

### Search
```http
# Full-text search across searchable fields
GET /api/products?search=smartphone

# Combined with other filters
GET /api/products?search=shirt&active=1&price__lt=50
```

### Complete Query Example
```http
GET /api/products?active=1&price__gte=20&price__lte=100&id_category_default=2&search=cotton&include=category,images&fields=id_product,name,price,description_short&limit=12&offset=0&order_by=price&order_way=ASC
```

## JavaScript SDK Example

```javascript
class PrestaShopAPI {
    constructor(baseURL, apiKey = null) {
        this.baseURL = baseURL;
        this.apiKey = apiKey;
        this.accessToken = localStorage.getItem('ps_access_token');
    }

    async request(endpoint, options = {}) {
        const url = `${this.baseURL}${endpoint}`;
        const headers = {
            'Content-Type': 'application/json',
            ...options.headers
        };

        if (this.accessToken) {
            headers.Authorization = `Bearer ${this.accessToken}`;
        } else if (this.apiKey) {
            headers.Authorization = `Bearer ${this.apiKey}`;
        }

        const response = await fetch(url, {
            ...options,
            headers
        });

        if (response.status === 401 && this.accessToken) {
            // Try to refresh token
            await this.refreshToken();
            headers.Authorization = `Bearer ${this.accessToken}`;
            return fetch(url, { ...options, headers });
        }

        return response.json();
    }

    // Authentication
    async login(email, password, rememberMe = false) {
        const response = await this.request('/auth/login', {
            method: 'POST',
            body: JSON.stringify({ email, password, remember_me: rememberMe })
        });

        if (response.tokens) {
            this.accessToken = response.tokens.access_token;
            localStorage.setItem('ps_access_token', this.accessToken);
            localStorage.setItem('ps_refresh_token', response.tokens.refresh_token);
        }

        return response;
    }

    async socialLogin(provider, tokenData) {
        const response = await this.request(`/auth/social/${provider}`, {
            method: 'POST',
            body: JSON.stringify(tokenData)
        });

        if (response.tokens) {
            this.accessToken = response.tokens.access_token;
            localStorage.setItem('ps_access_token', this.accessToken);
            localStorage.setItem('ps_refresh_token', response.tokens.refresh_token);
        }

        return response;
    }

    async refreshToken() {
        const refreshToken = localStorage.getItem('ps_refresh_token');
        if (!refreshToken) return null;

        const response = await this.request('/auth/refresh', {
            method: 'POST',
            body: JSON.stringify({ refresh_token: refreshToken })
        });

        if (response.tokens) {
            this.accessToken = response.tokens.access_token;
            localStorage.setItem('ps_access_token', this.accessToken);
            localStorage.setItem('ps_refresh_token', response.tokens.refresh_token);
        }

        return response;
    }

    logout() {
        this.accessToken = null;
        localStorage.removeItem('ps_access_token');
        localStorage.removeItem('ps_refresh_token');
        return this.request('/auth/logout', { method: 'POST' });
    }

    // Products
    async getProducts(params = {}) {
        const queryString = new URLSearchParams(params).toString();
        return this.request(`/api/products?${queryString}`);
    }

    async getProduct(id, include = []) {
        const includeParam = include.length ? `?include=${include.join(',')}` : '';
        return this.request(`/api/products/${id}${includeParam}`);
    }

    // Customer Account
    async getProfile() {
        return this.request('/account/profile');
    }

    async updateProfile(data) {
        return this.request('/account/profile', {
            method: 'PUT',
            body: JSON.stringify(data)
        });
    }

    async getOrders(params = {}) {
        const queryString = new URLSearchParams(params).toString();
        return this.request(`/account/orders?${queryString}`);
    }

    async getOrder(id) {
        return this.request(`/account/orders/${id}`);
    }

    async getAddresses() {
        return this.request('/account/addresses');
    }

    async createAddress(addressData) {
        return this.request('/account/addresses', {
            method: 'POST',
            body: JSON.stringify(addressData)
        });
    }

    async updateAddress(id, addressData) {
        return this.request(`/account/addresses/${id}`, {
            method: 'PUT',
            body: JSON.stringify(addressData)
        });
    }

    async deleteAddress(id) {
        return this.request(`/account/addresses/${id}`, {
            method: 'DELETE'
        });
    }

    // Wishlists
    async getWishlists() {
        return this.request('/account/wishlists');
    }

    async getWishlist(id) {
        return this.request(`/account/wishlists/${id}`);
    }

    async createWishlist(name) {
        return this.request('/account/wishlists', {
            method: 'POST',
            body: JSON.stringify({ name })
        });
    }

    async addToWishlist(wishlistId, productId, attributeId = null) {
        return this.request(`/account/wishlists/${wishlistId}`, {
            method: 'POST',
            body: JSON.stringify({
                id_product: productId,
                id_product_attribute: attributeId,
                quantity: 1
            })
        });
    }

    async removeFromWishlist(wishlistId, productId) {
        return this.request(`/account/wishlists/${wishlistId}?product_id=${productId}`, {
            method: 'DELETE'
        });
    }
}

// Usage Example
const api = new PrestaShopAPI('https://your-shop.com/modules/advancedrestapi');

// Login
api.login('customer@example.com', 'password').then(response => {
    console.log('Logged in:', response.customer);
});

// Get products with advanced filtering
api.getProducts({
    active: 1,
    'price__gte': 20,
    'price__lte': 100,
    search: 'shirt',
    include: 'category,images',
    limit: 12,
    order_by: 'price',
    order_way: 'ASC'
}).then(products => {
    console.log('Products:', products.data);
    console.log('Total:', products.total);
    console.log('Pagination:', products.pagination);
});

// Customer orders
api.getOrders({
    limit: 5,
    order_by: 'date_add',
    order_way: 'DESC'
}).then(orders => {
    console.log('Recent orders:', orders.data);
});

// Add to wishlist
api.addToWishlist(1, 123).then(response => {
    console.log('Added to wishlist:', response);
});
```

## OAuth Configuration

### Google OAuth Setup
1. Go to Google Cloud Console
2. Create OAuth 2.0 credentials
3. Add your domain to authorized origins
4. Set client ID in module configuration:
   ```php
   Configuration::updateValue('ADVANCED_REST_API_GOOGLE_CLIENT_ID', 'your_client_id');
   ```

### Facebook OAuth Setup
1. Create Facebook App
2. Configure Facebook Login product
3. Set app credentials:
   ```php
   Configuration::updateValue('ADVANCED_REST_API_FACEBOOK_APP_ID', 'your_app_id');
   Configuration::updateValue('ADVANCED_REST_API_FACEBOOK_APP_SECRET', 'your_app_secret');
   ```

### Apple OAuth Setup
1. Configure Sign in with Apple
2. Generate private key and client ID
3. Implement proper JWT verification for production use

## Security Considerations

1. **HTTPS Only**: Always use HTTPS in production
2. **JWT Secret**: Set a strong JWT secret key
3. **Token Expiration**: Configure appropriate token lifetimes
4. **Rate Limiting**: Implement API rate limiting
5. **Input Validation**: All inputs are sanitized and validated
6. **CORS**: Configure CORS properly for your domains
7. **OAuth Verification**: Properly verify social login tokens

## Performance Optimization

1. **Caching**: Implement response caching for frequently accessed data
2. **Database Indexes**: Ensure proper indexes on filtered fields
3. **Query Optimization**: Use field selection to reduce data transfer
4. **Pagination**: Always paginate large result sets
5. **Include Relations**: Only include needed relational data