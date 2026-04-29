<?php

namespace Drupal\pagedesigner_export\Commands;

use Drupal\pagedesigner_export\Service\Exporter;
use Drupal\pagedesigner_export\Service\Importer;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Pagedesigner export/import.
 */
class PagedesignerExportCommands extends DrushCommands {

  /**
   * Constructs the command class.
   *
   * @param \Drupal\pagedesigner_export\Service\Exporter $exporter
   *   The exporter service.
   * @param \Drupal\pagedesigner_export\Service\Importer $importer
   *   The importer service.
   */
  public function __construct(
    protected Exporter $exporter,
    protected Importer $importer,
  ) {}

  /**
   * Export a pagedesigner element tree to JSON.
   *
   * @param int $elementId
   *   The root element ID to export.
   * @param array $options
   *   Command options.
   *
   * @command pd:export
   * @aliases pd-export
   * @option field
   *   The pagedesigner field name (for reference in CLI; optional).
   * @option output
   *   File path to save JSON (if not provided, outputs to stdout).
   * @option langcode
   *   Default language code (default: de).
   * @option only-langcode
   *   Export only this single language per element (for overlay workflows).
   *   When set, the tree is traversed using this language's children structure
   *   and only this language's data is included in the export.
   * @option sanitize-local-urls
   *   Convert absolute local URLs (localhost, 127.0.0.1, *.ddev.site) in
   *   exported field payloads to relative paths. Default: TRUE.
   * @usage drush pd:export 242821
   *   Export element 242821 to stdout.
   * @usage drush pd:export 242821 --output=/tmp/export.json
   *   Export to file.
   * @usage drush pd:export 242821 --field=field_pd_manual_content --output=/tmp/tree.json
   *   Export with field hint and save to file.
   * @usage drush pd:export 242821 --only-langcode=fr --output=/tmp/tree-fr.json
   *   Export only the French translation.
   */
  public function export(
    int $elementId,
    array $options = [
      'field' => NULL,
      'output' => NULL,
      'langcode' => 'de',
      'only-langcode' => NULL,
      'sanitize-local-urls' => TRUE,
    ],
  ): void {
    try {
      $this->logger()->notice('Exporting element @id...', ['@id' => $elementId]);

      $sanitizeLocalUrls = $this->optionToBool($options['sanitize-local-urls'] ?? TRUE);
      $onlyLangcode = $options['only-langcode'] ?? NULL;
      $data = $this->exporter->export($elementId, $options['langcode'], $sanitizeLocalUrls, $onlyLangcode);

      $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      if (!$json) {
        throw new \Exception('JSON encoding failed: ' . json_last_error_msg());
      }

      if ($options['output']) {
        $bytes = file_put_contents($options['output'], $json);
        if ($bytes === FALSE) {
          throw new \Exception("Failed to write to file: {$options['output']}");
        }
        $this->logger()->success("Exported to {$options['output']} ({$bytes} bytes)");
      }
      else {
        $this->output()->writeln($json);
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Export failed: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Import a pagedesigner element tree from JSON.
   *
   * @param string $jsonFile
   *   Path to the JSON file to import.
   * @param array $options
   *   Command options.
   *
   * @command pd:import
   * @aliases pd-import
   * @option target-node
   *   Target node ID (required for clone mode).
   * @option target-field
   *   Target pagedesigner field name (required for clone mode).
   * @option mode
   *   Import mode: 'preserve' (default), 'clone', or 'overlay'.
   * @option skip-existing
   *   Skip elements that already exist.
   * @option dry-run
   *   Validate without writing.
   * @option target-langcode
   *   Target language code for overlay mode (required for overlay mode).
   *   The exported content will be added as this language's translation
   *   on the existing element tree.
   * @option sanitize-local-urls
   *   Convert absolute local URLs (localhost, 127.0.0.1, *.ddev.site) from
   *   imported field payloads to relative paths. Default: TRUE.
   * @usage drush pd:import /tmp/export.json
   *   Import with preserve-ids mode (exact restore).
   * @usage drush pd:import /tmp/export.json --dry-run
   *   Validate import without writing.
   * @usage drush pd:import /tmp/export.json --mode=clone --target-node=516014 --target-field=field_pd_manual_content
   *   Clone to node 516014 with new IDs.
   * @usage drush pd:import /tmp/tree-fr.json --mode=overlay --target-node=516014 --target-field=field_pd_manual_content --target-langcode=fr
   *   Overlay French translation onto existing tree of node 516014.
   */
  public function import(
    string $jsonFile,
    array $options = [
      'target-node' => NULL,
      'target-field' => NULL,
      'mode' => 'preserve',
      'skip-existing' => FALSE,
      'dry-run' => FALSE,
      'target-langcode' => NULL,
      'sanitize-local-urls' => TRUE,
    ],
  ): void {
    try {
      if (!file_exists($jsonFile)) {
        throw new \Exception("File not found: {$jsonFile}");
      }

      $json = file_get_contents($jsonFile);
      $data = json_decode($json, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Invalid JSON: ' . json_last_error_msg());
      }

      $this->logger()->notice("Importing from {$jsonFile} (mode: {$options['mode']})...");

      $importOptions = [
        'mode' => $options['mode'],
        'skip_existing' => $options['skip-existing'],
        'dry_run' => $options['dry-run'],
        'sanitize_local_urls' => $this->optionToBool($options['sanitize-local-urls'] ?? TRUE),
      ];

      if ($options['target-node']) {
        $importOptions['target_nid'] = $options['target-node'];
      }
      if ($options['target-field']) {
        $importOptions['target_field'] = $options['target-field'];
      }
      if ($options['target-langcode']) {
        $importOptions['target_langcode'] = $options['target-langcode'];
      }

      $result = $this->importer->import($data, $importOptions);

      if ($options['dry-run']) {
        $this->logger()->warning('DRY RUN: No data written.');
        $this->output()->writeln('Validation successful.');
        return;
      }

      if (!$result['success']) {
        $this->logger()->error('Import failed with @count errors.', ['@count' => count($result['errors'])]);
        foreach ($result['errors'] as $error) {
          $this->logger()->error('  - @error', ['@error' => $error]);
        }
        throw new \Exception('Import failed.');
      }

      $this->logger()->success('Import completed successfully!');
      $this->output()->writeln("Created: {$result['created_count']} elements");
      if ($result['updated_count'] > 0) {
        $this->output()->writeln("Updated: {$result['updated_count']} elements");
      }
      if ($result['root_id']) {
        $this->output()->writeln("Root element ID: {$result['root_id']}");
      }

      if (!empty($result['errors'])) {
        $this->logger()->warning('Import completed with @count warnings.', ['@count' => count($result['errors'])]);
        foreach ($result['errors'] as $error) {
          $this->logger()->warning('  - @error', ['@error' => $error]);
        }
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Import failed: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Normalize a mixed Drush option into boolean.
   *
   * @param mixed $value
   *   The option value.
   *
   * @return bool
   *   Normalized boolean value.
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
