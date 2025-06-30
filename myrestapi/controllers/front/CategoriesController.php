<?php

use MyRestApi\Controllers\Core\AbstractResourceController;
use MyRestApi\Services\CategoryService;
use MyRestApi\Core\ResourceServiceInterface; // For type hinting
use Tools; // For Tools::getValue in getRtoOptions

class MyRestApiCategoriesModuleFrontController extends AbstractResourceController
{
    protected $resourceIdField = 'id_category';

    /**
     * Override authenticate to allow public access for GET requests for Categories.
     * CUD operations will still require auth via parent class logic in AbstractApiControllerCore.
     */
    protected function authenticate(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Try to authenticate if token is present for potential user-specific data in future,
            // but don't fail if not present for public GET access.
            $tokenString = MyRestApi\Services\JwtService::getBearerToken();
            if ($tokenString) {
                try {
                    $jwtService = new MyRestApi\Services\JwtService();
                    $this->jwtPayload = $jwtService->validateToken($tokenString);
                    // If token is present but invalid, $this->jwtPayload will be null.
                    // The parent AbstractApiControllerCore's init might still deny if strict auth is needed for all GETs.
                    // However, for categories, we typically want public read access.
                } catch (\Exception $e) {
                    $this->jwtPayload = null; // Ensure payload is null on error
                }
            }
            return true; // Allow public access for GET operations on categories
        }
        // For non-GET methods (POST, PUT, DELETE), rely on the authentication logic
        // from MyRestApiAbstractApiControllerCore (the parent of AbstractResourceController)
        return parent::authenticate();
    }


    protected function getResourceServiceInstance(): ResourceServiceInterface
    {
        return new CategoryService();
    }

    protected function getDefaultSortField(): string
    {
        return 'level_depth'; // Default sort for categories often includes level_depth then position
    }

    /**
     * Pass product_limit from query parameters to CategoryRTO.
     */
    protected function getRtoOptions(): array
    {
        $options = parent::getRtoOptions(); // Get any base options

        $productLimit = Tools::getValue('product_limit');
        if ($productLimit !== false && is_numeric($productLimit)) {
            $options['product_limit'] = max(0, (int)$productLimit);
        } else {
            // Set a default if not provided or invalid, e.g. 5 or 10
            $options['product_limit'] = 5;
        }
        return $options;
    }

    // No need to override display(), postProcess(), processPutRequest(), processDeleteRequest(), or run() from AbstractResourceController
    // as they, along with CategoryService, should handle the generic CRUD flow.
    // The JWT handling for GET is now managed by overriding authenticate() here, making it public.
    // CUD operations will still be protected by the JWT check in AbstractApiControllerCore's init->authenticate()
    // before AbstractResourceController's specific CUD methods (postProcess, processPutRequest, etc.) are called.
}
