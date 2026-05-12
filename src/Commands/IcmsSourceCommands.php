<?php

namespace Drupal\pagedesigner_export\Commands;

use Drupal\pagedesigner_export\Service\SourceExporter;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for ICMS source migration exports.
 */
class IcmsSourceCommands extends DrushCommands {

  /**
   * Constructs the command class.
   */
  public function __construct(
    protected SourceExporter $sourceExporter,
  ) {}

  /**
   * Print a source inventory of nodes that use PageDesigner fields.
   *
   * @command icms-source:inventory
   * @aliases icms-source-inventory
   * @option bundles
   *   Comma-separated source node bundles to include.
   * @option nids
   *   Comma-separated source node IDs to include.
   * @option fields
   *   Comma-separated PageDesigner field names to include.
   * @option limit
   *   Limit nodes per bundle.
   */
  public function inventory(
    array $options = [
      'bundles' => NULL,
      'nids' => NULL,
      'fields' => NULL,
      'limit' => NULL,
    ],
  ): void {
    $inventory = $this->sourceExporter->inventory($this->normalizeOptions($options));
    $this->output()->writeln(json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  /**
   * Export source inventory and PageDesigner trees to a directory.
   *
   * @command icms-source:export
   * @aliases icms-source-export
   * @option output
   *   Output directory. Supports stream-wrapper paths such as private://.
   * @option bundles
   *   Comma-separated source node bundles to include.
   * @option nids
   *   Comma-separated source node IDs to include.
   * @option fields
   *   Comma-separated PageDesigner field names to include.
   * @option limit
   *   Limit nodes per bundle.
   * @option langcode
   *   Default language code for PageDesigner tree exports.
   * @option sanitize-local-urls
   *   Convert local absolute URLs to relative paths. Default: TRUE.
   */
  public function export(
    array $options = [
      'output' => NULL,
      'bundles' => NULL,
      'nids' => NULL,
      'fields' => NULL,
      'limit' => NULL,
      'langcode' => NULL,
      'sanitize-local-urls' => TRUE,
    ],
  ): void {
    if (empty($options['output'])) {
      throw new \InvalidArgumentException('The --output option is required.');
    }

    $exportOptions = $this->normalizeOptions($options);
    $exportOptions['sanitize_local_urls'] = $this->optionToBool($options['sanitize-local-urls'] ?? TRUE);
    $manifest = $this->sourceExporter->export($options['output'], $exportOptions);

    $this->logger()->success("Exported ICMS source package to {$options['output']}");
    $this->output()->writeln(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  /**
   * Normalize command options.
   */
  protected function normalizeOptions(array $options): array {
    return [
      'bundles' => $this->optionList($options['bundles'] ?? NULL),
      'nids' => $this->optionList($options['nids'] ?? NULL),
      'fields' => $this->optionList($options['fields'] ?? NULL),
      'limit' => isset($options['limit']) && $options['limit'] !== NULL && $options['limit'] !== '' ? (int) $options['limit'] : NULL,
      'langcode' => $options['langcode'] ?? NULL,
    ];
  }

  /**
   * Normalize a comma-separated option.
   */
  protected function optionList(mixed $value): ?array {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_array($value)) {
      return $value;
    }
    return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
  }

  /**
   * Normalize a mixed Drush option into boolean.
   */
  protected function optionToBool(mixed $value): bool {
    if (is_bool($value)) {
      return $value;
    }
    if (is_int($value)) {
      return $value !== 0;
    }
    if (is_string($value)) {
      $normalized = strtolower(trim($value));
      if (in_array($normalized, ['0', 'false', 'no', 'off'], TRUE)) {
        return FALSE;
      }
      if (in_array($normalized, ['1', 'true', 'yes', 'on'], TRUE)) {
        return TRUE;
      }
    }
    return (bool) $value;
  }

}
