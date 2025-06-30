<?php

use MyRestApi\Services\JwtService;

class MyRestApiTokenModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $guestAllowed = true; // Allow guests to access this controller for token generation

    public function init()
    {
        parent::init();
        // CORS headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    public function postProcess()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->ajaxRenderJson(['error' => 'Method Not Allowed'], 405);
            exit;
        }

        $requestBody = json_decode(Tools::file_get_contents('php://input'), true);
        $apiKey = $requestBody['api_key'] ?? null;

        if (!$apiKey) {
            $this->ajaxRenderJson(['error' => 'API key is required.'], 400);
            exit;
        }

        $configuredApiKey = Configuration::get('MYRESTAPI_API_KEY');

        if (!$configuredApiKey || !hash_equals($configuredApiKey, $apiKey)) {
            $this->ajaxRenderJson(['error' => 'Invalid API key.'], 401);
            exit;
        }

        try {
            $jwtService = new JwtService();
            // Use the API key itself (or a hash/part of it) as the 'uid' for the token,
            // or a generic identifier if preferred. For simplicity, using a generic ID.
            $userId = 'api_user_'.md5($apiKey);
            $token = $jwtService->generateToken($userId, ['roles' => ['api_access']]);

            $this->ajaxRenderJson(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 3600]);
            exit;

        } catch (\Exception $e) {
            // Log error: error_log('JWT Generation Error: ' . $e->getMessage());
            $this->ajaxRenderJson(['error' => 'Could not generate token: ' . $e->getMessage()], 500);
            exit;
        }
    }

    /**
     * Renders a JSON response and exits.
     *
     * @param mixed $data The data to encode as JSON.
     * @param int $statusCode HTTP status code.
     */
    protected function ajaxRenderJson($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        // For PrestaShop 1.7+ `prestashop.response.json` is preferred if available
        if (method_exists($this, 'jsonResponse')) {
             $this->jsonResponse($data); // PS 8.x
        } elseif (method_exists('AjaxController', 'ajaxRender')) {
            // This is a bit of a hack for older PS or if context is tricky
            $controller = new FrontController(); // Or AdminController if in BO context
            $controller->ajax = true;
            // For PS 1.7 style
            if(is_callable('ControllerCore::ajaxRender')){
                 $controller->ajaxRender(json_encode($data));
            } else {
                 // For PS 1.6 style
                 echo json_encode($data);
            }
        } else {
            // Fallback for very basic scenarios or testing
            echo json_encode($data);
        }
        exit;
    }

    /**
     * Override display method to prevent template rendering for API controllers
     */
    public function display()
    {
        // This controller should not render any template
    }
}
