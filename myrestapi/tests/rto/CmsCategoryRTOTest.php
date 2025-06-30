<?php
namespace MyRestApi\Tests\Rto;

use MyRestApi\Rto\CmsCategoryRTO;
use PHPUnit\Framework\TestCase;
use CMSCategory; // Mocked or real
use Language;
use Link;
use Configuration;
use Context;
use CMS; // For CMSCategory::getCMSPages static method

class CmsCategoryRTOTest extends TestCase
{
    private $mockCmsCategory;
    private $mockLink;
    private $id_lang = 1;

    public static function setUpBeforeClass(): void
    {
        // Mock Link if not already in bootstrap for CmsPageRTOTest
        if (!class_exists('Link')) {
            class Link {
                public function getCMSCategoryLink($cmsCategory, $alias = null, $id_lang = null, $id_shop = null) {
                     $id = is_object($cmsCategory) ? $cmsCategory->id : (int)$cmsCategory;
                     $lr = is_object($cmsCategory) && isset($cmsCategory->link_rewrite[$id_lang]) ? $cmsCategory->link_rewrite[$id_lang] : 'cms-cat';
                    return "http://mockshop.com/cms-category/{$id}-{$lr}";
                }
                 public function getCMSLink($cms, $alias = null, $ssl = false, $id_lang = null, $id_shop = null) { // Also needed by getPagesInCategory
                    $id = is_object($cms) ? $cms->id : (int)$cms;
                    // $lr = is_object($cms) && isset($cms->link_rewrite[$id_lang]) ? $cms->link_rewrite[$id_lang] : 'cms-page';
                    // For this mock, link_rewrite comes from page_data array directly
                    $lr = $alias ?? 'cms-page';
                    return "http://mockshop.com/cms/{$id}-{$lr}";
                }
            }
        }
        // Mock CMSCategory for static call CMSCategory::getCMSPages
        if (!class_exists('CMSCategory')) {
            class CMSCategory { // Basic mock for instantiation and static call
                public $id; public $name; public $link_rewrite; public $description;
                public $meta_title; public $meta_description; public $meta_keywords;
                public $active; public $position; public $id_parent; public $level_depth;
                public $date_add; public $date_upd;

                public function __construct($id = null, $id_lang = null, $id_shop = null) { $this->id = $id; }
                public static function getCMSPages($id_cms_category, $id_shop = null, $active = true, $id_lang = null, $limit = 0) {
                    if ($id_cms_category == 20) { // Example ID for testing
                         $pages = [
                            ['id_cms' => 101, 'meta_title' => 'Page 1 in Cat', 'link_rewrite' => 'page-1-in-cat'],
                            ['id_cms' => 102, 'meta_title' => 'Page 2 in Cat', 'link_rewrite' => 'page-2-in-cat'],
                        ];
                        return ($limit > 0) ? array_slice($pages, 0, $limit) : $pages;
                    }
                    return [];
                }
            }
        }
        Configuration::set('PS_LANG_DEFAULT', 1);
    }

    protected function setUp(): void
    {
        $this->id_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $this->mockLink = new Link();

        $mockContext = $this->createMock(Context::class);
        $mockContext->link = $this->mockLink;
        $mockContext->language = (object)['id' => $this->id_lang];
        // Context::setInstanceForTesting($mockContext);

        $this->mockCmsCategory = $this->createMock(CMSCategory::class); // Use PHPUnit's mock for methods
        $this->mockCmsCategory->id = 20;
        $this->mockCmsCategory->name = [$this->id_lang => 'Test CMS Category'];
        $this->mockCmsCategory->description = [$this->id_lang => 'CMS Category Description'];
        $this->mockCmsCategory->link_rewrite = [$this->id_lang => 'test-cms-category'];
        $this->mockCmsCategory->meta_title = [$this->id_lang => 'CMS Cat Meta Title'];
        $this->mockCmsCategory->meta_description = [$this->id_lang => 'CMS Cat Meta Desc'];
        $this->mockCmsCategory->meta_keywords = [$this->id_lang => 'cms, category, test'];
        $this->mockCmsCategory->active = true;
        $this->mockCmsCategory->position = 1;
        $this->mockCmsCategory->id_parent = 0;
        $this->mockCmsCategory->level_depth = 1;
        $this->mockCmsCategory->date_add = '2023-03-01 10:00:00';
        $this->mockCmsCategory->date_upd = '2023-03-01 11:00:00';
    }

    public function testToArrayBasicFields()
    {
        $rto = new CmsCategoryRTO($this->mockCmsCategory, $this->id_lang);
        $arrayData = $rto->toArray();

        $this->assertEquals(20, $arrayData['id_cms_category']);
        $this->assertEquals('Test CMS Category', $arrayData['name']);
        $this->assertEquals('CMS Category Description', $arrayData['description']);
        $this->assertEquals('test-cms-category', $arrayData['link_rewrite']);
        $this->assertTrue($arrayData['active']);
        $this->assertEquals("http://mockshop.com/cms-category/20-test-cms-category", $arrayData['category_url']);
    }

    public function testToArrayWithPages()
    {
        // The mock for CMSCategory::getCMSPages is set up in setUpBeforeClass
        // to return pages for id_cms_category == 20.
        $cmsCatForTest = new CMSCategory(20, $this->id_lang); // Instantiate the mock CMSCategory
        // Populate fields for the RTO if they are not automatically set by mock constructor
        $cmsCatForTest->name = [$this->id_lang => 'Test CMS Category With Pages'];
        $cmsCatForTest->link_rewrite = [$this->id_lang => 'test-cms-cat-pages'];
        // ... other necessary fields for CmsCategoryRTO ...
        $cmsCatForTest->active = true;


        $rto = new CmsCategoryRTO($cmsCatForTest, $this->id_lang, ['pages'], ['page_limit' => 1]);
        $arrayData = $rto->toArray();

        $this->assertArrayHasKey('pages', $arrayData);
        $this->assertCount(1, $arrayData['pages']);
        $this->assertEquals(101, $arrayData['pages'][0]['id_cms']);
        $this->assertEquals('Page 1 in Cat', $arrayData['pages'][0]['meta_title']);
        $this->assertEquals('http://mockshop.com/cms/101-page-1-in-cat', $arrayData['pages'][0]['page_url']);
    }
}
