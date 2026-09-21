<?php

declare(strict_types=1);

namespace Drupal\pagedesigner_mcp\Plugin\mcp_server\Tool;

use Drupal\pagedesigner_mcp\Service\PagedesignerMcpOperations;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP tool: get_theme_settings — thin adapter over PagedesignerMcpOperations.
 */
#[Tool(
  id: 'get_theme_settings',
  label: new TranslatableMarkup('Get theme settings'),
  description: new TranslatableMarkup('Export the site design as data: the default theme chain, iq_barrio.settings verbatim, the resolved colour palette (name to hex) and, per Pagedesigner pattern, the toggleable classes and styling-option selects that give the class tokens in the page trees their meaning. Same shape as theme.json of the drush package. Empty layers, never an error, on a site without iq_barrio.'),
  inputSchema: ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetThemeSettings extends ToolPluginBase {

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
    return $this->operations->execute('get_theme_settings', $arguments);
  }

}
