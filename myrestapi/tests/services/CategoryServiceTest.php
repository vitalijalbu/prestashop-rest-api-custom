<?php
namespace MyRestApi\Tests\Services;

use MyRestApi\Services\CategoryService;
use MyRestApi\Dto\CategoryDTO;
use PHPUnit\Framework\TestCase;
use Category; // Mocked
use Db;      // Mocked
use Validate; // Mocked or from bootstrap
use Configuration; // From bootstrap
use Context; // From bootstrap
use Language; // From bootstrap
use ImageManager; // Mocked
use ImageType; // Mocked

class CategoryServiceTest extends TestCase
{
    private $categoryService;

    public static function setUpBeforeClass(): void
    {
        // Ensure basic mocks from bootstrap are loaded
        if (!class_exists('Validate')) { class Validate { public static function isLoadedObject($obj) { return $obj && $obj->id > 0;} public static function isUnsignedId($id){return true;} public static function isCatalogName($name){return true;} public static function isLinkRewrite($lr){return true;} } }
        if (!class_exists('Category')) {
            class Category {
                public $id; public $active = true; public $id_parent; public $id_shop_list;
                public $name; public $link_rewrite; /* other fields */
                public function __construct($id = null, $id_lang = null, $id_shop = null, $context = null) { if($id) $this->id = $id; }
                public function add($auto_date = true, $null_values = false) { $this->id = $this->id ?: rand(1,1000); return true; }
                public function update($null_values = false) { return true; }
                public function delete() { return true; }
                public function deleteImage($force_delete = false) { return true; }
                public static function getRootCategory($id_lang = null, $shop = null) { $c = new self(2); $c->id_parent = 1; return $c; /* Home Cat */}
                public static function getTopCategory() { $c = new self(1); $c->id_parent = 0; return $c; /* Root Cat */}

            }
        }
        if (!class_exists('ImageManager')) { class ImageManager { public static function resize($src, $dst, $w=null, $h=null, $type='jpg', $force_type=false, &$error=0, &$tgt_width=null, &$tgt_height=null, $quality=5, $src_width=null, $src_height=null){ return true; } } }
        if (!class_exists('ImageType')) { class ImageType { public static function getImagesTypes($type='products'){ return [['name' => 'small_default', 'width'=>50, 'height'=>50]];} } }

        Configuration::set('PS_HOME_CATEGORY', 2);
        Configuration::set('PS_ROOT_CATEGORY', 1);

    }

    protected function setUp(): void
    {
        $this->categoryService = new CategoryService();
        // Similar to ProductServiceTest, Db mocking is complex for true unit tests.
    }

    public function testGetByIdFound()
    {
        $this->markTestSkipped('CategoryService::getById test requires deeper mocking of Category instantiation or DB interaction.');
    }

    public function testCreateCategorySuccess()
    {
        $dtoData = [
            'name' => [1 => 'Test Category Create Service'],
            'active' => true,
            'id_parent' => Configuration::get('PS_HOME_CATEGORY'),
            'link_rewrite' => [1 => 'test-cat-create-service'],
        ];
        $categoryDTO = CategoryDTO::fromArray($dtoData);

        $result = $this->categoryService->create($categoryDTO);

        $this->assertInstanceOf(Category::class, $result, "Service should return a Category object on success.");
        $this->assertGreaterThan(0, $result->id, "Created category should have an ID.");
        $this->assertEquals('Test Category Create Service', $result->name[1]);
    }

    public function testCreateCategoryValidationError()
    {
        $dtoData = ['active' => true]; // Missing name
        $categoryDTO = CategoryDTO::fromArray($dtoData);

        $result = $this->categoryService->create($categoryDTO);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Category name is required', $result['errors'][0]);
    }

    public function testDeleteRootCategoryForbidden()
    {
        $result = $this->categoryService->delete((int)Configuration::get('PS_ROOT_CATEGORY'));
        $this->assertIsArray($result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('Cannot delete root or home category.', $result['errors'][0]);

        $result = $this->categoryService->delete((int)Configuration::get('PS_HOME_CATEGORY'));
        $this->assertIsArray($result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('Cannot delete root or home category.', $result['errors'][0]);
    }

    // TODO: Tests for getList, update, delete (non-root), image handling
    // These require more extensive mocking.
}
