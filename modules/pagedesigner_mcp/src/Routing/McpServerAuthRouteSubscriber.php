<?php

declare(strict_types=1);

namespace Drupal\pagedesigner_mcp\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Allows OAuth2 Bearer authentication on the mcp_server endpoint.
 *
 * mcp_server's route declares `_auth: ['cookie']`, and Drupal core treats an
 * explicit `_auth` list as exhaustive — even GLOBAL providers such as
 * simple_oauth's `oauth2` are rejected with "The used authentication method
 * is not allowed on this route." The module's migration tools authenticate
 * exclusively via OAuth 2.1 client_credentials Bearer tokens, so `oauth2`
 * must be on the list. Idempotent: safe alongside any other module doing
 * the same (icms_mcp ships the identical subscriber).
 *
 * Also keeps the endpoint out of the redirect module's route normalizer. On
 * sites with language prefixes the normalizer answers `GET /mcp` with a 301 to
 * `/de/mcp`; the streamable-HTTP MCP client treats that as a dropped event
 * stream and reconnects every second, one loop per session — a migration
 * ingest turned that into ~1000 requests a minute against the source site.
 * A plain 405 on GET is what the client expects from a server without an
 * event stream, and it stops asking.
 */
final class McpServerAuthRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('mcp_server.handle');
    if ($route === NULL) {
      return;
    }
    $auth = $route->getOption('_auth') ?? [];
    if (!in_array('oauth2', $auth, TRUE)) {
      $auth[] = 'oauth2';
      $route->setOption('_auth', $auth);
    }
    $route->setDefault('_disable_route_normalizer', TRUE);
  }

}
