<?php

declare(strict_types=1);

namespace Drupal\pagedesigner_mcp\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Pagedesigner MCP OAuth client lifecycle.
 */
final class PagedesignerMcpCommands extends DrushCommands {

  /**
   * Rotate the Pagedesigner MCP OAuth client secret.
   */
  #[CLI\Command(name: 'pagedesigner-mcp:rotate-secret', aliases: ['pagedesigner-mcp-rotate'])]
  #[CLI\Usage(name: 'drush pagedesigner-mcp:rotate-secret', description: 'Generate a new client_secret for the pagedesigner_mcp consumer; the old secret stops working immediately.')]
  public function rotateSecret(): void {
    $secret = pagedesigner_mcp_rotate_oauth_secret();
    $this->output()->writeln('New client_secret (shown only this once — update the cockpit connection):');
    $this->output()->writeln($secret);
  }

}
