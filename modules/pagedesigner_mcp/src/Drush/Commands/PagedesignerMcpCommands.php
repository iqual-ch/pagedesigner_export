<?php

declare(strict_types=1);

namespace Drupal\pagedesigner_mcp\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Pagedesigner MCP OAuth client lifecycle.
 *
 * Self-contained on purpose: Drush discovers this class through the
 * autoloader, and nothing guarantees pagedesigner_mcp.module has been included
 * when the command runs — a helper living there was an undefined function
 * in practice. Everything the command needs is injected here.
 */
final class PagedesignerMcpCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * The consumer's client_id, fixed by pagedesigner_mcp_ensure_oauth_client().
   */
  private const CLIENT_ID = 'pagedesigner_mcp';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PasswordGeneratorInterface $passwordGenerator,
  ) {
    parent::__construct();
  }

  /**
   * Rotate the Pagedesigner MCP OAuth client secret.
   */
  #[CLI\Command(name: 'pagedesigner-mcp:rotate-secret', aliases: ['pagedesigner-mcp-rotate'])]
  #[CLI\Usage(name: 'drush pagedesigner-mcp:rotate-secret', description: 'Generate a new client_secret for the pagedesigner_mcp consumer; the old secret stops working immediately.')]
  public function rotateSecret(): void {
    $consumers = $this->entityTypeManager->getStorage('consumer')
      ->loadByProperties(['client_id' => self::CLIENT_ID]);
    $consumer = reset($consumers);
    if (!$consumer) {
      throw new \RuntimeException('No ' . self::CLIENT_ID . ' consumer exists — reinstall the module or run its updates first.');
    }
    $secret = $this->passwordGenerator->generate(32);
    $consumer->set('secret', $secret);
    $consumer->save();

    $this->output()->writeln('New client_secret (shown only this once — update the cockpit connection):');
    $this->output()->writeln($secret);
  }

}
