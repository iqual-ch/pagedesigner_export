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
 * MCP tool: get_webforms — thin adapter over PagedesignerMcpOperations.
 */
#[Tool(
  id: 'get_webforms',
  label: new TranslatableMarkup('Get webforms and submissions'),
  description: new TranslatableMarkup('Export every webform with its config verbatim (elements, settings, handlers, translations) and, by default, its submissions (data, timestamps, IP, draft state, submitter uid + e-mail). Same shape as webforms.json of the drush package. A Pagedesigner webform element places a form by id; the target imports the forms AFTER users (submitters are re-linked by e-mail) and before nodes.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'include_submissions' => ['type' => 'boolean', 'description' => 'Include the submissions (default true). The submission count is exported either way.', 'default' => TRUE],
    ],
    'required' => [],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetWebforms extends ToolPluginBase {

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
    return $this->operations->execute('get_webforms', $arguments);
  }

}
