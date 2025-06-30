<?php
namespace MyRestApi\Rto;

use Product;
use Manufacturer;
use Supplier;
use Category;
use Language;
use Link;
use Image;
use StockAvailable;
use Configuration;
use Context;
use FeatureValue;
use ProductAttribute;
use Tools;

class ProductRTO
{
    private $product;
    private $id_lang;
    private $context;
    private $link;

    // Configuration for what to include
    private $include = [
        'categories' => false,
        'manufacturer' => false,
        'supplier' => false,
        'images' => true, // Basic image info by default
        'specific_prices' => false,
        'stock_available' => true,
        'features' => false,
        'combinations' => false,
        'attachments' => false,
        'accessories' => false,
        'customization_fields' => false,
    ];

    public function __construct(Product $product, int $id_lang, array $includeOptions = [])
    {
        $this->product = $product;
        $this->id_lang = $id_lang;
        $this->context = Context::getContext();
        $this->link = $this->context->link;
        $this->configureIncludes($includeOptions);
    }

    private function configureIncludes(array $includeOptions): void
    {
        if (empty($includeOptions)) return;

        // If 'all' is specified, set all known relations to true
        if (in_array('all', $includeOptions)) {
            foreach (array_keys($this->include) as $key) {
                $this->include[$key] = true;
            }
            return; // 'all' overrides other specific includes for simplicity here
        }

        foreach ($includeOptions as $option) {
            if (array_key_exists($option, $this->include)) {
                $this->include[$option] = true;
            }
        }
    }

    private function getMultilangField($field)
    {
        if (is_array($this->product->{$field})) {
            return $this->product->{$field}[$this->id_lang] ?? $this->product->{$field}[(int)Configuration::get('PS_LANG_DEFAULT')] ?? '';
        }
        return $this->product->{$field} ?? '';
    }

    public function toArray(): array
    {
        $data = [
            'id_product' => $this->product->id,
            'name' => $this->getMultilangField('name'),
            'reference' => $this->product->reference,
            'ean13' => $this->product->ean13,
            'isbn' => $this->product->isbn,
            'upc' => $this->product->upc,
            'mpn' => $this->product->mpn,
            'active' => (bool)$this->product->active,
            'available_for_order' => (bool)$this->product->available_for_order,
            'show_price' => (bool)$this->product->show_price,
            'price' => Tools::ps_round((float)$this->product->price, 2), // Tax exl without specific price
            'wholesale_price' => Tools::ps_round((float)$this->product->wholesale_price, 2),
            'id_category_default' => (int)$this->product->id_category_default,
            'id_manufacturer' => (int)$this->product->id_manufacturer,
            'id_supplier' => (int)$this->product->id_supplier,
            'id_tax_rules_group' => (int)$this->product->id_tax_rules_group,
            'description_short' => $this->getMultilangField('description_short'),
            'description' => $this->getMultilangField('description'),
            'link_rewrite' => $this->getMultilangField('link_rewrite'),
            'meta_title' => $this->getMultilangField('meta_title'),
            'meta_description' => $this->getMultilangField('meta_description'),
            'meta_keywords' => $this->getMultilangField('meta_keywords'),
            'available_now' => $this->getMultilangField('available_now'),
            'available_later' => $this->getMultilangField('available_later'),
            'condition' => $this->product->condition,
            'show_condition' => (bool)$this->product->show_condition,
            'online_only' => (bool)$this->product->online_only,
            'minimal_quantity' => (int)$this->product->minimal_quantity,
            'quantity' => (int)StockAvailable::getQuantityAvailableByProduct($this->product->id, null, $this->context->shop->id), // Get current stock
            'out_of_stock' => (int)$this->product->out_of_stock,
            'low_stock_threshold' => $this->product->low_stock_threshold,
            'low_stock_alert' => (bool)$this->product->low_stock_alert,
            'unity' => $this->product->unity,
            'unit_price_ratio' => (float)$this->product->unit_price_ratio,
            'ecotax' => (float)$this->product->ecotax,
            'additional_shipping_cost' => (float)$this->product->additional_shipping_cost,
            'width' => (float)$this->product->width,
            'height' => (float)$this->product->height,
            'depth' => (float)$this->product->depth,
            'weight' => (float)$this->product->weight,
            'date_add' => $this->product->date_add,
            'date_upd' => $this->product->date_upd,
            'available_date' => $this->product->available_date,
            'product_url' => $this->link->getProductLink($this->product, null, null, null, $this->id_lang),
        ];

        if ($this->include['stock_available']) {
            $data['stock_available_data'] = $this->getStockAvailableData();
        }

        if ($this->include['categories']) {
            $data['categories'] = $this->getCategories();
        }
        if ($this->include['manufacturer'] && $this->product->id_manufacturer) {
            $data['manufacturer'] = $this->getManufacturer();
        }
        if ($this->include['supplier'] && $this->product->id_supplier) {
            $data['supplier'] = $this->getSupplier();
        }
        if ($this->include['images']) {
            $data['images'] = $this->getImages();
        }
        if ($this->include['features']) {
            $data['features'] = $this->getFeatures();
        }
        if ($this->include['combinations']) {
            $data['combinations'] = $this->getCombinations();
        }
        // Add other includes as needed (specific_prices, attachments etc.)

        return $data;
    }

    private function getStockAvailableData(): array
    {
        $stocks = [];
        $id_shop_group = $this->context->shop->id_shop_group;
        $id_shop = $this->context->shop->id;

        $query = new \DbQuery();
        $query->select('sa.id_stock_available, sa.id_product_attribute, sa.quantity, sa.depends_on_stock, sa.out_of_stock');
        $query->from('stock_available', 'sa');
        $query->where('sa.id_product = ' . (int)$this->product->id);
        $query->where('sa.id_shop = ' . (int)$id_shop . ' OR sa.id_shop_group = ' . (int)$id_shop_group);

        $result = \Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);
        if ($result) {
            foreach($result as $row) {
                $stocks[] = [
                    'id_stock_available' => (int)$row['id_stock_available'],
                    'id_product_attribute' => (int)$row['id_product_attribute'],
                    'quantity' => (int)$row['quantity'],
                    'depends_on_stock' => (bool)$row['depends_on_stock'],
                    'out_of_stock_behavior' => (int)$row['out_of_stock'], // 0: deny, 1: allow, 2: use global
                ];
            }
        }
        return $stocks;
    }

    private function getCategories(): array
    {
        $categories = [];
        $productCategories = Product::getProductCategoriesFull($this->product->id, $this->id_lang);
        foreach ($productCategories as $cat) {
            $category = new Category($cat['id_category'], $this->id_lang);
            $categories[] = [
                'id_category' => $category->id,
                'name' => $category->name,
                'link_rewrite' => $category->link_rewrite,
                'is_default' => ($category->id == $this->product->id_category_default),
            ];
        }
        return $categories;
    }

    private function getManufacturer(): ?array
    {
        if (!$this->product->id_manufacturer) {
            return null;
        }
        $manufacturer = new Manufacturer($this->product->id_manufacturer, $this->id_lang);
        if (Validate::isLoadedObject($manufacturer)) {
            return [
                'id_manufacturer' => $manufacturer->id,
                'name' => $manufacturer->name,
                'active' => (bool)$manufacturer->active,
                // Add more manufacturer fields if needed
            ];
        }
        return null;
    }

    private function getSupplier(): ?array
    {
        if (!$this->product->id_supplier) {
            return null;
        }
        $supplier = new Supplier($this->product->id_supplier, $this->id_lang);
         if (Validate::isLoadedObject($supplier)) {
            return [
                'id_supplier' => $supplier->id,
                'name' => $supplier->name,
                'active' => (bool)$supplier->active,
                // Add more supplier fields if needed
            ];
        }
        return null;
    }

    private function getImages(): array
    {
        $images = [];
        $productImages = Image::getImages($this->id_lang, $this->product->id);
        foreach ($productImages as $img) {
            $image = new Image($img['id_image']);
            $imageUrl = $this->link->getImageLink($this->product->link_rewrite[$this->id_lang] ?? $this->product->link_rewrite[(int)Configuration::get('PS_LANG_DEFAULT')], $image->id, 'large_default');
            $images[] = [
                'id_image' => $image->id,
                'legend' => $image->legend[$this->id_lang] ?? '',
                'position' => (int)$image->position,
                'cover' => (bool)$image->cover,
                'url' => $imageUrl,
                // Add different image types if needed (small_default, medium_default, etc.)
                // 'small_url' => $this->link->getImageLink($this->product->link_rewrite[$this->id_lang], $image->id, 'small_default'),
                // 'medium_url' => $this->link->getImageLink($this->product->link_rewrite[$this->id_lang], $image->id, 'medium_default'),
            ];
        }
        return $images;
    }

    private function getFeatures(): array
    {
        $features = [];
        $productFeatures = Product::getFrontFeaturesStatic($this->id_lang, $this->product->id);
        foreach ($productFeatures as $feature) {
            $features[] = [
                'id_feature' => (int)$feature['id_feature'],
                'name' => $feature['name'],
                'value' => $feature['value'],
                // 'id_feature_value' => $feature['id_feature_value'] // This might not be directly available in getFrontFeaturesStatic
            ];
        }
        return $features;
    }

    private function getCombinations(): array
    {
        $combinationsArray = [];
        $combinations = $this->product->getAttributeCombinations($this->id_lang);

        if (is_array($combinations)) {
            foreach ($combinations as $combination) {
                $id_product_attribute = (int)$combination['id_product_attribute'];
                if (!isset($combinationsArray[$id_product_attribute])) {
                    $combinationsArray[$id_product_attribute] = [
                        'id_product_attribute' => $id_product_attribute,
                        'reference' => $combination['reference'],
                        'ean13' => $combination['ean13'],
                        'isbn' => $combination['isbn'],
                        'upc' => $combination['upc'],
                        'mpn' => $combination['mpn'],
                        'price' => (float)$combination['price'], // price impact
                        'wholesale_price' => (float)$combination['wholesale_price'],
                        'ecotax' => (float)$combination['ecotax'],
                        'quantity' => (int)$combination['quantity'],
                        'weight' => (float)$combination['weight'], // weight impact
                        'unit_price_impact' => (float)$combination['unit_price_impact'],
                        'minimal_quantity' => (int)$combination['minimal_quantity'],
                        'low_stock_threshold' => $combination['low_stock_threshold'],
                        'low_stock_alert' => (bool)$combination['low_stock_alert'],
                        'available_date' => $combination['available_date'],
                        'default_on' => (bool)$combination['default_on'],
                        'attributes' => [],
                        'image_ids' => ProductAttribute::getAttributeImages($id_product_attribute) // Get image IDs for this combination
                    ];
                }
                $combinationsArray[$id_product_attribute]['attributes'][] = [
                    'group_name' => $combination['group_name'],
                    'attribute_name' => $combination['attribute_name'],
                    'id_attribute_group' => (int)$combination['id_attribute_group'],
                    'id_attribute' => (int)$combination['id_attribute'],
                ];
            }
        }
        return array_values($combinationsArray); // Re-index array
    }
}
