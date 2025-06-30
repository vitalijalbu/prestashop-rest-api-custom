<?php

use MyRestApi\Rto\ProductRTO;
use MyRestApi\Dto\ProductDTO;

class MyRestApiProductsModuleFrontController extends MyRestApiAbstractApiControllerCore
{
    public function init()
    {
        // Run parent init AFTER setting $this->product for GET /product/{id} case for example
        // Or handle product loading within specific methods.
        // For now, AbstractApiController's init will handle auth.
        parent::init();
    }

    /**
     * Handles GET requests.
     * /myrestapi/products -> list products
     * /myrestapi/products/{id} -> get single product
     */
    public function display()
    {
        $id_product = (int)Tools::getValue('id_product'); // Get ID from route (defined in myrestapi.php hookModuleRoutes)
                                                       // Note: PrestaShop's dispatcher might make it available directly if rule matches {id}

        if ($id_product > 0) {
            $this->getProduct($id_product);
        } else {
            $this->listProducts();
        }
    }

    private function listProducts()
    {
        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;

        // Pagination parameters
        $page = max(1, (int)Tools::getValue('page', 1));
        $limit = max(1, min(100, (int)Tools::getValue('limit', 10))); // Min 1, Max 100
        $offset = ($page - 1) * $limit;

        // Sorting parameters
        $orderBy = Tools::strtolower(Tools::getValue('order_by', 'id_product'));
        $orderWay = Tools::strtoupper(Tools::getValue('order_way', 'ASC'));
        $validOrderBys = ['id_product', 'name', 'price', 'date_add', 'date_upd', 'position', 'quantity']; // Add more as needed
        $validOrderWays = ['ASC', 'DESC'];

        if (!in_array($orderBy, $validOrderBys)) $orderBy = 'id_product';
        if (!in_array($orderWay, $validOrderWays)) $orderWay = 'ASC';

        // Filtering parameters
        $filters = Tools::getValue('filter', []); // Expect ?filter[name]=Laptop&filter[active]=1

        $query = new DbQuery();
        $query->select('p.id_product');
        $query->from('product', 'p');
        $query->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . (int)$id_shop);
        $query->leftJoin('product_lang', 'pl', 'p.id_product = pl.id_product AND pl.id_lang = ' . (int)$id_lang . ' AND pl.id_shop = ' . (int)$id_shop);
        $query->leftJoin('stock_available', 'sa', 'p.id_product = sa.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . (int)$id_shop);
        // Add more joins if filtering/ordering by related tables (e.g., category, manufacturer)

        // Apply filters
        $whereClauses = ['ps.active = 1']; // Always show active products by default unless specified
        if (isset($filters['active']) && in_array($filters['active'], ['0', '1'])) {
            // Override default if 'active' filter is explicitly passed
            $whereClauses = ['ps.active = ' . (int)$filters['active']];
        } elseif (!isset($filters['active'])) {
            $whereClauses[] = 'ps.active = 1'; // Default to active if no filter specified
        }


        if (!empty($filters['name'])) {
            $whereClauses[] = 'pl.name LIKE "%' . pSQL($filters['name']) . '%"';
        }
        if (!empty($filters['reference'])) {
            $whereClauses[] = 'p.reference LIKE "%' . pSQL($filters['reference']) . '%"';
        }
        if (isset($filters['id_category_default']) && Validate::isUnsignedId($filters['id_category_default'])) {
            $whereClauses[] = 'p.id_category_default = ' . (int)$filters['id_category_default'];
        }
        if (isset($filters['id_manufacturer']) && Validate::isUnsignedId($filters['id_manufacturer'])) {
            $whereClauses[] = 'p.id_manufacturer = ' . (int)$filters['id_manufacturer'];
        }
        if (isset($filters['price_gt']) && is_numeric($filters['price_gt'])) {
            $whereClauses[] = 'ps.price > ' . (float)$filters['price_gt'];
        }
        if (isset($filters['price_lt']) && is_numeric($filters['price_lt'])) {
            $whereClauses[] = 'ps.price < ' . (float)$filters['price_lt'];
        }
        // Add more filters as needed

        if (!empty($whereClauses)) {
            $query->where(implode(' AND ', $whereClauses));
        }

        // Get total count for pagination
        $countQuery = clone $query;
        $countQuery->select('COUNT(p.id_product)');
        $totalProducts = (int)Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($countQuery);

        if ($totalProducts === 0) {
            $this->sendResponse(['data' => [], 'pagination' => [
                'total_items' => 0, 'current_page' => $page, 'items_per_page' => $limit, 'total_pages' => 0
            ]], 200);
            return;
        }

        // Add order and limit for the actual data query
        // Mapping human-readable order_by to actual table columns
        $orderByColumn = 'p.id_product'; // Default
        if ($orderBy === 'name') $orderByColumn = 'pl.name';
        elseif ($orderBy === 'price') $orderByColumn = 'ps.price';
        elseif ($orderBy === 'date_add') $orderByColumn = 'p.date_add';
        elseif ($orderBy === 'date_upd') $orderByColumn = 'p.date_upd';
        elseif ($orderBy === 'position') $orderByColumn = 'ps.position'; // Assuming category context for position, or specific position field
        elseif ($orderBy === 'quantity') $orderByColumn = 'sa.quantity';


        $query->orderBy($orderByColumn . ' ' . $orderWay);
        $query->limit($limit, $offset);

        $product_ids = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);

        if (!$product_ids) {
            $this->sendResponse(['data' => [], 'pagination' => [
                 'total_items' => 0, 'current_page' => $page, 'items_per_page' => $limit, 'total_pages' => 0
            ]], 200);
            return;
        }

        $productRTOs = [];
        $includeOptions = Tools::getValue('include') ? explode(',', Tools::getValue('include')) : [];

        foreach ($product_ids as $product_data) {
            $product = new Product($product_data['id_product'], false, $id_lang, $id_shop, $this->context);
            if (Validate::isLoadedObject($product)) {
                $rto = new ProductRTO($product, $id_lang, $includeOptions);
                $productRTOs[] = $rto->toArray();
            }
        }

        $this->sendResponse([
            'data' => $productRTOs,
            'pagination' => [
                'total_items' => $totalProducts,
                'current_page' => $page,
                'items_per_page' => $limit,
                'total_pages' => ceil($totalProducts / $limit)
            ]
        ], 200);
    }

    private function getProduct($id_product)
    {
        $id_lang = $this->context->language->id;
        $product = new Product($id_product, false, $id_lang);

        if (!Validate::isLoadedObject($product)) {
            $this->sendResponse(['error' => 'Product not found.'], 404);
            return;
        }

        $includeOptions = Tools::getValue('include') ? explode(',', Tools::getValue('include')) : [];
        $rto = new ProductRTO($product, $id_lang, $includeOptions);
        $this->sendResponse($rto->toArray(), 200);
    }

    /**
     * Handles POST requests to create a new product.
     * /myrestapi/products
     */
    public function postProcess()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
             // This check might be redundant if PrestaShop routes strictly to postProcess for POST.
             // However, display() is the default for GET.
            $this->sendResponse(['error' => 'Method Not Allowed for this action. Use GET for retrieving.'], 405);
            return;
        }

        $requestData = $this->getRequestBodyAsArray();
        $productDTO = ProductDTO::fromArray($requestData);

        $validationErrors = $productDTO->validate();
        if (!empty($validationErrors)) {
            $this->sendResponse(['error' => 'Validation failed', 'messages' => $validationErrors], 400);
            return;
        }

        $product = new Product();
        $productDTO->hydrateProduct($product);

        // Default values if not set by DTO and required
        if (empty($product->id_shop_list)) {
            $product->id_shop_list = [(int)$this->context->shop->id];
        }
        if (empty($product->id_shop_default)) {
            $product->id_shop_default = (int)$this->context->shop->id;
        }


        try {
            if (!$product->add()) {
                $this->sendResponse(['error' => 'Failed to create product.', 'details' => $product->validateController()], 500); // validateController might give more info
                return;
            }

            // Update stock quantity if provided
            if (isset($productDTO->quantity)) {
                StockAvailable::setQuantity($product->id, 0, (int)$productDTO->quantity);
            }

            // Associate categories if provided
            if (!empty($productDTO->categories) && is_array($productDTO->categories)) {
                $product->updateCategories($productDTO->categories);
            }


            // Reload product to get all generated fields and then use RTO
            $newProduct = new Product($product->id, false, $this->context->language->id);
            $rto = new ProductRTO($newProduct, $this->context->language->id);
            $this->sendResponse($rto->toArray(), 201);

        } catch (PrestaShopException $e) {
            $this->sendResponse(['error' => 'Failed to create product.', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Handles PUT requests to update an existing product.
     * /myrestapi/products/{id}
     * Note: PrestaShop's FrontController usually doesn't route PUT/DELETE to specific methods like postProcess.
     * We rely on init() to check method and dispatch, or use a custom dispatcher if needed.
     * For simplicity here, we'll assume our AbstractApiController or routing handles method dispatching.
     * We'll add a specific method for PUT.
     */
    public function processPutRequest()
    {
        $id_product = (int)Tools::getValue('id_product');
        if (!$id_product) {
            $this->sendResponse(['error' => 'Product ID is required for update.'], 400);
            return;
        }

        $product = new Product($id_product, false, $this->context->language->id);
        if (!Validate::isLoadedObject($product)) {
            $this->sendResponse(['error' => 'Product not found.'], 404);
            return;
        }

        $requestData = $this->getRequestBodyAsArray();
        $productDTO = ProductDTO::fromArray($requestData);
        $productDTO->id_product = $id_product; // Ensure DTO has the ID for context

        // Retain existing multilang fields if not provided in DTO for update
        foreach (Language::getLanguages(false) as $lang) {
            foreach (['name', 'description', 'description_short', 'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords', 'available_now', 'available_later'] as $field) {
                if (empty($productDTO->{$field}[$lang['id_lang']])) {
                    $productDTO->{$field}[$lang['id_lang']] = $product->{$field}[$lang['id_lang']];
                }
            }
        }


        $validationErrors = $productDTO->validate(); // Validate DTO before applying
        if (!empty($validationErrors)) {
            $this->sendResponse(['error' => 'Validation failed', 'messages' => $validationErrors], 400);
            return;
        }

        $productDTO->hydrateProduct($product);

        try {
            if (!$product->update()) {
                $this->sendResponse(['error' => 'Failed to update product.', 'details' => $product->validateController()], 500);
                return;
            }

            // Update stock quantity if provided in DTO
            if (isset($requestData['quantity'])) { // Check original request data for explicit quantity update
                StockAvailable::setQuantity($product->id, 0, (int)$requestData['quantity']);
            }

            // Update categories if provided
            if (isset($productDTO->categories) && is_array($productDTO->categories)) {
                 $product->updateCategories($productDTO->categories);
            } elseif (isset($productDTO->categories) && empty($productDTO->categories)) {
                 $product->updateCategories([])); // Remove all categories
            }


            $updatedProduct = new Product($product->id, false, $this->context->language->id);
            $includeOptions = Tools::getValue('include') ? explode(',', Tools::getValue('include')) : [];
            $rto = new ProductRTO($updatedProduct, $this->context->language->id, $includeOptions);
            $this->sendResponse($rto->toArray(), 200);

        } catch (PrestaShopException $e) {
            $this->sendResponse(['error' => 'Failed to update product.', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Handles DELETE requests to delete a product.
     * /myrestapi/products/{id}
     */
    public function processDeleteRequest()
    {
        $id_product = (int)Tools::getValue('id_product');
        if (!$id_product) {
            $this->sendResponse(['error' => 'Product ID is required for deletion.'], 400);
            return;
        }

        $product = new Product($id_product);
        if (!Validate::isLoadedObject($product)) {
            $this->sendResponse(['error' => 'Product not found.'], 404);
            return;
        }

        try {
            if (!$product->delete()) {
                $this->sendResponse(['error' => 'Failed to delete product.'], 500);
                return;
            }
            $this->sendResponse(null, 204); // No Content

        } catch (PrestaShopException $e) {
            $this->sendResponse(['error' => 'Failed to delete product.', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Override run() to dispatch to appropriate PUT/DELETE methods based on request method.
     * This is a common way to handle non-GET/POST methods in PrestaShop front controllers.
     */
    public function run()
    {
        // Authentication is already handled in AbstractApiController's init() which calls parent::run() eventually.
        // If init() in AbstractApiController calls parent::init() which calls $this->run(),
        // we need to make sure auth happens before this custom dispatch.
        // The current AbstractApiController::init() calls parent::init() first, then authenticate().
        // This means this custom run() might be called before authentication if not careful.
        // Let's ensure authentication check from parent::init() has happened.
        // If $this->jwtPayload is null and it's not an OPTIONS request, auth failed or wasn't done.

        // The `init()` in `MyRestApiAbstractApiControllerCore` already calls `parent::init()` and then `authenticate()`.
        // If authentication fails, it exits. So, if we reach here, authentication was successful.

        $method = $_SERVER['REQUEST_METHOD'];

        switch ($method) {
            case 'PUT':
                $this->processPutRequest();
                break;
            case 'DELETE':
                $this->processDeleteRequest();
                break;
            case 'POST':
                 // Standard PrestaShop flow: postProcess() is called by parent::run() if POST.
                 // So, we let the parent run handle POST to call postProcess().
                parent::run();
                break;
            case 'GET':
                // Standard PrestaShop flow: display() is called by parent::run() if GET.
                parent::run();
                break;
            default:
                $this->sendResponse(['error' => 'Method Not Supported for this resource.'], 405);
                break;
        }
    }
}
