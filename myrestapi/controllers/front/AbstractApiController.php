<?php

use MyRestApi\Services\JwtService;

abstract class MyRestApiAbstractApiControllerCore extends ModuleFrontController
{
    public $ssl = true;
    protected $responseData = [];
    protected $statusCode = 200;
    protected $jwtPayload = null;

    public function init()
    {
        parent::init();

        // CORS Headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->sendResponse(null, 204); // No Content for OPTIONS
        }

        if (!$this->authenticate()) {
            $this->sendResponse(['error' => 'Unauthorized', 'message' => 'Valid JWT required.'], 401);
        }
    }

    protected function authenticate(): bool
    {
        $tokenString = JwtService::getBearerToken();

        if (!$tokenString) {
            return false;
        }

        try {
            $jwtService = new JwtService();
            $this->jwtPayload = $jwtService->validateToken($tokenString);

            if ($this->jwtPayload === null) {
                return false;
            }
            // Optionally, you could check for specific claims or roles here
            // e.g., if (!in_array('api_access', $this->jwtPayload->claims['roles'] ?? [])) return false;

            return true;
        } catch (\Exception $e) {
            // Log error: error_log('JWT Auth Error: ' . $e->getMessage());
            return false;
        }
    }

    protected function sendResponse($data, $statusCode = null)
    {
        if ($statusCode !== null) {
            http_response_code($statusCode);
        } else {
            http_response_code($this->statusCode);
        }

        if ($data === null && $statusCode === 204) { // No Content
            exit;
        }

        // For PrestaShop 1.7+ `prestashop.response.json` is preferred if available
        if (method_exists($this, 'jsonResponse')) {
             $this->jsonResponse($data); // PS 8.x
        } elseif (method_exists('AjaxController', 'ajaxRender')) {
            $controller = new FrontController();
            $controller->ajax = true;
            if(is_callable('ControllerCore::ajaxRender')){
                 $controller->ajaxRender(json_encode($data));
            } else {
                 echo json_encode($data);
            }
        } else {
            echo json_encode($data);
        }
        exit;
    }

    public function display()
    {
        // API controllers should not render templates
    }

    // Common helper methods for API controllers can be added here
    protected function getRequestBodyAsArray(): array
    {
        $json = Tools::file_get_contents('php://input');
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
