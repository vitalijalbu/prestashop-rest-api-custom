<?php
namespace MyRestApi\Dto;

use Validate;
use Language;
use Configuration;
use Category; // Ensure Category class is recognized for type hinting if needed
use Tools;

class CategoryDTO
{
    public $id_category;
    public $name; // Multilang
    public $description; // Multilang
    public $link_rewrite; // Multilang
    public $meta_title; // Multilang
    public $meta_description; // Multilang
    public $meta_keywords; // Multilang
    public $id_parent;
    public $active = true;
    public $is_root_category; // Read-only usually, but can be passed
    // public $id_shop_default; // Usually handled by context

    // Image handling - could be base64 string, URL to download, or temp server path
    public $image_data; // e.g., ['filename' => 'cat.jpg', 'base64_content' => '...']
    public $image_legend; // Multilang

    /**
     * Populates DTO from an array of data.
     * @param array $data
     * @return CategoryDTO
     */
    public static function fromArray(array $data): CategoryDTO
    {
        $dto = new self();
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');

        foreach ($data as $key => $value) {
            if (property_exists($dto, $key)) {
                if (is_array($value) && in_array($key, ['name', 'description', 'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords', 'image_legend'])) {
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
     * @return array Error messages.
     */
    public function validate(): array
    {
        $errors = [];
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');

        if (empty($this->name[$defaultLangId])) {
            $errors[] = 'Category name is required for the default language.';
        }
        foreach (Language::getLanguages(true) as $lang) {
            if (!empty($this->name[$lang['id_lang']]) && !Validate::isCatalogName($this->name[$lang['id_lang']])) {
                $errors[] = 'Invalid category name for language ISO: ' . $lang['iso_code'];
            }
            if (isset($this->link_rewrite[$lang['id_lang']]) && !empty($this->link_rewrite[$lang['id_lang']]) && !Validate::isLinkRewrite($this->link_rewrite[$lang['id_lang']])) {
                $errors[] = 'Invalid link_rewrite for language ISO: ' . $lang['iso_code'];
            }
             if (isset($this->image_legend[$lang['id_lang']]) && !empty($this->image_legend[$lang['id_lang']]) && !Validate::isGenericName($this->image_legend[$lang['id_lang']])) {
                $errors[] = 'Invalid image legend for language ISO: ' . $lang['iso_code'];
            }
        }

        if (isset($this->id_parent) && !Validate::isUnsignedId($this->id_parent)) {
            $errors[] = 'Invalid parent category ID.';
        }

        if (isset($this->image_data)) {
            if (!is_array($this->image_data) || empty($this->image_data['base64_content']) || empty($this->image_data['filename'])) {
                $errors[] = 'Image data must be an array with "filename" and "base64_content" if provided.';
            } elseif (!preg_match('/^[a-zA-Z0-9_.-]+\.(jpg|jpeg|png|gif)$/i', $this->image_data['filename'])) {
                $errors[] = 'Invalid image filename or extension (only jpg, jpeg, png, gif allowed).';
            }
        }

        return $errors;
    }

    /**
     * Hydrates a PrestaShop Category object from this DTO.
     * @param Category $category
     */
    public function hydrateCategory(Category $category): void
    {
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');

        foreach (get_object_vars($this) as $key => $value) {
            if ($value === null && $key !== 'id_parent') continue; // Allow id_parent to be explicitly null for root

            if (in_array($key, ['name', 'description', 'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords', 'image_legend'])) {
                $category->$key = [];
                foreach (Language::getLanguages(false) as $lang) {
                     if (isset($this->{$key}[$lang['id_lang']])) {
                        $category->{$key}[$lang['id_lang']] = $this->{$key}[$lang['id_lang']];
                    } elseif (isset($this->{$key}[$lang['iso_code']])) {
                        $category->{$key}[$lang['id_lang']] = $this->{$key}[$lang['iso_code']];
                    } else {
                        // Ensure link_rewrite is generated if empty
                        if ($key === 'link_rewrite' && empty($category->{$key}[$lang['id_lang']]) && !empty($this->name[$lang['id_lang']])) {
                            $category->{$key}[$lang['id_lang']] = Tools::linkRewrite($this->name[$lang['id_lang']]);
                        } elseif (empty($category->{$key}[$lang['id_lang']])) {
                           // $category->{$key}[$lang['id_lang']] = ''; // Default to empty
                        }
                    }
                }
            } elseif (property_exists($category, $key)) {
                if ($key === 'id_parent') {
                    $category->id_parent = (int)$value;
                } elseif ($key === 'active' || $key === 'is_root_category') {
                    $category->$key = (bool)$value;
                } else {
                    $category->$key = $value;
                }
            }
        }

        // Auto-generate link_rewrite if not provided
        foreach (Language::getLanguages(false) as $lang) {
            if (empty($category->link_rewrite[$lang['id_lang']]) && !empty($category->name[$lang['id_lang']])) {
                $category->link_rewrite[$lang['id_lang']] = Tools::linkRewrite($category->name[$lang['id_lang']]);
            }
        }

        if (empty($category->id_shop_list)) {
             $category->id_shop_list = [\Context::getContext()->shop->id];
        }
    }
}
