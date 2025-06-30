<?php

use MyRestApi\Rto\CmsPageRTO;
use MyRestApi\Rto\CmsCategoryRTO;

class MyRestApiCmsModuleFrontController extends MyRestApiAbstractApiControllerCore
{
    /**
     * Override authenticate to allow public access for CMS GET requests.
     * CUD operations would still require auth if implemented.
     */
    protected function authenticate(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Try to authenticate if token is present, but don't fail if not.
            // The $this->jwtPayload will be set if token is valid.
            $tokenString = MyRestApi\Services\JwtService::getBearerToken();
            if ($tokenString) {
                try {
                    $jwtService = new MyRestApi\Services\JwtService();
                    $this->jwtPayload = $jwtService->validateToken($tokenString);
                    // If token is present but invalid, $this->jwtPayload will be null.
                    // We could choose to return false here to enforce valid token if present.
                    // For now, allow proceeding even if present token is invalid for GET.
                } catch (\Exception $e) {
                    $this->jwtPayload = null; // Ensure payload is null on error
                }
            }
            return true; // Allow public access for GET
        }
        return parent::authenticate(); // Enforce JWT for POST, PUT, DELETE etc.
    }

    public function display()
    {
        $requestUri = $_SERVER['REQUEST_URI'];

        // Basic routing based on URL segments
        // e.g. /myrestapi/cms/pages/{id_cms}
        // e.g. /myrestapi/cms/categories/{id_cms_category}
        // We need to parse this or use PrestaShop's routing parameters more effectively.
        // The hookModuleRoutes defines 'id_cms_page' and 'id_cms_category_object'

        $id_cms_page = (int)Tools::getValue('id_cms_page');
        $id_cms_category = (int)Tools::getValue('id_cms_category_object');


        if (strpos($requestUri, '/cms/pages') !== false || strpos($requestUri, '/cms_pages') !== false) {
            if ($id_cms_page > 0) {
                $this->getCmsPage($id_cms_page);
            } else {
                $this->listCmsPages();
            }
        } elseif (strpos($requestUri, '/cms/categories') !== false || strpos($requestUri, '/cms_categories') !== false) {
            if ($id_cms_category > 0) {
                $this->getCmsCategory($id_cms_category);
            } else {
                $this->listCmsCategories();
            }
        } else {
            $this->sendResponse(['error' => 'Invalid CMS endpoint.'], 404);
        }
    }

    private function listCmsPages()
    {
        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;

        $page = max(1, (int)Tools::getValue('page', 1));
        $limit = max(1, min(100, (int)Tools::getValue('limit', 10)));
        $offset = ($page - 1) * $limit;

        $filters = Tools::getValue('filter', []);
        $id_cms_category = isset($filters['id_cms_category']) ? (int)$filters['id_cms_category'] : null;
        $active = isset($filters['active']) ? (bool)$filters['active'] : true; // Default to active pages

        // CMS::getCMSPages requires id_cms_category to be non-null for filtering by category.
        // If $id_cms_category is null, it fetches all pages.
        $pages_data = CMS::getCMSPages($id_lang, $id_cms_category, $active, $id_shop);

        if (!$pages_data) {
             $this->sendResponse(['data' => [], 'pagination' => ['total_items' => 0, 'current_page' => $page, 'items_per_page' => $limit, 'total_pages' => 0]], 200);
            return;
        }

        $totalItems = count($pages_data);
        $paginated_data = array_slice($pages_data, $offset, $limit);

        $cmsPageRTOs = [];
        foreach ($paginated_data as $page_data) {
            $cms = new CMS($page_data['id_cms'], $id_lang, $id_shop);
            if (Validate::isLoadedObject($cms)) {
                $rto = new CmsPageRTO($cms, $id_lang);
                $cmsPageRTOs[] = $rto->toArray();
            }
        }

        $this->sendResponse([
            'data' => $cmsPageRTOs,
            'pagination' => [
                'total_items' => $totalItems,
                'current_page' => $page,
                'items_per_page' => $limit,
                'total_pages' => ceil($totalItems / $limit)
            ]
        ], 200);
    }

    private function getCmsPage($id_cms_page)
    {
        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;
        $cms = new CMS($id_cms_page, $id_lang, $id_shop);

        if (!Validate::isLoadedObject($cms) || !$cms->active) {
            $this->sendResponse(['error' => 'CMS Page not found or not active.'], 404);
            return;
        }

        $rto = new CmsPageRTO($cms, $id_lang);
        $this->sendResponse($rto->toArray(), 200);
    }

    private function listCmsCategories()
    {
        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;

        $page = max(1, (int)Tools::getValue('page', 1));
        $limit = max(1, min(100, (int)Tools::getValue('limit', 10)));
        // $offset = ($page - 1) * $limit; // CMSCategory::getCategories doesn't support direct offset

        $filters = Tools::getValue('filter', []);
        $id_parent = isset($filters['id_parent']) ? (int)$filters['id_parent'] : Configuration::get('PS_ROOT_CATEGORY'); // Default to root
        $active = isset($filters['active']) ? (bool)$filters['active'] : true; // Default to active

        // CMSCategory::getCategories(id_parent, id_lang, active, id_shop, order_by, order_way)
        // It doesn't support pagination directly in one call for total count + slice.
        $categories_data = CMSCategory::getCategories($id_parent, $id_lang, $active, $id_shop);

        if (!$categories_data) {
            $this->sendResponse(['data' => [], 'pagination' => ['total_items' => 0, 'current_page' => $page, 'items_per_page' => $limit, 'total_pages' => 0]], 200);
            return;
        }

        $totalItems = count($categories_data);
        // Manual pagination after fetching all (not ideal for very large sets)
        $paginated_data = array_slice($categories_data, ($page - 1) * $limit, $limit);

        $cmsCategoryRTOs = [];
        $includeOptions = Tools::getValue('include') ? explode(',', Tools::getValue('include')) : [];
        $rtoOptions = ['page_limit' => (int)Tools::getValue('page_limit', 5)];

        foreach ($paginated_data as $cat_data) {
            $cmsCategory = new CMSCategory($cat_data['id_cms_category'], $id_lang, $id_shop);
            if (Validate::isLoadedObject($cmsCategory)) {
                $rto = new CmsCategoryRTO($cmsCategory, $id_lang, $includeOptions, $rtoOptions);
                $cmsCategoryRTOs[] = $rto->toArray();
            }
        }

        $this->sendResponse([
            'data' => $cmsCategoryRTOs,
            'pagination' => [
                'total_items' => $totalItems,
                'current_page' => $page,
                'items_per_page' => $limit,
                'total_pages' => ceil($totalItems / $limit)
            ]
        ], 200);
    }

    private function getCmsCategory($id_cms_category)
    {
        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;
        $cmsCategory = new CMSCategory($id_cms_category, $id_lang, $id_shop);

        if (!Validate::isLoadedObject($cmsCategory) || !$cmsCategory->active) {
            $this->sendResponse(['error' => 'CMS Category not found or not active.'], 404);
            return;
        }

        $includeOptions = Tools::getValue('include') ? explode(',', Tools::getValue('include')) : [];
        $rtoOptions = ['page_limit' => (int)Tools::getValue('page_limit', 10)];
        $rto = new CmsCategoryRTO($cmsCategory, $id_lang, $includeOptions, $rtoOptions);
        $this->sendResponse($rto->toArray(), 200);
    }

    // No POST/PUT/DELETE for CMS in this phase.
    // If they were added, they'd go into postProcess(), processPutRequest(), processDeleteRequest()
    // and parent::authenticate() would be used in init() or authenticate() override removed.

    public function run()
    {
        // For CMS, only GET is supported in this phase.
        // AbstractApiController's init calls parent::init, which calls this run()
        // The custom authenticate() method allows GETs.
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            parent::run(); // Calls display()
        } else {
            // For other methods, enforce stricter auth from parent if CUD operations were defined
            if (parent::authenticate()) { // This will check JWT for non-GET
                 // If CUD methods were here, dispatch them. Since not, method not allowed.
                 $this->sendResponse(['error' => 'Method Not Allowed for CMS resources in this version.'], 405);
            } else {
                // parent::authenticate() would have already sent 401 if JWT was required and failed
                // This path might not be hit if parent::authenticate() calls sendResponse and exits.
            }
        }
    }
}
