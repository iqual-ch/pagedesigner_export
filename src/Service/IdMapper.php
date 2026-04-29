<?php

namespace Drupal\pagedesigner_export\Service;

/**
 * Helper service to track ID mappings during import with new IDs.
 */
class IdMapper {

  /**
   * Map of old IDs to new IDs: [old_id => new_id].
   *
   * @var array
   */
  protected array $map = [];

  /**
   * Add a mapping from old ID to new ID.
   *
   * @param int $oldId
   *   The old element ID.
   * @param int $newId
   *   The new element ID.
   */
  public function addMapping(int $oldId, int $newId): void {
    $this->map[$oldId] = $newId;
  }

  /**
   * Get the new ID for an old ID.
   *
   * @param int $oldId
   *   The old element ID.
   *
   * @return int|null
   *   The new element ID, or NULL if not mapped.
   */
  public function getNewId(int $oldId): ?int {
    return $this->map[$oldId] ?? NULL;
  }

  /**
   * Check if an old ID has been mapped.
   *
   * @param int $oldId
   *   The old element ID.
   *
   * @return bool
   *   TRUE if mapped, FALSE otherwise.
   */
  public function hasMapped(int $oldId): bool {
    return isset($this->map[$oldId]);
  }

  /**
   * Get all mappings.
   *
   * @return array
   *   The ID map.
   */
  public function getMap(): array {
    return $this->map;
  }

  /**
   * Reset the mapper.
   */
  public function reset(): void {
    $this->map = [];
  }

}
