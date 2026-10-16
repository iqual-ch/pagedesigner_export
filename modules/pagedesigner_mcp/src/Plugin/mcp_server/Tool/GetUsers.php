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
 * MCP tool: get_users — thin adapter over PagedesignerMcpOperations.
 */
#[Tool(
  id: 'get_users',
  label: new TranslatableMarkup('Get users and roles'),
  description: new TranslatableMarkup('Export the site accounts (uid, uuid, name, mail, status, roles, created) and its roles (id, label). Same shape as users.json of the drush package. A page carries its owner as {uid, name} only; the target matches an owner by e-mail, so it resolves those uids against this list. Users import BEFORE nodes on the target. Pass `limit` to page by uid (with `after_uid` = the previous page\'s `paging.next_after_uid`); without it every account comes in one response.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'limit' => ['type' => 'integer', 'description' => 'Optional page size. With it the result carries `paging: {after_uid, next_after_uid, total}`; `next_after_uid` is null on the last page.'],
      'after_uid' => ['type' => 'integer', 'description' => 'Paged form: return accounts with a uid above this (0 for the first page).', 'default' => 0],
    ],
    'required' => [],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetUsers extends ToolPluginBase {

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
    return $this->operations->execute('get_users', $arguments);
  }

}
