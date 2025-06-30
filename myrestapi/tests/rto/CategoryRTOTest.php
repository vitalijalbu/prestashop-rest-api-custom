<?php
namespace MyRestApi\Tests\Rto;

use MyRestApi\Rto\CategoryRTO;
use PHPUnit\Framework\TestCase;
use Category; // Mocked or real PrestaShop class
use Language; // Mocked
use Link;     // Mocked
use Configuration; // Mocked
use Context;  // Mocked
use Product;  // Mocked

class CategoryRTOTest extends TestCase
{
    private $mockCategory;
    private $mockLink;
    private $id_lang = 1;

    public static function setUpBeforeClass(): void
    {
        // Ensure necessary mocks are available from bootstrap or defined here
        if (!class_exists('Link')) {
            class Link { // Simple mock
                public function getCatImageLink($name, $id_image, $type = null) { return "http://mockshop.com/img/c/{$id_image}-{$type}.jpg"; }
                public function getCategoryLink($category, $alias = null, $id_lang = null, $id_shop = null) { return "http://mockshop.com/category/{$category->id}-{$category->link_rewrite[$id_lang]}"; }
            }
        }
         if (!class_exists('Product')) { // For ProductRTO if used by CategoryRTO
            class Product {
                public $id;
                public $name;
                public $link_rewrite;
                public function __construct($id = null, $full = false, $id_lang = null, $id_shop = null, $context = null) { $this->id = $id; }
                public static function getPriceStatic($id_product, $usetax = true, $id_product_attribute = null, $decimals = 6, $divisor = null, $only_reduc = false, $usereduc = true, $quantity = 1, $force_associated_tax = false, $id_customer = null, $id_cart = null, $id_address = null, &$specific_price_output = null, $with_ecotax = true, $use_group_reduction = true, Context $context = null, $use_customer_price = true) { return 10.0; }
            }
        }
        if (!class_exists('MyRestApi\Rto\ProductRTO')) { // Mock ProductRTO if CategoryRTO uses it
            class ProductRTO {
                private $product; private $id_lang; private $include;
                public function __construct(Product $product, int $id_lang, array $includeOptions = []) {
                    $this->product = $product; $this->id_lang = $id_lang; $this->include = $includeOptions;
                }
                public function toArray() { return ['id_product' => $this->product->id, 'name' => 'Mock Product ' . $this->product->id, 'price' => 10.0]; }
            }
            // Need to ensure this class is autoloadable if it's not in the same namespace for real test.
            // For this conceptual test, defining it inline is fine.
        }


        Configuration::set('PS_LANG_DEFAULT', 1);
        Configuration::set('PS_SHOP_DEFAULT', 1);
    }


    protected function setUp(): void
    {
        $this->id_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $this->mockLink = new Link(); // Use the mocked Link

        $mockContext = $this->createMock(Context::class);
        $mockContext->link = $this->mockLink;
        $mockContext->language = (object)['id' => $this->id_lang];
        $mockContext->shop = (object)['id' => 1];

        // It's tricky to mock static 'getContext' if it's already been called by other tests.
        // For now, we rely on bootstrap's simple Context mock or a more robust solution.
        // Context::setInstanceForTesting($mockContext); // If Context had such a method

        $this->mockCategory = $this->createMock(Category::class);
        $this->mockCategory->id = 5;
        $this->mockCategory->name = [$this->id_lang => 'Test Category RTO', (int)Configuration::get('PS_LANG_DEFAULT') => 'Test Category RTO'];
        $this->mockCategory->link_rewrite = [$this->id_lang => 'test-category-rto', (int)Configuration::get('PS_LANG_DEFAULT') => 'test-category-rto'];
        $this->mockCategory->description = [$this->id_lang => 'Description here', (int)Configuration::get('PS_LANG_DEFAULT') => 'Description here'];
        $this->mockCategory->id_parent = 2;
        $this->mockCategory->active = true;
        $this->mockCategory->id_image = 5; // Assuming an image exists
        $this->mockCategory->level_depth = 2;
        $this->mockCategory->is_root_category = false;
        $this->mockCategory->meta_title = [$this->id_lang => 'Meta Title'];
        $this->mockCategory->meta_description = [$this->id_lang => 'Meta Desc'];
        $this->mockCategory->meta_keywords = [$this->id_lang => 'kw1,kw2'];
        $this->mockCategory->date_add = '2023-01-01 10:00:00';
        $this->mockCategory->date_upd = '2023-01-01 11:00:00';

        // Mock methods
        $this->mockCategory->method('getSubCategories')->willReturn([]);
        $this->mockCategory->method('getProducts')->willReturn([]); // For products_count and actual products
                                                     // Note: getProducts has many params, mock carefully.
                                                     // For count: getProducts(null, null, null, null, null, true)
                                                     // For list:  getProducts($id_lang, $p, $n, $orderBy, $orderWay)

    }

    public function testToArrayBasicFields()
    {
        $rto = new CategoryRTO($this->mockCategory, $this->id_lang);
        $arrayData = $rto->toArray();

        $this->assertEquals(5, $arrayData['id_category']);
        $this->assertEquals('Test Category RTO', $arrayData['name']);
        $this->assertEquals('test-category-rto', $arrayData['link_rewrite']);
        $this->assertEquals('Description here', $arrayData['description']);
        $this->assertEquals(2, $arrayData['id_parent']);
        $this->assertTrue($arrayData['active']);
        $this->assertEquals("http://mockshop.com/img/c/5-category_default.jpg", $arrayData['image_url']);
        $this->assertEquals("http://mockshop.com/category/5-test-category-rto", $arrayData['category_url']);
    }

    public function testToArrayWithSubcategories()
    {
        $subCatMock = $this->createMock(Category::class);
        $subCatMock->id = 6;
        $subCatMock->name = [$this->id_lang => 'SubCategory 1'];
        $subCatMock->link_rewrite = [$this->id_lang => 'subcategory-1'];
        $subCatMock->id_image = 0; // No image
        $subCatMock->active = true;
        $subCatMock->method('getSubCategories')->willReturn([]); // No further nesting for this test
        $subCatMock->method('getProducts')->willReturn([]);


        $this->mockCategory->method('getSubCategories')->willReturn([
            ['id_category' => 6] // Data format from Category::getSubCategories
        ]);

        // Need to ensure the Category constructor is not called with ID 6 by the RTO itself,
        // or that such calls also return a mock. This is where DI for object creation helps.
        // For now, this test is conceptual for the structure.
        // A better mock for getSubCategories would return an array of actual mocked Category objects if RTO expects that.
        // The current CategoryRTO re-instantiates Category: new Category($subcat_data['id_category'], $this->id_lang);
        // This makes true unit testing harder without a service container or factory.

        $this->markTestSkipped('Testing subcategories requires deeper mocking of Category instantiation within RTO or integration test.');

        /*
        $rto = new CategoryRTO($this->mockCategory, $this->id_lang, ['subcategories']);
        $arrayData = $rto->toArray();

        $this->assertArrayHasKey('subcategories', $arrayData);
        $this->assertCount(1, $arrayData['subcategories']);
        $this->assertEquals(6, $arrayData['subcategories'][0]['id_category']);
        $this->assertEquals('SubCategory 1', $arrayData['subcategories'][0]['name']);
        */
    }

    public function testToArrayWithProductsSimple()
    {
        $this->mockCategory->method('getProducts')
            // ->with($this->id_lang, 1, 10, 'position', 'ASC') // For fetching product list
            ->willReturn([ // Simplified product data as returned by Category::getProducts
                ['id_product' => 101, 'name' => 'Product A', 'reference' => 'REF_A', 'link_rewrite' => 'product-a', 'id_image' => '101'],
                ['id_product' => 102, 'name' => 'Product B', 'reference' => 'REF_B', 'link_rewrite' => 'product-b', 'id_image' => '102'],
            ]);

        $rto = new CategoryRTO($this->mockCategory, $this->id_lang, ['products'], ['product_limit' => 2]);
        $arrayData = $rto->toArray();

        $this->assertArrayHasKey('products', $arrayData);
        $this->assertCount(2, $arrayData['products']);
        $this->assertEquals(101, $arrayData['products'][0]['id_product']);
        $this->assertEquals('Product A', $arrayData['products'][0]['name']);
        $this->assertEquals(10.0, $arrayData['products'][0]['price']); // From mocked Product::getPriceStatic
        $this->assertStringContainsString('product-a', $arrayData['products'][0]['product_url']);
        $this->assertStringContainsString('101-home_default.jpg', $arrayData['products'][0]['main_image_url']);
    }

     public function testToArrayWithProductDetails()
    {
        // This would require ProductRTO to be fully testable or mocked,
        // and Category::getProducts to return data that can hydrate a mock Product object.
        $this->markTestSkipped('Testing with product_details requires ProductRTO and deeper Product object mocking.');
    }


}
