<?php

use MyRestApi\Rto\CategoryRTO;
use MyRestApi\Dto\CategoryDTO;

class MyRestApiCategoriesModuleFrontController extends MyRestApiAbstractApiControllerCore
{
    /**
     * Handles GET requests.
     * /myrestapi/categories -> list categories
     * /myrestapi/categories/{id} -> get single category
     */
    public function display()
    {
        $id_category = (int)Tools::getValue('id_category');

        if ($id_category > 0) {
            $this->getCategory($id_category);
        } else {
            $this->listCategories();
        }
    }

    private function listCategories()
    {
        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;

        // Pagination
        $page = max(1, (int)Tools::getValue('page', 1));
        $limit = max(1, min(100, (int)Tools::getValue('limit', 10)));
        $offset = ($page - 1) * $limit;

        // Filtering
        $filters = Tools::getValue('filter', []);
        $id_parent = $filters['id_parent'] ?? null;
        if ($id_parent === 'root') { // Special keyword for root categories
            $id_parent = Category::getRootCategory($id_lang, $id_shop)->id;
        } elseif ($id_parent !== null) {
            $id_parent = (int)$id_parent;
        }

        $active = isset($filters['active']) ? (bool)$filters['active'] : null; // null means don't filter by active status explicitly, true/false to filter
        $name = $filters['name'] ?? null;

        // Build query
        $query = new DbQuery();
        $query->select('c.id_category');
        $query->from('category', 'c');
        $query->leftJoin('category_lang', 'cl', 'c.id_category = cl.id_category AND cl.id_lang = ' . (int)$id_lang . ' AND cl.id_shop = ' . (int)$id_shop);
        $query->innerJoin('category_shop', 'cs', 'c.id_category = cs.id_category AND cs.id_shop = ' . (int)$id_shop);

        $whereClauses = [];
        if ($id_parent !== null) {
            $whereClauses[] = 'c.id_parent = ' . (int)$id_parent;
        }
        if ($active !== null) {
            $whereClauses[] = 'c.active = ' . (int)$active;
        }
        if ($name) {
            $whereClauses[] = 'cl.name LIKE "%' . pSQL($name) . '%"';
        }
        // By default, only show categories that are not the main root (id_category=1 usually) unless explicitly requested
        if ($id_parent === null && (!isset($filters['include_hidden_root']) || !$filters['include_hidden_root'])) {
             $rootOfRoots = Category::getTopCategory(); // This is usually the hidden "Home" category id=1
             if (Validate::isLoadedObject($rootOfRoots)) {
                $whereClauses[] = 'c.id_category != ' . (int)$rootOfRoots->id;
             }
        }


        if (!empty($whereClauses)) {
            $query->where(implode(' AND ', $whereClauses));
        }

        $query->groupBy('c.id_category'); // Ensure unique categories

        // Get total count
        $countQuery = clone $query;
        $countQuery->select('COUNT(DISTINCT c.id_category)'); // Ensure distinct count
        $totalCategories = (int)Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($countQuery);

        if ($totalCategories === 0) {
            $this->sendResponse(['data' => [], 'pagination' => ['total_items' => 0, 'current_page' => $page, 'items_per_page' => $limit, 'total_pages' => 0]], 200);
            return;
        }

        $query->orderBy('c.level_depth ASC, cs.position ASC'); // Default order
        $query->limit($limit, $offset);

        $category_ids = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);

        if (!$category_ids) {
             $this->sendResponse(['data' => [], 'pagination' => ['total_items' => 0, 'current_page' => $page, 'items_per_page' => $limit, 'total_pages' => 0]], 200);
            return;
        }

        $categoryRTOs = [];
        $includeOptions = Tools::getValue('include') ? explode(',', Tools::getValue('include')) : [];
        $rtoOptions = ['product_limit' => (int)Tools::getValue('product_limit', 5)];


        foreach ($category_ids as $cat_data) {
            $category = new Category($cat_data['id_category'], $id_lang, $id_shop);
            if (Validate::isLoadedObject($category)) {
                $rto = new CategoryRTO($category, $id_lang, $includeOptions, $rtoOptions);
                $categoryRTOs[] = $rto->toArray();
            }
        }

        $this->sendResponse([
            'data' => $categoryRTOs,
            'pagination' => [
                'total_items' => $totalCategories,
                'current_page' => $page,
                'items_per_page' => $limit,
                'total_pages' => ceil($totalCategories / $limit)
            ]
        ], 200);
    }

    private function getCategory($id_category)
    {
        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;
        $category = new Category($id_category, $id_lang, $id_shop);

        if (!Validate::isLoadedObject($category) || !$category->active && !$this->userHasAdminRights()) { // Basic check, admin rights could allow viewing inactive
            $this->sendResponse(['error' => 'Category not found or not active.'], 404);
            return;
        }

        $includeOptions = Tools::getValue('include') ? explode(',', Tools::getValue('include')) : [];
        $rtoOptions = ['product_limit' => (int)Tools::getValue('product_limit', 10)];
        $rto = new CategoryRTO($category, $id_lang, $includeOptions, $rtoOptions);
        $this->sendResponse($rto->toArray(), 200);
    }

    // Helper to check if current JWT user has admin-like rights (conceptual)
    private function userHasAdminRights(): bool
    {
        // If using a generic API key -> JWT, this might be based on a claim in the JWT.
        // If it's a customer JWT, then false.
        // For now, assume false unless specific role claim is present.
        return $this->jwtPayload && isset($this->jwtPayload->claims['roles']) && in_array('admin', $this->jwtPayload->claims['roles']);
    }


    public function postProcess() // Create
    {
        if (!$this->jwtPayload) { // Ensure JWT was processed and is valid for CUD
            $this->sendResponse(['error' => 'Authentication required for this action.'], 401);
            return;
        }

        $requestData = $this->getRequestBodyAsArray();
        $categoryDTO = CategoryDTO::fromArray($requestData);

        $validationErrors = $categoryDTO->validate();
        if (!empty($validationErrors)) {
            $this->sendResponse(['error' => 'Validation failed', 'messages' => $validationErrors], 400);
            return;
        }

        $category = new Category();
        $categoryDTO->hydrateCategory($category);

        // Set default values if not provided
        $category->id_shop_list = $category->id_shop_list ?? [(int)$this->context->shop->id];
        if (empty($category->id_parent)) { // Default to Home category if no parent specified
            $category->id_parent = Configuration::get('PS_HOME_CATEGORY');
        }


        try {
            if (!$category->add()) {
                $this->sendResponse(['error' => 'Failed to create category.'], 500);
                return;
            }

            // Handle image upload if present in DTO
            if (!empty($categoryDTO->image_data['base64_content'])) {
                $this->handleImageUpload($category, $categoryDTO->image_data);
            }


            $newCategory = new Category($category->id, $this->context->language->id, $this->context->shop->id);
            $rto = new CategoryRTO($newCategory, $this->context->language->id);
            $this->sendResponse($rto->toArray(), 201);

        } catch (PrestaShopException $e) {
            $this->sendResponse(['error' => 'Failed to create category.', 'message' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => 'An unexpected error occurred.', 'message' => $e->getMessage()], 500);
        }
    }

    public function processPutRequest() // Update
    {
        if (!$this->jwtPayload) {
            $this->sendResponse(['error' => 'Authentication required for this action.'], 401);
            return;
        }

        $id_category = (int)Tools::getValue('id_category');
        if (!$id_category) {
            $this->sendResponse(['error' => 'Category ID is required for update.'], 400);
            return;
        }

        $category = new Category($id_category, $this->context->language->id, $this->context->shop->id);
        if (!Validate::isLoadedObject($category)) {
            $this->sendResponse(['error' => 'Category not found.'], 404);
            return;
        }

        $requestData = $this->getRequestBodyAsArray();
        $categoryDTO = CategoryDTO::fromArray($requestData);
        $categoryDTO->id_category = $id_category;

        // Retain existing multilang fields if not provided in DTO for update
        foreach (Language::getLanguages(false) as $lang) {
            foreach (['name', 'description', 'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords', 'image_legend'] as $field) {
                if (empty($categoryDTO->{$field}[$lang['id_lang']])) { // If DTO value for this lang is empty
                    // Keep existing value from $category object
                     $categoryDTO->{$field}[$lang['id_lang']] = $category->{$field}[$lang['id_lang']] ?? '';
                }
            }
        }

        $validationErrors = $categoryDTO->validate();
        if (!empty($validationErrors)) {
            $this->sendResponse(['error' => 'Validation failed', 'messages' => $validationErrors], 400);
            return;
        }

        $categoryDTO->hydrateCategory($category);

        try {
            if (!$category->update()) {
                $this->sendResponse(['error' => 'Failed to update category.'], 500);
                return;
            }

             // Handle image upload if present in DTO
            if (!empty($categoryDTO->image_data['base64_content'])) {
                $this->handleImageUpload($category, $categoryDTO->image_data);
            } elseif (isset($requestData['delete_image']) && $requestData['delete_image'] == true) {
                 $category->deleteImage(true); // true to force regeneration of thumbnails
            }


            $updatedCategory = new Category($category->id, $this->context->language->id, $this->context->shop->id);
            $rto = new CategoryRTO($updatedCategory, $this->context->language->id);
            $this->sendResponse($rto->toArray(), 200);

        } catch (PrestaShopException $e) {
            $this->sendResponse(['error' => 'Failed to update category.', 'message' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => 'An unexpected error occurred during update.', 'message' => $e->getMessage()], 500);
        }
    }

    public function processDeleteRequest()
    {
        if (!$this->jwtPayload) {
            $this->sendResponse(['error' => 'Authentication required for this action.'], 401);
            return;
        }

        $id_category = (int)Tools::getValue('id_category');
        if (!$id_category) {
            $this->sendResponse(['error' => 'Category ID is required for deletion.'], 400);
            return;
        }

        // Prevent deletion of root categories
        if ($id_category == Configuration::get('PS_ROOT_CATEGORY') || $id_category == Configuration::get('PS_HOME_CATEGORY')) {
            $this->sendResponse(['error' => 'Cannot delete root or home category.'], 403);
            return;
        }

        $category = new Category($id_category);
        if (!Validate::isLoadedObject($category)) {
            $this->sendResponse(['error' => 'Category not found.'], 404);
            return;
        }

        try {
            if (!$category->delete()) {
                $this->sendResponse(['error' => 'Failed to delete category. It might have subcategories or products.'], 500);
                return;
            }
            $this->sendResponse(null, 204); // No Content

        } catch (PrestaShopException $e) {
            $this->sendResponse(['error' => 'Failed to delete category.', 'message' => $e->getMessage()], 500);
        }
    }

    private function handleImageUpload(Category $category, array $imageData)
    {
        $decodedImage = base64_decode($imageData['base64_content']);
        if ($decodedImage === false) {
            throw new \Exception('Invalid base64 image content.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'cat_img_');
        file_put_contents($tempPath, $decodedImage);

        // Validate image mime type (simple check based on extension from filename)
        $extension = strtolower(pathinfo($imageData['filename'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array($extension, $allowedExtensions)) {
            unlink($tempPath);
            throw new \Exception('Invalid image type: ' . $extension . '. Only jpg, jpeg, png, gif allowed.');
        }

        // Construct a pseudo $_FILES entry for PrestaShop's image handling if necessary
        // Or use $category->updateImage() or similar if available and simpler.
        // PrestaShop's Category class does not have a simple updateImage method like Product.
        // We need to manually delete old image and copy new one.

        $category->deleteImage(true); // Delete existing images, true to regenerate no-image

        $origPath = _PS_CAT_IMG_DIR_ . (int)$category->id . '.' . $extension;

        if (ImageManager::resize($tempPath, $origPath)) {
            // If ImageManager::resize is successful, it means the main image is there.
            // Thumbnails are usually generated on the fly or by specific methods.
            // PrestaShop may automatically try to generate thumbnails when displaying them if missing.
            // Forcing regeneration can be done by deleting all thumb files.
            $imagesTypes = ImageType::getImagesTypes('categories');
            foreach ($imagesTypes as $imageType) {
                $thumbPath = _PS_CAT_IMG_DIR_ . (int)$category->id . '-' . stripslashes($imageType['name']) . '.' . $extension;
                if (file_exists($thumbPath)) {
                    unlink($thumbPath);
                }
            }
            $category->id_image = (int)$category->id; // Or more robustly, re-fetch image ID if PrestaShop creates a new one.
                                                  // In this manual copy, the ID of image is category ID.
            $category->update();
        } else {
            unlink($tempPath);
            throw new \Exception('Failed to process and save category image.');
        }
        unlink($tempPath);
    }


    public function run()
    {
        // Auth is handled by AbstractApiController::init()
        $method = $_SERVER['REQUEST_METHOD'];
        switch ($method) {
            case 'PUT':
                $this->processPutRequest();
                break;
            case 'DELETE':
                $this->processDeleteRequest();
                break;
            case 'POST':
                parent::run(); // Calls postProcess()
                break;
            case 'GET':
                parent::run(); // Calls display()
                break;
            default:
                $this->sendResponse(['error' => 'Method Not Supported for categories.'], 405);
                break;
        }
    }
}
