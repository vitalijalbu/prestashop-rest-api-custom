<?php
namespace MyRestApi\Core;

interface ResourceServiceInterface
{
    /**
     * Get a list of resources with pagination, filtering, and sorting.
     * @param array $filters Associative array of filters (e.g., ['active' => 1, 'name' => 'test'])
     * @param array $sort Associative array for sorting (e.g., ['name' => 'ASC'])
     * @param int $page Current page number
     * @param int $limit Number of items per page
     * @return array ['data' => array, 'total' => int]
     */
    public function getList(array $filters, array $sort, int $page, int $limit): array;

    /**
     * Get a single resource by its ID.
     * @param int $id
     * @return object|null The resource object or null if not found
     */
    public function getById(int $id);

    /**
     * Create a new resource.
     * @param object $dto Data Transfer Object containing resource data.
     * @return object|array The created resource object or an array of errors.
     */
    public function create(object $dto);

    /**
     * Update an existing resource.
     * @param int $id The ID of the resource to update.
     * @param object $dto Data Transfer Object containing resource data for update.
     * @return object|array The updated resource object or an array of errors.
     */
    public function update(int $id, object $dto);

    /**
     * Delete a resource by its ID.
     * @param int $id
     * @return bool|array True on success, or an array of errors.
     */
    public function delete(int $id);

    /**
     * Get the RTO class name associated with this service's resource.
     * @return string
     */
    public function getRtoClass(): string;

    /**
     * Get the DTO class name associated with this service's resource for create/update.
     * @return string
     */
    public function getDtoClass(): string;
}
