<?php

declare(strict_types=1);

namespace Drupal\pagedesigner_mcp\Plugin\Tool;

use Drupal\pagedesigner_mcp\Service\PagedesignerMcpOperations;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP tool: get_page_tree — thin adapter over PagedesignerMcpOperations.
 */
#[Tool(
  id: 'get_page_tree',
  label: new TranslatableMarkup('Get page tree'),
  description: new TranslatableMarkup('Export one Pagedesigner element tree (rows, cells, typed elements with field values, ALL translations, media enrichment with downloadable URLs) plus its content_hash for incremental sync. Same shape as a pages/*.json file of the drush package.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'pagedesigner_root_id' => ['type' => 'integer', 'description' => 'The pagedesigner_root_id from a manifest page entry.'],
      'langcode' => ['type' => 'string', 'description' => 'Base language of the tree; defaults to the page default_langcode from the manifest.'],
    ],
    'required' => ['pagedesigner_root_id'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetPageTree extends ToolPluginBase {

  protected PagedesignerMcpOperations $operations;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
    );
    $instance->operations = $container->get('pagedesigner_mcp.operations');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Enabled by default: the module exists solely to expose these tools.
   */
  protected function defaultConfiguration(): array {
    return ['enabled' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->operations->execute('get_page_tree', $arguments);
  }

}
