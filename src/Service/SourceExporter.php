<?php

namespace Drupal\pagedesigner_export\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Exports source-site content metadata and PageDesigner trees for migration.
 */
class SourceExporter {

  /**
   * Constructs the SourceExporter service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected LanguageManagerInterface $languageManager,
    protected AliasManagerInterface $aliasManager,
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    protected Exporter $pagedesignerExporter,
  ) {}

  /**
   * Build an inventory of nodes that use PageDesigner fields.
   *
   * @param array $options
   *   Supported options:
   *   - bundles: string[]|null
   *   - fields: string[]|null
   *   - limit: int|null
   *
   * @return array
   *   Inventory data.
   */
  public function inventory(array $options = []): array {
    $bundleFilter = $this->normalizeList($options['bundles'] ?? NULL);
    $fieldFilter = $this->normalizeList($options['fields'] ?? NULL);
    $limit = isset($options['limit']) && $options['limit'] !== NULL ? (int) $options['limit'] : NULL;

    $pagedesignerFieldsByBundle = $this->getPagedesignerFieldsByBundle($bundleFilter, $fieldFilter);
    $allLangcodes = array_keys($this->languageManager->getLanguages());
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $rows = [];
    $stats = [
      'nodeCount' => 0,
      'translationCount' => 0,
      'pagedesignerRootCount' => 0,
      'uniquePagedesignerRootCount' => 0,
      'bundles' => [],
      'languages' => [],
    ];
    $uniqueRoots = [];

    foreach ($pagedesignerFieldsByBundle as $bundle => $fieldNames) {
      $query = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->sort('nid');

      if ($limit) {
        $query->range(0, $limit);
      }

      $nids = $query->execute();
      if (!$nids) {
        continue;
      }

      foreach (array_chunk(array_values($nids), 50) as $chunk) {
        $nodes = $nodeStorage->loadMultiple($chunk);
        foreach ($nodes as $node) {
          if (!$node instanceof NodeInterface) {
            continue;
          }

          $row = [
            'nid' => (int) $node->id(),
            'uuid' => $node->uuid(),
            'bundle' => $node->bundle(),
            'defaultLangcode' => $node->language()->getId(),
            'status' => (bool) $node->isPublished(),
            'created' => (int) $node->getCreatedTime(),
            'changed' => (int) $node->getChangedTime(),
            'pagedesignerFields' => [],
            'translations' => [],
          ];

          foreach ($allLangcodes as $langcode) {
            if (!$node->hasTranslation($langcode)) {
              continue;
            }

            $translation = $node->getTranslation($langcode);
            $translationData = [
              'langcode' => $langcode,
              'title' => $translation->label(),
              'status' => (bool) $translation->isPublished(),
              'path' => $this->aliasManager->getAliasByPath('/node/' . $node->id(), $langcode),
              'pagedesignerRoots' => [],
            ];

            foreach ($fieldNames as $fieldName) {
              if (!$translation->hasField($fieldName) || $translation->get($fieldName)->isEmpty()) {
                continue;
              }
              foreach ($translation->get($fieldName)->getValue() as $delta => $value) {
                if (empty($value['target_id'])) {
                  continue;
                }
                $root = [
                  'fieldName' => $fieldName,
                  'delta' => (int) $delta,
                  'targetId' => (int) $value['target_id'],
                ];
                $translationData['pagedesignerRoots'][] = $root;
                $row['pagedesignerFields'][$fieldName] = TRUE;
                $stats['pagedesignerRootCount']++;
                $uniqueRoots[(string) $value['target_id']] = TRUE;
              }
            }

            $row['translations'][$langcode] = $translationData;
            $stats['translationCount']++;
            $stats['languages'][$langcode] = ($stats['languages'][$langcode] ?? 0) + 1;
          }

          $row['pagedesignerFields'] = array_keys($row['pagedesignerFields']);
          sort($row['pagedesignerFields']);

          $rows[] = $row;
          $stats['nodeCount']++;
          $stats['bundles'][$node->bundle()] = ($stats['bundles'][$node->bundle()] ?? 0) + 1;
        }
      }
    }

    ksort($stats['bundles']);
    ksort($stats['languages']);
    $stats['uniquePagedesignerRootCount'] = count($uniqueRoots);

    return [
      'format' => 'icms-source-inventory-v1',
      'generatedAt' => gmdate('c'),
      'site' => [
        'name' => $this->configFactory->get('system.site')->get('name'),
        'defaultLangcode' => $this->languageManager->getDefaultLanguage()->getId(),
      ],
      'filters' => [
        'bundles' => $bundleFilter ? array_values($bundleFilter) : NULL,
        'fields' => $fieldFilter ? array_values($fieldFilter) : NULL,
        'limit' => $limit,
      ],
      'pagedesignerFieldsByBundle' => $pagedesignerFieldsByBundle,
      'stats' => $stats,
      'nodes' => $rows,
    ];
  }

  /**
   * Export a source package to a directory.
   *
   * @param string $outputDir
   *   Output directory. Stream-wrapper paths such as private:// are supported.
   * @param array $options
   *   Inventory options plus:
   *   - langcode: default langcode for PageDesigner tree exports
   *   - sanitize_local_urls: bool
   *
   * @return array
   *   Export manifest.
   */
  public function export(string $outputDir, array $options = []): array {
    $this->fileSystem->prepareDirectory(
      $outputDir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    $realOutputDir = $this->fileSystem->realpath($outputDir) ?: $outputDir;
    $pagedesignerDir = $realOutputDir . DIRECTORY_SEPARATOR . 'pagedesigner';
    $this->fileSystem->prepareDirectory(
      $pagedesignerDir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    $inventory = $this->inventory($options);
    $this->writeJson($realOutputDir . DIRECTORY_SEPARATOR . 'source-inventory.json', $inventory);

    $defaultLangcode = $options['langcode'] ?? $this->languageManager->getDefaultLanguage()->getId();
    $sanitizeLocalUrls = (bool) ($options['sanitize_local_urls'] ?? TRUE);
    $exports = [];
    $exportedRootIds = [];

    foreach ($inventory['nodes'] as $node) {
      foreach ($node['translations'] as $langcode => $translation) {
        foreach ($translation['pagedesignerRoots'] as $root) {
          $targetId = (int) $root['targetId'];
          if (isset($exportedRootIds[$targetId])) {
            continue;
          }
          $filename = 'root-' . $targetId . '.json';
          $path = $pagedesignerDir . DIRECTORY_SEPARATOR . $filename;
          $tree = $this->pagedesignerExporter->export($targetId, $defaultLangcode, $sanitizeLocalUrls);
          $this->writeJson($path, $tree);
          $exportedRootIds[$targetId] = TRUE;
          $exports[] = [
            'rootId' => $targetId,
            'file' => 'pagedesigner/' . $filename,
            'firstSeen' => [
              'nid' => $node['nid'],
              'uuid' => $node['uuid'],
              'bundle' => $node['bundle'],
              'langcode' => $langcode,
              'fieldName' => $root['fieldName'],
              'delta' => $root['delta'],
            ],
          ];
        }
      }
    }

    $manifest = [
      'format' => 'icms-source-export-v1',
      'generatedAt' => gmdate('c'),
      'site' => $inventory['site'],
      'files' => [
        'inventory' => 'source-inventory.json',
        'pagedesignerDir' => 'pagedesigner',
      ],
      'stats' => [
        'nodeCount' => $inventory['stats']['nodeCount'],
        'translationCount' => $inventory['stats']['translationCount'],
        'pagedesignerRootCount' => count($exports),
      ],
      'exports' => $exports,
    ];
    $this->writeJson($realOutputDir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);

    return $manifest;
  }

  /**
   * Find PageDesigner node fields grouped by node bundle.
   */
  protected function getPagedesignerFieldsByBundle(?array $bundleFilter = NULL, ?array $fieldFilter = NULL): array {
    $fieldMap = $this->entityFieldManager->getFieldMapByFieldType('pagedesigner_item');
    $nodeFields = $fieldMap['node'] ?? [];

    $result = [];
    foreach ($nodeFields as $fieldName => $info) {
      if ($fieldFilter && !in_array($fieldName, $fieldFilter, TRUE)) {
        continue;
      }
      foreach (array_keys($info['bundles'] ?? []) as $bundle) {
        if ($bundleFilter && !in_array($bundle, $bundleFilter, TRUE)) {
          continue;
        }
        $result[$bundle][] = $fieldName;
      }
    }

    ksort($result);
    foreach ($result as &$fields) {
      sort($fields);
    }

    return $result;
  }

  /**
   * Normalize a string/list option into a list.
   */
  protected function normalizeList(mixed $value): ?array {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_string($value)) {
      $value = explode(',', $value);
    }
    if (!is_array($value)) {
      return NULL;
    }
    $items = array_values(array_filter(array_map('trim', $value)));
    return $items ?: NULL;
  }

  /**
   * Write JSON to a path.
   */
  protected function writeJson(string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
    }
    if (file_put_contents($path, $json . PHP_EOL) === FALSE) {
      throw new \RuntimeException("Failed to write {$path}");
    }
  }

}
