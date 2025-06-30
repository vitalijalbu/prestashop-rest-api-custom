<?php
namespace MyRestApi\Rto;

use Category;
use Language;
use Link;
use Configuration;
use Context;
use Product; // For counting products
use Tools;

class CategoryRTO
{
    private $category;
    private $id_lang;
    private $context;
    private $link;

    private $include = [
        'subcategories' => false,
        'products' => false, // Flag to include products
        'product_details' => false, // Flag for full product RTO vs IDs/names
    ];

    private $productLimit = 10; // Default limit for products if included

    public function __construct(Category $category, int $id_lang, array $includeOptions = [], array $options = [])
    {
        $this->category = $category;
        $this->id_lang = $id_lang;
        $this->context = Context::getContext();
        $this->link = $this->context->link;
        $this->configureIncludes($includeOptions);
        if (isset($options['product_limit'])) {
            $this->productLimit = max(1, (int)$options['product_limit']);
        }
    }

    private function configureIncludes(array $includeOptions): void
    {
        if (empty($includeOptions)) return;

        if (in_array('all_relations', $includeOptions)) { // Specific keyword for category relations
            $this->include['subcategories'] = true;
            $this->include['products'] = true;
             // $this->include['product_details'] = true; // Example: 'all_relations' implies full details
            return;
        }

        foreach ($includeOptions as $option) {
            if (array_key_exists($option, $this->include)) {
                $this->include[$option] = true;
            }
        }
    }

    private function getMultilangField($field)
    {
        if (is_array($this->category->{$field})) {
            return $this->category->{$field}[$this->id_lang] ?? $this->category->{$field}[(int)Configuration::get('PS_LANG_DEFAULT')] ?? '';
        }
        return $this->category->{$field} ?? '';
    }

    public function toArray(): array
    {
        $data = [
            'id_category' => $this->category->id,
            'name' => $this->getMultilangField('name'),
            'description' => $this->getMultilangField('description'),
            'link_rewrite' => $this->getMultilangField('link_rewrite'),
            'id_parent' => (int)$this->category->id_parent,
            'level_depth' => (int)$this->category->level_depth,
            'active' => (bool)$this->category->active,
            'is_root_category' => (bool)$this->category->is_root_category,
            'meta_title' => $this->getMultilangField('meta_title'),
            'meta_description' => $this->getMultilangField('meta_description'),
            'meta_keywords' => $this->getMultilangField('meta_keywords'),
            'date_add' => $this->category->date_add,
            'date_upd' => $this->category->date_upd,
            'image_url' => $this->category->id_image ? $this->link->getCatImageLink($this->category->link_rewrite[$this->id_lang] ?? $this->category->link_rewrite[(int)Configuration::get('PS_LANG_DEFAULT')], $this->category->id_image, 'category_default') : null,
            'category_url' => $this->link->getCategoryLink($this->category, null, $this->id_lang),
            // Counts can be intensive, consider making them conditional via $include
            'subcategories_count' => count($this->category->getSubCategories($this->id_lang, true)),
            'products_count' => (int)$this->category->getProducts(null, null, null, null, null, true), // Total products in this category
        ];

        if ($this->include['subcategories']) {
            $data['subcategories'] = $this->getSubcategories();
        }

        if ($this->include['products']) {
            $data['products'] = $this->getProductsInCategory();
        }

        return $data;
    }

    private function getSubcategories(): array
    {
        $subcategoriesData = [];
        $subcategories = $this->category->getSubCategories($this->id_lang, true); // Get only active subcategories
        if ($subcategories) {
            foreach ($subcategories as $subcat_data) {
                $subCategory = new Category($subcat_data['id_category'], $this->id_lang);
                if (Validate::isLoadedObject($subCategory)) {
                    // For subcategories, we typically don't include their own subcategories or products by default
                    // to prevent excessively deep recursion. Pass empty includeOptions.
                    $subRto = new CategoryRTO($subCategory, $this->id_lang, []);
                    $subcategoriesData[] = $subRto->toArray();
                }
            }
        }
        return $subcategoriesData;
    }

    private function getProductsInCategory(): array
    {
        $productsData = [];
        // Get limited number of products for preview
        $products = $this->category->getProducts($this->id_lang, 1, $this->productLimit, 'position', 'ASC');

        if ($products) {
            foreach ($products as $product_data) {
                if ($this->include['product_details']) {
                    $product = new Product($product_data['id_product'], false, $this->id_lang);
                    if (Validate::isLoadedObject($product)) {
                        // Use ProductRTO for full product details
                        // Pass minimal includeOptions for products listed under category to avoid deep nesting
                        $productRto = new ProductRTO($product, $this->id_lang, ['images']);
                        $productsData[] = $productRto->toArray();
                    }
                } else {
                    // Simpler representation: ID, name, maybe price and main image
                    $productsData[] = [
                        'id_product' => (int)$product_data['id_product'],
                        'name' => $product_data['name'],
                        'reference' => $product_data['reference'],
                        'price' => Tools::ps_round((float)Product::getPriceStatic((int)$product_data['id_product'], false),2),
                        'link_rewrite' => $product_data['link_rewrite'],
                        'main_image_url' => $this->link->getImageLink($product_data['link_rewrite'], $product_data['id_image'], 'home_default'), // Example image type
                        'product_url' => $this->link->getProductLink((int)$product_data['id_product'], $product_data['link_rewrite'])
                    ];
                }
            }
        }
        return $productsData;
    }
}
