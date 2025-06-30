<?php
namespace MyRestApi\Rto;

use CMS;
use Language;
use Link;
use Configuration;
use Context;
use Tools; // For any text processing if needed

class CmsPageRTO
{
    private $cmsPage;
    private $id_lang;
    private $context;
    private $link;

    public function __construct(CMS $cmsPage, int $id_lang)
    {
        $this->cmsPage = $cmsPage;
        $this->id_lang = $id_lang;
        $this->context = Context::getContext();
        $this->link = $this->context->link;
    }

    private function getMultilangField($field)
    {
        if (is_array($this->cmsPage->{$field})) {
            return $this->cmsPage->{$field}[$this->id_lang] ?? $this->cmsPage->{$field}[(int)Configuration::get('PS_LANG_DEFAULT')] ?? '';
        }
        // For CMS, some fields like 'content' might not be directly on the object in multilang array form after construction.
        // CMS object loads current lang by default. If we need all langs, we'd have to fetch them.
        // For simplicity, this RTO will return data for the provided $id_lang.
        return $this->cmsPage->{$field} ?? '';
    }

    public function toArray(): array
    {
        // Ensure CMS object is loaded for the specified language
        // The CMS object constructor itself takes $id_lang.
        // If $this->cmsPage was constructed with a different $id_lang, its properties would reflect that.
        // For an RTO, it's better if the passed $cmsPage object is already language-specific or if RTO re-fetches.
        // Assuming $this->cmsPage is already correctly loaded for $this->id_lang.

        $content = $this->getMultilangField('content');

        // Resolve shortcodes or dynamic content if needed (advanced)
        // For example, PrestaShop uses a specific shortcode format for contact forms, etc.
        // $content = self::doShortcode($content); // Example custom method

        return [
            'id_cms' => $this->cmsPage->id,
            'id_cms_category' => (int)$this->cmsPage->id_cms_category,
            'position' => (int)$this->cmsPage->position,
            'active' => (bool)$this->cmsPage->active,
            'indexed' => (bool)$this->cmsPage->indexation, // Note: property name is indexation
            'meta_title' => $this->getMultilangField('meta_title'),
            'meta_description' => $this->getMultilangField('meta_description'),
            'meta_keywords' => $this->getMultilangField('meta_keywords'),
            'content' => $content,
            'link_rewrite' => $this->getMultilangField('link_rewrite'),
            'page_url' => $this->link->getCMSLink($this->cmsPage, null, true, $this->id_lang),
            'date_add' => $this->cmsPage->date_add, // Assuming these exist on the CMS object
            'date_upd' => $this->cmsPage->date_upd,
        ];
    }

    /**
     * Placeholder for processing shortcodes or dynamic tags within CMS content.
     * PrestaShop itself does some processing, e.g. for {contact_form}.
     * This can be expanded to handle custom shortcodes if the headless CMS needs it.
     */
    public static function doShortcode(string $content): string
    {
        // Example: Replace a simple shortcode like [shop_name]
        // $content = str_replace('[shop_name]', Configuration::get('PS_SHOP_NAME'), $content);
        // More complex shortcode parsing would use regular expressions.
        return $content;
    }
}
