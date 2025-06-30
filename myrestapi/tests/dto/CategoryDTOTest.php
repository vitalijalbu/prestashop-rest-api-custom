<?php
namespace MyRestApi\Tests\Dto;

use MyRestApi\Dto\CategoryDTO;
use PHPUnit\Framework\TestCase;
use Configuration; // Mocked in bootstrap
use Language;      // Mocked in bootstrap (if not already)
use Validate;      // Mocked in bootstrap (if not already)
use Tools;         // Mocked in bootstrap (if not already)

class CategoryDTOTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Ensure Configuration, Language, Validate, Tools mocks are available via bootstrap
        if (!class_exists('Language')) {
            class Language { // Simple mock
                public static function getLanguages($active = true, $id_shop = false, $ids_only = false) {
                    return [
                        ['id_lang' => 1, 'iso_code' => 'en'],
                        ['id_lang' => 2, 'iso_code' => 'fr']
                    ];
                }
            }
        }
        if (!class_exists('Validate')) {
            class Validate { // Simple mock
                public static function isCatalogName($name) { return is_string($name) && strlen($name) > 0 && strlen($name) < 129; }
                public static function isLinkRewrite($link) { return is_string($link) && preg_match('/^[a-zA-Z0-9-]+$/', $link); }
                public static function isUnsignedId($id) { return is_numeric($id) && $id >= 0; }
                public static function isGenericName($name) { return is_string($name) && strlen($name) < 129; }

            }
        }
        if (!class_exists('Tools')) {
            class Tools { // Simple mock
                public static function linkRewrite($str, $utf8_decode = false) {
                    $str = strtolower(trim($str));
                    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
                    return $str;
                }
            }
        }
        Configuration::set('PS_LANG_DEFAULT', 1);
    }

    public function testFromArrayMinimalData()
    {
        $data = [
            'name' => ['en' => 'Test Category EN', 'fr' => 'Test Catégorie FR'],
            'id_parent' => 2,
            'active' => true,
        ];
        $dto = CategoryDTO::fromArray($data);

        $this->assertEquals('Test Category EN', $dto->name[1]);
        $this->assertEquals('Test Catégorie FR', $dto->name[2]);
        $this->assertEquals(2, $dto->id_parent);
        $this->assertTrue($dto->active);
    }

    public function testFromArrayAllData()
    {
        $data = [
            'name' => ['en' => 'Full Category', 'fr' => 'Catégorie Complète'],
            'description' => ['en' => 'Desc EN', 'fr' => 'Desc FR'],
            'link_rewrite' => ['en' => 'full-cat', 'fr' => 'cat-complete'],
            'meta_title' => ['en' => 'Meta Title EN', 'fr' => 'Meta Titre FR'],
            'meta_description' => ['en' => 'Meta Desc EN', 'fr' => 'Meta Desc FR'],
            'meta_keywords' => ['en' => 'kw1, kw2', 'fr' => 'mc1, mc2'],
            'id_parent' => 3,
            'active' => false,
            'image_data' => ['filename' => 'test.jpg', 'base64_content' => 'base64string'],
            'image_legend' => ['en' => 'Legend EN', 'fr' => 'Légende FR'],
        ];
        $dto = CategoryDTO::fromArray($data);

        $this->assertEquals('Full Category', $dto->name[1]);
        $this->assertEquals('Desc EN', $dto->description[1]);
        $this->assertEquals('full-cat', $dto->link_rewrite[1]);
        $this->assertEquals('Meta Title EN', $dto->meta_title[1]);
        $this->assertEquals('Meta Desc EN', $dto->meta_description[1]);
        $this->assertEquals('kw1, kw2', $dto->meta_keywords[1]);
        $this->assertEquals(3, $dto->id_parent);
        $this->assertFalse($dto->active);
        $this->assertEquals(['filename' => 'test.jpg', 'base64_content' => 'base64string'], $dto->image_data);
        $this->assertEquals('Legend EN', $dto->image_legend[1]);
    }

    public function testValidateSuccess()
    {
        $data = [
            'name' => ['en' => 'Valid Name', 'fr' => 'Nom Valide'],
            'id_parent' => 1,
            'link_rewrite' => ['en' => 'valid-name'],
            'active' => true,
        ];
        $dto = CategoryDTO::fromArray($data);
        $errors = $dto->validate();
        $this->assertEmpty($errors);
    }

    public function testValidateMissingName()
    {
        $data = [
            'id_parent' => 1,
            'active' => true,
        ];
        $dto = CategoryDTO::fromArray($data); // Name will be empty for default lang
        $errors = $dto->validate();
        $this->assertNotEmpty($errors);
        $this->assertContains('Category name is required for the default language.', $errors);
    }

    public function testValidateInvalidLinkRewrite()
    {
        $data = [
            'name' => ['en' => 'Valid Name'],
            'id_parent' => 1,
            'link_rewrite' => ['en' => 'invalid link rewrite with spaces'],
        ];
        $dto = CategoryDTO::fromArray($data);
        $errors = $dto->validate();
        $this->assertNotEmpty($errors);
        $this->assertContains('Invalid link_rewrite for language ISO: en', $errors);
    }

    public function testValidateInvalidImageData()
    {
        $data = [
            'name' => ['en' => 'Category with Image'],
            'id_parent' => 1,
            'image_data' => ['base64_content' => 'test'], // Missing filename
        ];
        $dto = CategoryDTO::fromArray($data);
        $errors = $dto->validate();
        $this->assertNotEmpty($errors);
        $this->assertContains('Image data must be an array with "filename" and "base64_content" if provided.', $errors);

        $data['image_data'] = ['filename' => 'test.txt', 'base64_content' => 'testcontent']; // Invalid extension
        $dto = CategoryDTO::fromArray($data);
        $errors = $dto->validate();
        $this->assertNotEmpty($errors);
        $this->assertContains('Invalid image filename or extension (only jpg, jpeg, png, gif allowed).', $errors);
    }

    public function testHydrateCategory()
    {
        // This test is more of an integration test if Category class is not mocked.
        // For a unit test, Category class itself should be a mock to verify setters.
        $this->markTestSkipped('HydrateCategory test requires mocking PrestaShop Category class or is an integration test.');

        /*
        $data = [
            'name' => ['en' => 'Hydrate Test', 'fr' => 'Test Hydratation'],
            'id_parent' => 2,
            'active' => true,
            'link_rewrite' => ['en' => 'hydrate-test'],
        ];
        $dto = CategoryDTO::fromArray($data);
        $mockCategory = $this->createMock(\Category::class); // PHPUnit's way to mock

        // Expect setters to be called
        $mockCategory->expects($this->once())->method('__set')->with('id_parent', 2);
        // ... more expectations

        $dto->hydrateCategory($mockCategory);
        // No direct assertion here, the expectations on the mock serve as assertions.
        */
    }
}
