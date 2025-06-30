<?php
namespace MyRestApi\Tests\Rto;

use MyRestApi\Rto\CmsPageRTO;
use PHPUnit\Framework\TestCase;
use CMS; // Mocked or real PrestaShop class
use Language;
use Link;
use Configuration;
use Context;

class CmsPageRTOTest extends TestCase
{
    private $mockCmsPage;
    private $mockLink;
    private $id_lang = 1;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('Link')) {
            class Link {
                public function getCMSLink($cms, $alias = null, $ssl = false, $id_lang = null, $id_shop = null) {
                    $id = is_object($cms) ? $cms->id : (int)$cms;
                    $lr = is_object($cms) && isset($cms->link_rewrite[$id_lang]) ? $cms->link_rewrite[$id_lang] : 'cms-page';
                    return "http://mockshop.com/cms/{$id}-{$lr}";
                }
            }
        }
        Configuration::set('PS_LANG_DEFAULT', 1);
    }

    protected function setUp(): void
    {
        $this->id_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $this->mockLink = new Link(); // Use the mocked Link

        $mockContext = $this->createMock(Context::class);
        $mockContext->link = $this->mockLink;
        $mockContext->language = (object)['id' => $this->id_lang];
        // Context::setInstanceForTesting($mockContext); // If available

        $this->mockCmsPage = $this->createMock(CMS::class);
        $this->mockCmsPage->id = 10;
        $this->mockCmsPage->id_cms_category = 1;
        $this->mockCmsPage->position = 1;
        $this->mockCmsPage->active = true;
        $this->mockCmsPage->indexation = true; // Property name is 'indexation'

        // Mocking multilang fields
        $this->mockCmsPage->meta_title = [$this->id_lang => 'Test CMS Page Title'];
        $this->mockCmsPage->meta_description = [$this->id_lang => 'Test CMS Page Meta Description'];
        $this->mockCmsPage->meta_keywords = [$this->id_lang => 'cms, test, page'];
        $this->mockCmsPage->content = [$this->id_lang => '<p>This is test CMS content.</p>'];
        $this->mockCmsPage->link_rewrite = [$this->id_lang => 'test-cms-page'];

        $this->mockCmsPage->date_add = '2023-02-01 10:00:00';
        $this->mockCmsPage->date_upd = '2023-02-01 11:00:00';
    }

    public function testToArrayBasicFields()
    {
        // If CmsPageRTO's getMultilangField expects $this->cmsPage->{$field} to be the direct value
        // for the current language (as CMS object often loads itself for a specific lang),
        // then the mock setup needs to reflect that, e.g. $this->mockCmsPage->meta_title = 'Test CMS Page Title';
        // The current RTO tries $this->cmsPage->{$field}[$this->id_lang] first.

        $rto = new CmsPageRTO($this->mockCmsPage, $this->id_lang);
        $arrayData = $rto->toArray();

        $this->assertEquals(10, $arrayData['id_cms']);
        $this->assertEquals(1, $arrayData['id_cms_category']);
        $this->assertTrue($arrayData['active']);
        $this->assertTrue($arrayData['indexed']);
        $this->assertEquals('Test CMS Page Title', $arrayData['meta_title']);
        $this->assertEquals('Test CMS Page Meta Description', $arrayData['meta_description']);
        $this->assertEquals('cms, test, page', $arrayData['meta_keywords']);
        $this->assertEquals('<p>This is test CMS content.</p>', $arrayData['content']);
        $this->assertEquals('test-cms-page', $arrayData['link_rewrite']);
        $this->assertEquals("http://mockshop.com/cms/10-test-cms-page", $arrayData['page_url']);
        $this->assertEquals('2023-02-01 10:00:00', $arrayData['date_add']);
        $this->assertEquals('2023-02-01 11:00:00', $arrayData['date_upd']);
    }
}
