<?php
namespace MyRestApi\Controllers\Core;

use MyRestApi\Services\JwtService; // For type hinting if needed, though parent has it
use MyRestApi\Core\ResourceServiceInterface;
use ModuleFrontController;
use Tools;
use Validate;
use Context;

abstract class AbstractResourceController extends \MyRestApiAbstractApiControllerCore // Extends the module's root abstract controller
{
    /** @var ResourceServiceInterface $resourceService */
    protected $resourceService;

    /**
     * Name of the parameter used in routes for the resource ID.
     * Example: 'id_product', 'id_category'. Must be defined in child controller.
     * @var string
     */
    protected $resourceIdField = 'id'; // Default, can be overridden

    public function __construct()
    {
        parent::__construct();
        $this->resourceService = $this->getResourceServiceInstance();
        if (!$this->resourceService instanceof ResourceServiceInterface) {
            throw new \LogicException(get_called_class() . ' must initialize a valid $resourceService implementing ResourceServiceInterface.');
        }
    }

    /**
     * Child controllers must implement this to return an instance of their specific service.
     * @return ResourceServiceInterface
     */
    abstract protected function getResourceServiceInstance(): ResourceServiceInterface;

    /**
     * Handles GET requests.
     * URI without ID -> list resources
     * URI with ID -> get single resource
     */
    public function display()
    {
        $id_resource = (int)Tools::getValue($this->resourceIdField);

        if ($id_resource > 0) {
            $this->getResource($id_resource);
        } else {
            $this->listResources();
        }
    }

    protected function listResources()
    {
        // Pagination
        $page = max(1, (int)Tools::getValue('page', 1));
        $limit = max(1, min(100, (int)Tools::getValue('limit', 10)));

        // Sorting
        $orderBy = Tools::strtolower(Tools::getValue('order_by', $this->getDefaultSortField()));
        $orderWay = Tools::strtoupper(Tools::getValue('order_way', 'ASC'));
        $sort = ['orderBy' => $orderBy, 'orderWay' => $orderWay];

        // Filtering (generic filter array)
        $filters = Tools::getValue('filter', []);
        if (!is_array($filters)) $filters = [];

        // Include options for RTO
        $includeOptions = Tools::getValue('include') ? explode(',', Tools::getValue('include')) : [];
        $rtoOptions = $this->getRtoOptions(); // Allow child controllers to pass specific RTO options

        $serviceResult = $this->resourceService->getList($filters, $sort, $page, $limit);
        $resource_ids = $serviceResult['data'];
        $totalItems = $serviceResult['total'];

        if ($totalItems === 0) {
            $this->sendResponse(['data' => [], 'pagination' => $this->getPaginationData(0, $page, $limit)], 200);
            return;
        }

        $rtoClassName = $this->resourceService->getRtoClass();
        $resourceRTOs = [];

        foreach ($resource_ids as $resource_id) {
            // Fetch the full object using the service's getById, which should handle object loading
            $resourceObject = $this->resourceService->getById((int)$resource_id);
            if ($resourceObject && Validate::isLoadedObject($resourceObject)) {
                // Ensure $resourceObject is an object, not an error array
                if (is_array($resourceObject) && isset($resourceObject['errors'])) {
                    // Log error or decide how to handle partial failures in a list
                    continue;
                }
                $rto = new $rtoClassName($resourceObject, $this->context->language->id, $includeOptions, $rtoOptions);
                $resourceRTOs[] = $rto->toArray();
            }
        }

        $this->sendResponse([
            'data' => $resourceRTOs,
            'pagination' => $this->getPaginationData($totalItems, $page, $limit)
        ], 200);
    }

    protected function getResource(int $id_resource)
    {
        $resource = $this->resourceService->getById($id_resource);

        if (!$resource || (is_array($resource) && isset($resource['errors']))) {
            $this->sendResponse(['error' => ucfirst(str_replace('_', ' ', $this->resourceIdField)) . ' not found or access denied.'], 404);
            return;
        }
        if (!is_object($resource) || !Validate::isLoadedObject($resource)) {
             $this->sendResponse(['error' => ucfirst(str_replace('_', ' ', $this->resourceIdField)) . ' not found or access denied.'], 404);
            return;
        }

        $includeOptions = Tools::getValue('include') ? explode(',', Tools::getValue('include')) : [];
        $rtoOptions = $this->getRtoOptions();

        $rtoClassName = $this->resourceService->getRtoClass();
        $rto = new $rtoClassName($resource, $this->context->language->id, $includeOptions, $rtoOptions);
        $this->sendResponse($rto->toArray(), 200);
    }

    public function postProcess() // Create Resource
    {
        if (!$this->jwtPayload) {
            $this->sendResponse(['error' => 'Authentication required for creation.'], 401);
            return;
        }

        $requestData = $this->getRequestBodyAsArray();
        $dtoClassName = $this->resourceService->getDtoClass();

        if (!method_exists($dtoClassName, 'fromArray')) {
             $this->sendResponse(['error' => "DTO class {$dtoClassName} must have a static fromArray method."], 500);
             return;
        }
        $dto = call_user_func([$dtoClassName, 'fromArray'], $requestData);

        $result = $this->resourceService->create($dto);

        if (is_array($result) && isset($result['errors'])) {
            $this->sendResponse(['error' => 'Failed to create resource.', 'messages' => $result['errors']], 400); // 400 for validation errors
            return;
        }
        if (!is_object($result) || !Validate::isLoadedObject($result)) {
             $this->sendResponse(['error' => 'Failed to create resource or return valid object.'], 500);
            return;
        }

        $rtoClassName = $this->resourceService->getRtoClass();
        $rto = new $rtoClassName($result, $this->context->language->id, [], $this->getRtoOptions()); // No includes for create response by default
        $this->sendResponse($rto->toArray(), 201);
    }

    public function processPutRequest() // Update Resource
    {
        if (!$this->jwtPayload) {
            $this->sendResponse(['error' => 'Authentication required for update.'], 401);
            return;
        }

        $id_resource = (int)Tools::getValue($this->resourceIdField);
        if (!$id_resource) {
            $this->sendResponse(['error' => ucfirst(str_replace('_', ' ', $this->resourceIdField)) . ' ID is required for update.'], 400);
            return;
        }

        $requestData = $this->getRequestBodyAsArray();
        $dtoClassName = $this->resourceService->getDtoClass();

        if (!method_exists($dtoClassName, 'fromArray')) {
             $this->sendResponse(['error' => "DTO class {$dtoClassName} must have a static fromArray method."], 500);
             return;
        }
        $dto = call_user_func([$dtoClassName, 'fromArray'], $requestData);

        $result = $this->resourceService->update($id_resource, $dto);

        if (is_array($result) && isset($result['errors'])) {
            $errorMsg = 'Failed to update resource.';
            $statusCode = 400; // Default for validation errors
            if (in_array(ucfirst(str_replace('_', ' ', $this->resourceIdField)) . ' not found.', $result['errors']) ||
                in_array('Product not found.', $result['errors']) || in_array('Category not found.', $result['errors'])) { // Match typical service errors
                $statusCode = 404;
            }
            $this->sendResponse(['error' => $errorMsg, 'messages' => $result['errors']], $statusCode);
            return;
        }
         if (!is_object($result) || !Validate::isLoadedObject($result)) {
             $this->sendResponse(['error' => 'Failed to update resource or return valid object.'], 500);
            return;
        }


        $rtoClassName = $this->resourceService->getRtoClass();
        $rto = new $rtoClassName($result, $this->context->language->id, [], $this->getRtoOptions()); // No includes for update response by default
        $this->sendResponse($rto->toArray(), 200);
    }

    public function processDeleteRequest()
    {
        if (!$this->jwtPayload) {
            $this->sendResponse(['error' => 'Authentication required for deletion.'], 401);
            return;
        }

        $id_resource = (int)Tools::getValue($this->resourceIdField);
        if (!$id_resource) {
            $this->sendResponse(['error' => ucfirst(str_replace('_', ' ', $this->resourceIdField)) . ' ID is required for deletion.'], 400);
            return;
        }

        $result = $this->resourceService->delete($id_resource);

        if (is_array($result) && isset($result['errors'])) {
            $errorMsg = 'Failed to delete resource.';
            $statusCode = 500;
             if (in_array(ucfirst(str_replace('_', ' ', $this->resourceIdField)) . ' not found.', $result['errors']) ||
                in_array('Product not found.', $result['errors']) || in_array('Category not found.', $result['errors'])) {
                $statusCode = 404;
            }
            $this->sendResponse(['error' => $errorMsg, 'messages' => $result['errors']], $statusCode);
            return;
        }
        if ($result !== true) { // Should be true or error array
            $this->sendResponse(['error' => 'Failed to delete resource due to an unknown error.'], 500);
            return;
        }

        $this->sendResponse(null, 204); // No Content
    }

    protected function getPaginationData(int $totalItems, int $currentPage, int $itemsPerPage): array
    {
        if ($itemsPerPage <= 0) $itemsPerPage = 1; // Avoid division by zero
        return [
            'total_items' => $totalItems,
            'current_page' => $currentPage,
            'items_per_page' => $itemsPerPage,
            'total_pages' => ceil($totalItems / $itemsPerPage)
        ];
    }

    /**
     * Default sort field for the resource. Can be overridden by child controllers.
     * @return string
     */
    protected function getDefaultSortField(): string
    {
        return 'id_' . Tools::strtolower(str_replace('Controller', '', (new \ReflectionClass($this))->getShortName()));
    }

    /**
     * Allows child controllers to pass specific options to RTOs if needed.
     * For example, product_limit for CategoryRTO.
     * @return array
     */
    protected function getRtoOptions(): array
    {
        return [];
    }


    public function run()
    {
        // Authentication is handled by MyRestApiAbstractApiControllerCore::init()
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
                $this->sendResponse(['error' => 'Method Not Supported for this resource.'], 405);
                break;
        }
    }
}
