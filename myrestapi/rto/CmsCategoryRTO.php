<?php
namespace MyRestApi\Rto;

use CMSCategory;
use Language;
use Link;
use Configuration;
use Context;
use CMS; // For listing pages in category

class CmsCategoryRTO
{
    private $cmsCategory;
    private $id_lang;
    private $context;
    private $link;

    private $include = [
        'pages' => false, // Flag to include CMS pages within this category
    ];
    private $pageLimit = 10; // Default limit for pages if included


    public function __construct(CMSCategory $cmsCategory, int $id_lang, array $includeOptions = [], array $options = [])
    {
        $this->cmsCategory = $cmsCategory;
        $this->id_lang = $id_lang;
        $this->context = Context::getContext();
        $this->link = $this->context->link;
        $this->configureIncludes($includeOptions);
        if (isset($options['page_limit'])) {
            $this->pageLimit = max(1, (int)$options['page_limit']);
        }
    }

    private function configureIncludes(array $includeOptions): void
    {
        if (empty($includeOptions)) return;

        foreach ($includeOptions as $option) {
            if (array_key_exists($option, $this->include)) {
                $this->include[$option] = true;
            }
        }
    }

    private function getMultilangField($field)
    {
        if (is_array($this->cmsCategory->{$field})) {
            return $this->cmsCategory->{$field}[$this->id_lang] ?? $this->cmsCategory->{$field}[(int)Configuration::get('PS_LANG_DEFAULT')] ?? '';
        }
        return $this->cmsCategory->{$field} ?? '';
    }

    public function toArray(): array
    {
        $data = [
            'id_cms_category' => $this->cmsCategory->id,
            'name' => $this->getMultilangField('name'),
            'description' => $this->getMultilangField('description'),
            'link_rewrite' => $this->getMultilangField('link_rewrite'),
            'meta_title' => $this->getMultilangField('meta_title'),
            'meta_description' => $this->getMultilangField('meta_description'),
            'meta_keywords' => $this->getMultilangField('meta_keywords'),
            'active' => (bool)$this->cmsCategory->active,
            'position' => (int)$this->cmsCategory->position,
            'id_parent' => (int)$this->cmsCategory->id_parent,
            'level_depth' => (int)$this->cmsCategory->level_depth,
            'category_url' => $this->link->getCMSCategoryLink($this->cmsCategory, null, $this->id_lang),
            'date_add' => $this->cmsCategory->date_add,
            'date_upd' => $this->cmsCategory->date_upd,
        ];

        if ($this->include['pages']) {
            $data['pages'] = $this->getPagesInCategory();
        }

        return $data;
    }

    private function getPagesInCategory(): array
    {
        $pagesData = [];
        // Get limited number of active CMS pages for preview
        // CMSCategory::getCMSPages needs id_shop, but it's not a direct param. It uses context.
        $pages = CMSCategory::getCMSPages($this->cmsCategory->id, null, true, $this->id_lang, $this->pageLimit);

        if ($pages) {
            foreach ($pages as $page_data) {
                // CMS::getCMSPages returns an array of arrays, not CMS objects.
                // We need to create CMS objects to use CmsPageRTO if we want full details.
                // For now, a simpler representation.
                 $pagesData[] = [
                    'id_cms' => (int)$page_data['id_cms'],
                    'meta_title' => $page_data['meta_title'], // Already lang specific from getCMSPages
                    'link_rewrite' => $page_data['link_rewrite'],
                    'page_url' => $this->link->getCMSLink((int)$page_data['id_cms'], $page_data['link_rewrite'], true, $this->id_lang),
                ];
            }
        }
        return $pagesData;
    }
}
