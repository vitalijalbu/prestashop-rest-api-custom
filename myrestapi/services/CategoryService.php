<?php
namespace MyRestApi\Services;

use MyRestApi\Core\AbstractResourceService;
use MyRestApi\Dto\CategoryDTO;
use MyRestApi\Rto\CategoryRTO;
use Category;
use DbQuery;
use Validate;
use PrestaShopException;
use Configuration;
use ImageManager;
use ImageType;
use Tools;
use Language;

class CategoryService extends AbstractResourceService
{
    protected $resourceClass = Category::class;
    protected $dtoClass = CategoryDTO::class;
    protected $rtoClass = CategoryRTO::class;

    public function getList(array $filters, array $sort, int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;
        $orderBy = $sort['orderBy'] ?? 'level_depth'; // Default sort for categories
        $orderWay = $sort['orderWay'] ?? 'ASC';

        $validOrderBys = ['id_category', 'name', 'position', 'level_depth', 'date_add', 'date_upd'];
        if (!in_array(strtolower($orderBy), $validOrderBys)) $orderBy = 'level_depth';
        if (!in_array(strtoupper($orderWay), ['ASC', 'DESC'])) $orderWay = 'ASC';

        $query = new DbQuery();
        $query->select('c.id_category');
        $query->from('category', 'c');
        $query->leftJoin('category_lang', 'cl', 'c.id_category = cl.id_category AND cl.id_lang = ' . (int)$this->id_lang . ' AND cl.id_shop = ' . (int)$this->id_shop);
        $query->innerJoin('category_shop', 'cs', 'c.id_category = cs.id_category AND cs.id_shop = ' . (int)$this->id_shop);

        $whereClauses = [];
        $id_parent_filter = $filters['id_parent'] ?? null;
        if ($id_parent_filter === 'root') {
             $whereClauses[] = 'c.id_parent = ' . (int)Category::getRootCategory($this->id_lang, $this->context->shop)->id;
        } elseif ($id_parent_filter !== null && Validate::isUnsignedId($id_parent_filter)) {
            $whereClauses[] = 'c.id_parent = ' . (int)$id_parent_filter;
        }

        if (isset($filters['active']) && in_array($filters['active'], ['0', '1'])) {
            $whereClauses[] = 'c.active = ' . (int)$filters['active'];
        } else {
            $whereClauses[] = 'c.active = 1'; // Default to active
        }

        if (!empty($filters['name'])) {
            $whereClauses[] = 'cl.name LIKE "%' . pSQL($filters['name']) . '%"';
        }
        if (isset($filters['is_root_category']) && in_array($filters['is_root_category'], ['0', '1'])) {
            $whereClauses[] = 'c.is_root_category = ' . (int)$filters['is_root_category'];
        }

        // Exclude the main hidden root category by default unless 'id_parent' is specifically asking for it or 'include_hidden_root' is true
        $rootShopCategory = Category::getRootCategory($this->id_lang, $this->context->shop); // The "Home" category for the shop
        $superRootCategory = Category::getTopCategory(); // The absolute root, usually ID 1

        if ($id_parent_filter === null && (!isset($filters['include_hidden_root']) || $filters['include_hidden_root'] != 'true')) {
            if (Validate::isLoadedObject($superRootCategory)) {
                 $whereClauses[] = 'c.id_category != ' . (int)$superRootCategory->id;
            }
        }


        if (!empty($whereClauses)) {
            $query->where(implode(' AND ', $whereClauses));
        }

        $query->groupBy('c.id_category');

        $countQuery = clone $query;
        $countQuery->select('COUNT(DISTINCT c.id_category)');
        $totalItems = (int)\Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($countQuery);

        if ($totalItems === 0) {
            return ['data' => [], 'total' => 0];
        }

        $orderByMap = [
            'id_category' => 'c.id_category',
            'name' => 'cl.name',
            'position' => 'cs.position',
            'level_depth' => 'c.level_depth',
            'date_add' => 'c.date_add',
            'date_upd' => 'c.date_upd',
        ];
        $orderByColumn = $orderByMap[strtolower($orderBy)] ?? 'c.level_depth';
        // For position, it's often relative to parent, so sorting globally by position might be complex
        // For now, 'level_depth' then 'position' is a good default
        $query->orderBy(($orderByColumn . ' ' . $orderWay) . ($orderByColumn !== 'cs.position' ? ', cs.position ASC' : ''));


        $query->limit($limit, $offset);

        $results = \Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);
        return ['data' => $results ? array_column($results, 'id_category') : [], 'total' => $totalItems];
    }

    public function getById(int $id)
    {
        $category = $this->getResourceInstance($id);
        // Allow viewing inactive categories if user has admin rights (conceptual)
        if (!Validate::isLoadedObject($category) || (!$category->active && !$this->userHasAdminRights())) {
            return null;
        }
        return $category;
    }

    public function create(object $dto) // $dto is an instance of CategoryDTO
    {
        $validationErrors = $this->validateDto($dto);
        if (!empty($validationErrors)) {
            return ['errors' => $validationErrors];
        }

        $category = $this->getResourceInstance();
        $this->hydrateObjectModelFromDto($dto, $category);

        if (empty($category->id_parent)) {
            $category->id_parent = (int)Configuration::get('PS_HOME_CATEGORY');
        }
        $category->id_shop_list = $category->id_shop_list ?? [(int)$this->id_shop];


        try {
            if (!$category->add()) {
                return ['errors' => ['Failed to save category. DB error or PrestaShop core validation failed.']];
            }

            if (!empty($dto->image_data['base64_content'])) {
                $this->handleImageUpload($category, $dto->image_data);
            }

            return $this->getResourceInstance($category->id);
        } catch (PrestaShopException $e) {
            return ['errors' => [$e->getMessage()]];
        } catch (\Exception $e) { // Catch specific image upload errors
             return ['errors' => ['Category saved, but image upload failed: ' . $e->getMessage()]];
        }
    }

    public function update(int $id, object $dto) // $dto is an instance of CategoryDTO
    {
        $category = $this->getResourceInstance($id);
        if (!Validate::isLoadedObject($category)) {
            return ['errors' => ['Category not found.']];
        }

        if (property_exists($dto, 'id_category')) {
            $dto->id_category = $id;
        }

        // Retain existing multilang fields if not provided in DTO for update
        foreach (Language::getLanguages(false) as $lang) {
            foreach (['name', 'description', 'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords', 'image_legend'] as $field) {
                 if (property_exists($dto, $field) && is_array($dto->{$field}) && empty($dto->{$field}[$lang['id_lang']])) {
                    if (isset($category->{$field}[$lang['id_lang']])) {
                        $dto->{$field}[$lang['id_lang']] = $category->{$field}[$lang['id_lang']];
                    }
                }
            }
        }


        $validationErrors = $this->validateDto($dto);
        if (!empty($validationErrors)) {
            return ['errors' => $validationErrors];
        }

        $this->hydrateObjectModelFromDto($dto, $category);

        try {
            if (!$category->update()) {
                return ['errors' => ['Failed to update category. DB error or PrestaShop core validation failed.']];
            }

            if (!empty($dto->image_data['base64_content'])) {
                $this->handleImageUpload($category, $dto->image_data);
            } elseif (isset($dto->image_data['delete_image']) && $dto->image_data['delete_image'] === true) { // Check if DTO has a way to signal delete
                 $category->deleteImage(true);
            }


            return $this->getResourceInstance($category->id);
        } catch (PrestaShopException $e) {
            return ['errors' => [$e->getMessage()]];
        } catch (\Exception $e) {
             return ['errors' => ['Category updated, but image processing failed: ' . $e->getMessage()]];
        }
    }

    public function delete(int $id): bool|array
    {
        if ($id == Configuration::get('PS_ROOT_CATEGORY') || $id == Configuration::get('PS_HOME_CATEGORY')) {
            return ['errors' => ['Cannot delete root or home category.']];
        }

        $category = $this->getResourceInstance($id);
        if (!Validate::isLoadedObject($category)) {
            return ['errors' => ['Category not found.']];
        }

        try {
            // PrestaShop's delete() method for Category handles moving children/products by default.
            // The behavior can be configured in BO > Shop Parameters > Product Settings > "Action when deleting a category"
            if (!$category->delete()) {
                return ['errors' => ['Failed to delete category. It might have dependencies or deletion is restricted.']];
            }
            return true;
        } catch (PrestaShopException $e) {
            return ['errors' => [$e->getMessage()]];
        }
    }

    private function handleImageUpload(Category $category, array $imageData)
    {
        $decodedImage = base64_decode($imageData['base64_content']);
        if ($decodedImage === false) {
            throw new \Exception('Invalid base64 image content.');
        }

        $tempFilename = Tools::passwdGen(16); // Generate a random name
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempFilename;
        file_put_contents($tempPath, $decodedImage);

        $extension = strtolower(pathinfo($imageData['filename'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array($extension, $allowedExtensions)) {
            unlink($tempPath);
            throw new \Exception('Invalid image type: ' . $extension . '. Only jpg, jpeg, png, gif allowed.');
        }

        // Ensure target directory exists
        if (!file_exists(_PS_CAT_IMG_DIR_)) {
            mkdir(_PS_CAT_IMG_DIR_, 0775, true);
        }

        $origPath = _PS_CAT_IMG_DIR_ . (int)$category->id . '.' . $extension;

        // Delete old image before copying new one, regardless of extension
        $category->deleteImage(true); // true to force regeneration of thumbnails (no-image)

        if (ImageManager::resize($tempPath, $origPath, null, null, $extension)) {
            $imagesTypes = ImageType::getImagesTypes('categories');
            foreach ($imagesTypes as $imageType) {
                ImageManager::resize($tempPath, _PS_CAT_IMG_DIR_ . (int)$category->id . '-' . stripslashes($imageType['name']) . '.' . $extension, $imageType['width'], $imageType['height'], $extension);
            }
            // Update category's id_image if PrestaShop structure relies on it.
            // For categories, the image name is just {id_category}.{ext}, so id_image is not directly stored on category table like products.
            // However, some modules or themes might expect $category->id_image to be set.
            // We can set it to $category->id to signify an image exists.
            $category->id_image = (int)$category->id; // Or simply ensure the file is there
                                                     // This might not be necessary if $category->getCatImageLink works based on file existence.
            // No need to call $category->update() just for image if it's already saved.
            // If 'id_image' field needs update on 'ps_category' table, then yes. But it's not standard.
        } else {
            unlink($tempPath);
            throw new \Exception('Failed to process and save category image using ImageManager.');
        }
        @unlink($tempPath); // Clean up temp file
    }

    private function userHasAdminRights(): bool
    {
        // Placeholder for actual role check based on JWT payload
        return false;
    }
}
