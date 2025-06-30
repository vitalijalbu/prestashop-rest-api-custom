<?php

use MyRestApi\Controllers\Core\AbstractResourceController;
use MyRestApi\Services\ProductService;
use MyRestApi\Core\ResourceServiceInterface; // For type hinting

class MyRestApiProductsModuleFrontController extends AbstractResourceController
{
    protected $resourceIdField = 'id_product';

    protected function getResourceServiceInstance(): ResourceServiceInterface
    {
        return new ProductService();
    }

    // Optional: Override methods from AbstractResourceController if Product-specific
    // logic is needed that cannot be handled generically by the service or abstract controller.
    // For example, if products have a very unique filtering parameter not common to other resources.

    // protected function listResources()
    // {
    //    // Custom logic before or after calling parent::listResources()
    //    parent::listResources();
    // }

    protected function getDefaultSortField(): string
    {
        return 'id_product'; // Default sort for products
    }

    // No need to override display(), postProcess(), processPutRequest(), processDeleteRequest(), or run()
    // as the AbstractResourceController and ProductService handle these generically.
}
