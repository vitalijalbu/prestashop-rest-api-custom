<?php
namespace MyRestApi\Services;

use MyRestApi\Core\AbstractResourceService;
use MyRestApi\Dto\ProductDTO; // Will be used by AbstractResourceService helpers
use MyRestApi\Rto\ProductRTO; // Will be used by AbstractResourceService helpers
use Product;
use DbQuery;
use Validate;
use StockAvailable;
use PrestaShopException;
use Manufacturer; // For filtering/linking
use Category;   // For filtering/linking
use Tools;

class ProductService extends AbstractResourceService
{
    protected $resourceClass = Product::class;
    // DTO and RTO class names are strings because they might not be loaded yet when service is constructed.
    protected $dtoClass = ProductDTO::class;
    protected $rtoClass = ProductRTO::class;

    public function getList(array $filters, array $sort, int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;
        $orderBy = $sort['orderBy'] ?? 'id_product';
        $orderWay = $sort['orderWay'] ?? 'ASC';

        $validOrderBys = ['id_product', 'name', 'price', 'date_add', 'date_upd', 'position', 'quantity', 'reference'];
        if (!in_array(strtolower($orderBy), $validOrderBys)) $orderBy = 'id_product';
        if (!in_array(strtoupper($orderWay), ['ASC', 'DESC'])) $orderWay = 'ASC';

        $query = new DbQuery();
        $query->select('p.id_product');
        $query->from('product', 'p');
        $query->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . (int)$this->id_shop);
        $query->leftJoin('product_lang', 'pl', 'p.id_product = pl.id_product AND pl.id_lang = ' . (int)$this->id_lang . ' AND pl.id_shop = ' . (int)$this->id_shop);
        $query->leftJoin('stock_available', 'sa', 'p.id_product = sa.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . (int)$this->id_shop . ' AND sa.id_shop_group = 0');

        $whereClauses = [];
        if (isset($filters['active']) && in_array($filters['active'], ['0', '1'])) {
            $whereClauses[] = 'ps.active = ' . (int)$filters['active'];
        } else {
             $whereClauses[] = 'ps.active = 1'; // Default to active if not specified
        }

        if (!empty($filters['name'])) {
            $whereClauses[] = 'pl.name LIKE "%' . pSQL($filters['name']) . '%"';
        }
        if (!empty($filters['reference'])) {
            $whereClauses[] = 'p.reference LIKE "%' . pSQL($filters['reference']) . '%"';
        }
        if (isset($filters['id_category_default']) && Validate::isUnsignedId($filters['id_category_default'])) {
            $whereClauses[] = 'ps.id_category_default = ' . (int)$filters['id_category_default']; // product_shop table for category default per shop
        }
         if (isset($filters['id_category']) && Validate::isUnsignedId($filters['id_category'])) {
            $query->innerJoin('category_product', 'cp', 'p.id_product = cp.id_product AND cp.id_category = ' . (int)$filters['id_category']);
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

        if (!empty($whereClauses)) {
            $query->where(implode(' AND ', $whereClauses));
        }

        $query->groupBy('p.id_product');

        $countQuery = clone $query;
        $countQuery->select('COUNT(DISTINCT p.id_product)');
        $totalItems = (int)\Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($countQuery);

        if ($totalItems === 0) {
            return ['data' => [], 'total' => 0];
        }

        $orderByMap = [
            'id_product' => 'p.id_product',
            'name' => 'pl.name',
            'price' => 'ps.price',
            'date_add' => 'p.date_add',
            'date_upd' => 'p.date_upd',
            'position' => 'ps.position', // This is usually category specific position. Default might be 0 or id.
            'quantity' => 'sa.quantity',
            'reference' => 'p.reference',
        ];
        $orderByColumn = $orderByMap[strtolower($orderBy)] ?? 'p.id_product';
        $query->orderBy($orderByColumn . ' ' . $orderWay);
        $query->limit($limit, $offset);

        $results = \Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);
        return ['data' => $results ? array_column($results, 'id_product') : [], 'total' => $totalItems];
    }

    public function getById(int $id)
    {
        $product = $this->getResourceInstance($id);
        if (!Validate::isLoadedObject($product) || !$product->active && !$this->userHasAdminRights()) {
             // Consider if active check should be here or in controller based on user role
            return null;
        }
        return $product;
    }

    public function create(object $dto) // $dto is an instance of ProductDTO
    {
        $validationErrors = $this->validateDto($dto);
        if (!empty($validationErrors)) {
            return ['errors' => $validationErrors];
        }

        $product = $this->getResourceInstance(); // Get new Product instance
        $this->hydrateObjectModelFromDto($dto, $product);

        // Ensure id_shop_list and id_shop_default are set
        if (empty($product->id_shop_list)) {
            $product->id_shop_list = [(int)$this->id_shop];
        }
        if (empty($product->id_shop_default)) {
            $product->id_shop_default = (int)$this->id_shop;
        }
        // Ensure product is associated with at least the default shop from context
        if (empty($product->getWsShops()) && \Shop::isFeatureActive() && \Shop::getContext() == \Shop::CONTEXT_SHOP) {
             $product->id_shop_list = [(int)$this->context->shop->id];
        }


        try {
            if (!$product->add()) {
                return ['errors' => ['Failed to save product. DB error or PrestaShop core validation failed.']];
            }

            // Update stock quantity if provided
            if (isset($dto->quantity)) {
                StockAvailable::setQuantity($product->id, 0, (int)$dto->quantity, $this->id_shop);
            }

            // Associate categories if provided
            if (!empty($dto->categories) && is_array($dto->categories)) {
                $product->updateCategories($dto->categories);
            } elseif (empty($dto->categories) && $product->id_category_default) {
                // If no categories provided, but a default category is set, ensure it's associated
                 $product->updateCategories([$product->id_category_default]);
            }


            return $this->getResourceInstance($product->id); // Return the newly created and loaded product
        } catch (PrestaShopException $e) {
            return ['errors' => [$e->getMessage()]];
        }
    }

    public function update(int $id, object $dto) // $dto is an instance of ProductDTO
    {
        $product = $this->getResourceInstance($id);
        if (!Validate::isLoadedObject($product)) {
            return ['errors' => ['Product not found.']];
        }

        // Set id_product on DTO to ensure context during hydration if DTO uses it
        if (property_exists($dto, 'id_product')) {
            $dto->id_product = $id;
        }

        // Retain existing multilang fields if not provided in DTO for update
        foreach (\Language::getLanguages(false) as $lang) {
            foreach (['name', 'description', 'description_short', 'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords', 'available_now', 'available_later'] as $field) {
                if (property_exists($dto, $field) && is_array($dto->{$field}) && empty($dto->{$field}[$lang['id_lang']])) {
                    if (isset($product->{$field}[$lang['id_lang']])) {
                        $dto->{$field}[$lang['id_lang']] = $product->{$field}[$lang['id_lang']];
                    }
                }
            }
        }

        $validationErrors = $this->validateDto($dto);
        if (!empty($validationErrors)) {
            return ['errors' => $validationErrors];
        }

        $this->hydrateObjectModelFromDto($dto, $product);

        try {
            if (!$product->update()) {
                 return ['errors' => ['Failed to update product. DB error or PrestaShop core validation failed.']];
            }

            if (isset($dto->quantity)) { // Check DTO for explicit quantity update
                StockAvailable::setQuantity($product->id, 0, (int)$dto->quantity, $this->id_shop);
            }

            if (isset($dto->categories)) { // Only update categories if explicitly provided in DTO
                if (is_array($dto->categories)) {
                    $product->updateCategories($dto->categories);
                } elseif (empty($dto->categories)) {
                    $product->updateCategories([]); // Remove all categories
                }
            }

            return $this->getResourceInstance($product->id); // Return the updated and loaded product
        } catch (PrestaShopException $e) {
            return ['errors' => [$e->getMessage()]];
        }
    }

    public function delete(int $id): bool|array
    {
        $product = $this->getResourceInstance($id);
        if (!Validate::isLoadedObject($product)) {
            return ['errors' => ['Product not found.']];
        }

        try {
            if (!$product->delete()) {
                return ['errors' => ['Failed to delete product.']];
            }
            return true;
        } catch (PrestaShopException $e) {
            return ['errors' => [$e->getMessage()]];
        }
    }

    // Example helper, can be used by controller or abstract controller later
    private function userHasAdminRights(): bool
    {
        // This would check JWT claims if they exist and are set by AbstractApiController
        // For now, assume false or a basic check.
        // if ($this->context->employee && $this->context->employee->isLoggedBack()) return true;
        return false;
    }
}
