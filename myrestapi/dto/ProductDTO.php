<?php
namespace MyRestApi\Dto;

use Validate;
use Language;
use Product; // Ensure Product class is recognized

class ProductDTO
{
    // Basic Product Fields
    public $id_product;
    public $name; // Multilang
    public $description; // Multilang
    public $description_short; // Multilang
    public $link_rewrite; // Multilang
    public $meta_title; // Multilang
    public $meta_description; // Multilang
    public $meta_keywords; // Multilang
    public $available_now; // Multilang
    public $available_later; // Multilang

    public $id_supplier;
    public $id_manufacturer;
    public $id_category_default;
    public $id_shop_default;
    public $id_tax_rules_group;
    public $on_sale;
    public $online_only;
    public $ean13;
    public $isbn;
    public $upc;
    public $mpn;
    public $ecotax;
    public $quantity = 0;
    public $minimal_quantity = 1;
    public $low_stock_threshold;
    public $low_stock_alert;
    public $price; // Default shop price, tax excluded
    public $wholesale_price;
    public $unity;
    public $unit_price_ratio;
    public $additional_shipping_cost;
    public $reference;
    public $supplier_reference;
    public $location;
    public $width;
    public $height;
    public $depth;
    public $weight;
    public $out_of_stock; // Action when out of stock
    public $additional_delivery_times; // 1 for default, 2 for specific
    public $delivery_in_stock; // Multilang
    public $delivery_out_stock; // Multilang

    public $active = true;
    public $redirect_type = ''; // e.g., 404, 301-product, 302-product, etc.
    public $id_type_redirected; // Target product ID if redirected
    public $available_for_order = true;
    public $available_date;
    public $show_condition; // 0 or 1
    public $condition; // 'new', 'used', 'refurbished'
    public $show_price = true;
    public $indexed = true;
    public $visibility = 'both'; // 'both', 'catalog', 'search', 'none'

    public $advanced_stock_management = false;
    public $pack_stock_type = 3; // Default for PrestaShop 1.7+

    // Categories (array of id_category)
    public $categories; // Array of category IDs

    // Images (array of image URLs or new image data) - Placeholder for now
    // public $images;

    // Features (array of feature objects {id_feature, id_feature_value} or {id_feature, custom_value})
    // public $features;

    // Combinations - More complex, handle separately if needed initially
    // public $combinations;


    /**
     * Populates DTO from an array of data (e.g., JSON request).
     * @param array $data
     * @return ProductDTO
     */
    public static function fromArray(array $data): ProductDTO
    {
        $dto = new self();
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');

        foreach ($data as $key => $value) {
            if (property_exists($dto, $key)) {
                // Handle multilang fields
                if (is_array($value) && in_array($key, ['name', 'description', 'description_short', 'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords', 'available_now', 'available_later', 'delivery_in_stock', 'delivery_out_stock'])) {
                    $dto->$key = [];
                    foreach (Language::getLanguages(false) as $lang) {
                        $dto->$key[$lang['id_lang']] = $value[$lang['iso_code']] ?? ($value[$lang['id_lang']] ?? null);
                    }
                } else {
                    $dto->$key = $value;
                }
            }
        }
        return $dto;
    }

    /**
     * Validates the DTO properties.
     * Returns an array of error messages, empty if valid.
     * @return array
     */
    public function validate(): array
    {
        $errors = [];
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');

        // Name validation (required, multilang)
        if (empty($this->name[$defaultLangId])) {
            $errors[] = 'Product name is required for the default language.';
        }
        foreach (Language::getLanguages(true) as $lang) {
            if (!empty($this->name[$lang['id_lang']]) && !Validate::isCatalogName($this->name[$lang['id_lang']])) {
                $errors[] = 'Invalid product name for language ISO: ' . $lang['iso_code'];
            }
            if (isset($this->link_rewrite[$lang['id_lang']]) && !Validate::isLinkRewrite($this->link_rewrite[$lang['id_lang']])) {
                $errors[] = 'Invalid link_rewrite for language ISO: ' . $lang['iso_code'];
            }
        }

        if (isset($this->reference) && !Validate::isReference($this->reference)) {
            $errors[] = 'Invalid product reference.';
        }
        if (isset($this->ean13) && !empty($this->ean13) && !Validate::isEan13($this->ean13)) {
            $errors[] = 'Invalid EAN13 code.';
        }
        if (isset($this->price) && !Validate::isPrice($this->price)) {
            $errors[] = 'Invalid price.';
        }
        if (isset($this->wholesale_price) && !Validate::isPrice($this->wholesale_price)) {
            $errors[] = 'Invalid wholesale price.';
        }
        if (isset($this->id_category_default) && !Validate::isUnsignedId($this->id_category_default)) {
            $errors[] = 'Invalid default category ID.';
        }
        if (isset($this->quantity) && !Validate::isInt($this->quantity)) {
            $errors[] = 'Invalid quantity.';
        }
        if (isset($this->minimal_quantity) && !Validate::isUnsignedInt($this->minimal_quantity)) {
             $errors[] = 'Invalid minimal quantity.';
        }
        if (isset($this->id_tax_rules_group) && !Validate::isUnsignedId($this->id_tax_rules_group)) {
            $errors[] = 'Invalid tax rules group ID.';
        }

        // Validate categories if provided
        if (isset($this->categories)) {
            if (!is_array($this->categories)) {
                $errors[] = 'Categories must be an array of IDs.';
            } else {
                foreach ($this->categories as $id_category) {
                    if (!Validate::isUnsignedId($id_category)) {
                        $errors[] = 'Invalid category ID in categories list: ' . $id_category;
                        break;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Hydrates a PrestaShop Product object from this DTO.
     * @param Product $product
     */
    public function hydrateProduct(Product $product): void
    {
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');

        foreach (get_object_vars($this) as $key => $value) {
            if ($value === null) continue; // Skip null values from DTO

            if (in_array($key, ['name', 'description', 'description_short', 'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords', 'available_now', 'available_later', 'delivery_in_stock', 'delivery_out_stock'])) {
                // Prepare multilang fields
                $product->$key = [];
                foreach (Language::getLanguages(false) as $lang) {
                    if (isset($this->{$key}[$lang['id_lang']])) {
                        $product->{$key}[$lang['id_lang']] = $this->{$key}[$lang['id_lang']];
                    } elseif (isset($this->{$key}[$lang['iso_code']])) { // Fallback to ISO code if id_lang not used in input
                        $product->{$key}[$lang['id_lang']] = $this->{$key}[$lang['iso_code']];
                    } else {
                         // Ensure all languages have at least an empty string if not provided,
                         // or copy from default if that's the desired behavior.
                         // For link_rewrite, generate if empty from name.
                        if ($key === 'link_rewrite' && empty($product->{$key}[$lang['id_lang']]) && !empty($this->name[$lang['id_lang']])) {
                            $product->{$key}[$lang['id_lang']] = Tools::linkRewrite($this->name[$lang['id_lang']]);
                        } elseif (empty($product->{$key}[$lang['id_lang']])) {
                            $product->{$key}[$lang['id_lang']] = $this->name[$defaultLangId] ?? ''; // Default to empty or could be derived
                        }
                    }
                }
            } elseif (property_exists($product, $key)) {
                // Handle type casting for specific fields
                if (in_array($key, ['price', 'wholesale_price', 'ecotax', 'width', 'height', 'depth', 'weight', 'unit_price_ratio'])) {
                    $product->$key = (float) $value;
                } elseif (in_array($key, ['quantity', 'minimal_quantity', 'id_supplier', 'id_manufacturer', 'id_category_default', 'id_shop_default', 'id_tax_rules_group', 'low_stock_threshold', 'out_of_stock', 'additional_delivery_times', 'id_type_redirected'])) {
                    $product->$key = (int) $value;
                } elseif (in_array($key, ['on_sale', 'online_only', 'low_stock_alert', 'active', 'available_for_order', 'show_condition', 'show_price', 'indexed', 'advanced_stock_management'])) {
                    $product->$key = (bool) $value;
                } elseif ($key === 'available_date' && !empty($value)) {
                    $product->$key = Validate::isDateFormat($value) ? $value : null;
                }
                else {
                    $product->$key = $value;
                }
            }
        }

        // Ensure link_rewrite is generated for all languages if not provided
        foreach (Language::getLanguages(false) as $lang) {
            if (empty($product->link_rewrite[$lang['id_lang']]) && !empty($product->name[$lang['id_lang']])) {
                $product->link_rewrite[$lang['id_lang']] = Tools::linkRewrite($product->name[$lang['id_lang']]);
            }
        }

        // If id_shop_default is not set, try to set it from context or default
        if (empty($product->id_shop_default)) {
            $product->id_shop_default = (int) \Context::getContext()->shop->id;
        }
        if (empty($product->id_shop_list)) {
             $product->id_shop_list = [\Context::getContext()->shop->id];
        }


    }
}
