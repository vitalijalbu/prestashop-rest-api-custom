<?php
namespace MyRestApi\Core;

use Context;
use Language;
use Shop;
use Configuration;

abstract class AbstractResourceService implements ResourceServiceInterface
{
    protected $context;
    protected $id_lang;
    protected $id_shop;

    /**
     * The PrestaShop ObjectModel class name (e.g., 'Product', 'Category').
     * To be defined in child classes.
     * @var string
     */
    protected $resourceClass;

    /**
     * The DTO class name for this resource.
     * To be defined in child classes.
     * @var string
     */
    protected $dtoClass;

    /**
     * The RTO class name for this resource.
     * To be defined in child classes.
     * @var string
     */
    protected $rtoClass;


    public function __construct()
    {
        $this->context = Context::getContext();
        $this->id_lang = $this->context->language->id;
        $this->id_shop = $this->context->shop->id;

        if (empty($this->resourceClass)) {
            throw new \LogicException(get_called_class() . ' must have a $resourceClass');
        }
        if (empty($this->dtoClass)) {
            throw new \LogicException(get_called_class() . ' must have a $dtoClass');
        }
        if (empty($this->rtoClass)) {
            throw new \LogicException(get_called_class() . ' must have a $rtoClass');
        }
    }

    public function getRtoClass(): string
    {
        return $this->rtoClass;
    }

    public function getDtoClass(): string
    {
        return $this->dtoClass;
    }

    // Abstract methods to be implemented by child services
    abstract public function getList(array $filters, array $sort, int $page, int $limit): array;
    abstract public function getById(int $id);
    abstract public function create(object $dto);
    abstract public function update(int $id, object $dto);
    abstract public function delete(int $id);

    /**
     * Helper method to get a new instance of the resource's ObjectModel.
     * @param int|null $id
     * @return \ObjectModel|\ObjectModelCore|null
     */
    protected function getResourceInstance(int $id = null)
    {
        if (!class_exists($this->resourceClass)) {
            throw new \RuntimeException("Resource class {$this->resourceClass} not found.");
        }
        // For some objects, context might be needed in constructor or language/shop ID
        if ($this->resourceClass === 'Product' || $this->resourceClass === 'Category' || $this->resourceClass === 'CMS' || $this->resourceClass === 'CMSCategory' || $this->resourceClass === 'Manufacturer' || $this->resourceClass === 'Supplier') {
             return new $this->resourceClass($id, false, $this->id_lang, $this->id_shop, $this->context);
        }
        return new $this->resourceClass($id, $this->id_lang, $this->id_shop);
    }

    /**
     * Helper method to get a new instance of the resource's DTO from an array.
     * @param array $data
     * @return object instance of $this->dtoClass
     */
    protected function createDtoFromArray(array $data): object
    {
        if (!method_exists($this->dtoClass, 'fromArray')) {
            throw new \LogicException("DTO class {$this->dtoClass} must have a static fromArray method.");
        }
        return call_user_func([$this->dtoClass, 'fromArray'], $data);
    }

    /**
     * Helper method to validate a DTO.
     * @param object $dto
     * @return array List of validation errors. Empty if valid.
     */
    protected function validateDto(object $dto): array
    {
        if (!method_exists($dto, 'validate')) {
            throw new \LogicException("DTO class " . get_class($dto) . " must have a validate method.");
        }
        return $dto->validate();
    }

    /**
     * Helper method to hydrate a PrestaShop ObjectModel from a DTO.
     * The DTO must have a method like `hydrateObjectModelName(ObjectModel $object)`.
     * Example: ProductDTO should have `hydrateProduct(Product $product)`.
     * @param object $dto The DTO instance
     * @param \ObjectModel $objectModel The ObjectModel instance to hydrate
     */
    protected function hydrateObjectModelFromDto(object $dto, \ObjectModel $objectModel): void
    {
        $resourceName = (new \ReflectionClass($this->resourceClass))->getShortName();
        $hydrateMethod = 'hydrate' . $resourceName; // e.g., hydrateProduct, hydrateCategory

        if (!method_exists($dto, $hydrateMethod)) {
            throw new \LogicException("DTO class " . get_class($dto) . " must have a {$hydrateMethod} method.");
        }
        $dto->$hydrateMethod($objectModel);
    }
}
