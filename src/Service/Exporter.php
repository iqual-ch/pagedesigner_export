<?php

namespace Drupal\pagedesigner_export\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\pagedesigner\Entity\Element;
use Drupal\user\EntityOwnerInterface;

/**
 * Exports Pagedesigner element trees to JSON.
 */
class Exporter {

  /**
   * Constructs the Exporter service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected LanguageManagerInterface $languageManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected ConfigFactoryInterface $configFactory,
    protected AliasManagerInterface $aliasManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected ?ModuleHandlerInterface $moduleHandler = NULL,
    protected ?MenuLinkManagerInterface $menuLinkManager = NULL,
    protected ?Connection $database = NULL,
    protected ?ThemeHandlerInterface $themeHandler = NULL,
    protected ?PluginManagerInterface $patternManager = NULL,
  ) {}

  /**
   * Export contract schema version.
   */
  // 0.12.0 adds `assets` and `customCss` to `theme.json`: the logo and favicon
  // the theme settings point at, the theme's own SVG icons, the `@font-face`
  // rules of its compiled stylesheets with their files, and those stylesheets
  // themselves (inline when small) — what a migration needs to rebuild the
  // visual identity beyond the settings, and what the scalar filter used to
  // drop (`logo.path`). The manifest's `theme` summary says whether they exist.
  // 0.11.0 adds `source.search`: whether Search API is installed, its servers
  // with their backend (Solr, Elasticsearch, database), its indexes and the
  // views built on them. A consumer can then tell a search results block from
  // a content listing by configuration rather than by the view's name, and a
  // target requirements plan can name the backend the target has to provide.
  // 0.10.0 adds `machine_name` and `description` to each vocabulary in
  // `taxonomies.json` / `get_taxonomies`: the vocabulary gate shows a reviewer
  // what a vocabulary is FOR beside its label.
  // 0.9.0 adds `webforms.json` (`content.webforms_file`): every webform's
  // config verbatim plus its submissions, so a page's placed form and an
  // event's lifted registration form can exist on the target. It also adds
  // `description` to `field_definitions` — the field's editor-facing help
  // text, so a reviewer mapping an unfamiliar site's fields reads what a
  // field is FOR and not only what it is called.
  // 0.5.0 adds `field_definitions`: the declared schema of the fields an entity
  // exports, so target setup can create a counterpart field instead of guessing
  // its type from a sample value. Consumers check the MAJOR version only, so
  // older readers keep working.
  // 0.6.0 adds `users.json` (accounts + roles, so a migrated page can keep its
  // author) and `pagedesigner_child_count` per page (so a consumer can tell a
  // real composition from an empty one without fetching its tree).
  // 0.7.0 adds `pagedesigner_content_count`: the same question asked properly,
  // counting the CONTENT elements anywhere under the root rather than the
  // root's direct children, so a page left holding empty rows reads as empty.
  // 0.8.0 adds `theme.json` (and a `theme` summary on the manifest): the
  // site's design as data — `iq_barrio.settings` verbatim, the resolved palette
  // and the per-pattern class / styling-option vocabulary — so a migration can
  // carry the visual identity, not only the content.
  public const MIGRATION_SCHEMA_VERSION = '0.12.0';

  /**
   * The `iq_barrio.settings` keys that hold literal colours.
   *
   * Every other `*_color*` key holds one of these NAMES (`primary`, `grey5`,
   * `white`, …); the theme interpolates `$color-<name>` into its SCSS. The
   * palette is therefore the only place a consumer can turn a name into a hex.
   */
  public const PALETTE_KEYS = [
    'primary', 'secondary', 'tertiary', 'quaternary',
    'grey1', 'grey2', 'grey3', 'grey4', 'grey5',
    'black', 'white',
  ];

  /**
   * Element bundles that hold no content of their own.
   *
   * Structure (`container`, `row`, `cell`, `layout`), the UI-patterns shell
   * (`component` — its payload lives in its `content` children) and the
   * presentation attachments (`style`, `class`, the responsive-image helpers).
   * Everything else counts: `content`, `image`, `svg`, `link`, `document`,
   * `audio`, `video`, `embed`, `gallery`, `block`, `webform`, …
   *
   * A DENY-list on purpose. A bundle a future `pagedesigner_*` sub-module ships
   * counts as content until it is listed here, so an unknown element keeps its
   * page in the reviewer's hands; an allow-list would silently classify that
   * page as empty and skip it.
   */
  public const NON_CONTENT_ELEMENT_TYPES = [
    'container',
    'row',
    'cell',
    'component',
    'layout',
    'style',
    'class',
    'component_sizes',
    'image_style_template',
  ];

  /**
   * Build a migration manifest for source entities with pagedesigner roots.
   *
   * @param array $options
   *   Manifest options:
   *   - entity_type: Entity type ID, defaults to node.
   *   - bundle: Optional bundle filter.
   *   - field: Optional pagedesigner field filter.
   *   - limit: Optional page/root limit.
   *   - include_unpublished: Include unpublished page translations.
   *
   * @return array
   *   The manifest data.
   */
  public function buildMigrationManifest(array $options = []): array {
    $siteConfig = $this->configFactory->get('system.site');
    $entityTypeId = (string) ($options['entity_type'] ?? 'node');

    return [
      'schema_version' => self::MIGRATION_SCHEMA_VERSION,
      'source' => [
        'adapter' => 'pagedesigner_export',
        'site_name' => $siteConfig->get('name') ?: NULL,
        'site_uuid' => $siteConfig->get('uuid') ?: NULL,
        'base_url' => $this->getBaseUrl(),
        'default_langcode' => $this->languageManager->getDefaultLanguage()->getId(),
        'drupal_version' => \Drupal::VERSION,
        'exported_at' => time(),
        'module_version' => self::MIGRATION_SCHEMA_VERSION,
        'search' => $this->searchSummary(),
      ],
      'pages' => $this->discoverMigrationPages($entityTypeId, $options),
      'theme' => $this->themeSummary(),
    ];
  }

  /**
   * Export a complete migration package with manifest.json and pages/*.json.
   *
   * @param string $outputDirectory
   *   Output package directory.
   * @param array $options
   *   Export options. See buildMigrationManifest().
   *
   * @return array
   *   Export result with manifest data and page count.
   *
   * @throws \Exception
   *   If any tree cannot be exported or written.
   */
  public function exportMigrationPackage(string $outputDirectory, array $options = []): array {
    $manifest = $this->buildMigrationManifest($options);
    $sanitizeLocalUrls = (bool) ($options['sanitize_local_urls'] ?? TRUE);

    $pagesDirectory = rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pages';
    if (!is_dir($pagesDirectory) && !mkdir($pagesDirectory, 0775, TRUE) && !is_dir($pagesDirectory)) {
      throw new \Exception("Failed to create pages directory: {$pagesDirectory}");
    }

    foreach ($manifest['pages'] as &$page) {
      $tree = $this->export((int) $page['pagedesigner_root_id'], $page['default_langcode'], $sanitizeLocalUrls);
      $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . $page['export_file'], $tree);
      // Stable per-page hash so re-exports can skip unchanged pages.
      $page['content_hash'] = sha1(json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
    unset($page);

    // Step-3 content (schema 0.3.0): taxonomy trees, menus, and entities
    // without pagedesigner roots — compositions reference them, so they
    // must exist on the target first.
    $taxonomies = $this->exportTaxonomies();
    $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . 'taxonomies.json', $taxonomies);
    $menus = $this->exportMenus();
    $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . 'menus.json', $menus);
    // Accounts and roles (schema 0.6.0): a page's owner travels as {uid, name},
    // which the target cannot resolve on its own — it matches by e-mail.
    $users = $this->exportUsers();
    $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . 'users.json', $users);
    // The design (schema 0.8.0): theme settings, palette and the pattern
    // vocabulary that gives the class tokens in the trees their meaning.
    $theme = $this->exportTheme();
    $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . 'theme.json', $theme);
    // Webforms (schema 0.9.0): config verbatim plus submissions. A page places
    // a form by id only, and a child entity names its registration form by id;
    // without this sidecar neither can exist on the target.
    $webforms = $this->exportWebforms();
    $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . 'webforms.json', $webforms);

    $pd_entity_ids = [];
    foreach ($manifest['pages'] as $page) {
      $pd_entity_ids[(string) ($page['entity_type'] ?? 'node') . ':' . (string) ($page['entity_id'] ?? '')] = TRUE;
    }
    $entities = $this->discoverPlainEntities($options, $pd_entity_ids);
    foreach ($entities as &$entity_entry) {
      $payload = $this->exportPlainEntity($entity_entry, $sanitizeLocalUrls);
      $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . $entity_entry['export_file'], $payload);
      $entity_entry['content_hash'] = sha1(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
    unset($entity_entry);

    $manifest['content'] = [
      'taxonomies_file' => 'taxonomies.json',
      'vocabulary_count' => count($taxonomies['vocabularies'] ?? []),
      'menus_file' => 'menus.json',
      'menu_count' => count($menus['menus'] ?? []),
      'users_file' => 'users.json',
      'user_count' => count($users['users'] ?? []),
      'role_count' => count($users['roles'] ?? []),
      'theme_file' => 'theme.json',
      'webforms_file' => 'webforms.json',
      'webform_count' => count($webforms['webforms'] ?? []),
      'webform_submission_count' => array_sum(array_map(
        static fn (array $webform): int => (int) ($webform['submissionCount'] ?? 0),
        $webforms['webforms'] ?? [],
      )),
      'entities' => $entities,
    ];
    $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);

    return [
      'manifest' => $manifest,
      'page_count' => count($manifest['pages']),
      'entity_count' => count($entities),
    ];
  }

  /**
   * Export the site's design as data.
   *
   * Three layers, all read-only:
   * - `theme`: the default theme and its base chain, and which config object
   *   carries the design settings.
   * - `settings`: `iq_barrio.settings` verbatim (strings, as the theme-settings
   *   form saved them). The theme bakes these into compiled CSS at save time,
   *   so the config is the only machine-readable copy of the design.
   * - `palette`: the literal colours, keyed by the NAME the other settings use.
   * - `patterns`: per UI pattern, the toggleable `classes` and the
   *   `styling_options` selects. Without this a consumer sees a tree's
   *   `field_classes` as opaque strings and cannot tell a variant (`inverted`)
   *   from a breakpoint flag (`fullwidth-small`) or a colour pick.
   *
   * A site without iq_barrio (or without UI Patterns) still answers: the
   * layers it lacks are empty, never an error, so the migration proceeds
   * without a design profile instead of failing on it.
   */
  public function exportTheme(): array {
    $chain = $this->themeChain();
    $settingsConfig = in_array('iq_barrio', $chain, TRUE) || $chain === []
      ? 'iq_barrio.settings'
      : $chain[0] . '.settings';
    $settings = $this->configFactory->get($settingsConfig)->get() ?: [];
    if ($settings === [] && $settingsConfig !== 'iq_barrio.settings') {
      $settingsConfig = 'iq_barrio.settings';
      $settings = $this->configFactory->get($settingsConfig)->get() ?: [];
    }
    // Only scalars travel: nested Barrio region/feature arrays are layout
    // chrome, not design, and they bloat the payload.
    $settings = array_filter($settings, static fn ($value): bool => is_scalar($value) || $value === NULL);

    $custom_css = $this->themeStylesheets($chain[0] ?? '');
    return [
      'theme' => [
        'name' => $chain[0] ?? NULL,
        'baseTheme' => $chain[1] ?? NULL,
        'chain' => $chain,
        'settingsConfig' => $settingsConfig,
      ],
      'settings' => $settings,
      'palette' => $this->paletteFromSettings($settings),
      'patterns' => $this->exportPatternVocabulary(),
      'assets' => $this->themeAssets($chain[0] ?? '', $custom_css),
      'customCss' => $custom_css,
    ];
  }

  /**
   * The design's files: logo, favicon, the theme's own icons, the font faces.
   *
   * `logo` / `favicon` come from the default theme's settings (`theme_get_setting`
   * resolves `use_default`, the theme's `logo.svg` fallback and the file URI);
   * `icons` are the SVGs under the theme's `resources/img/` and `patterns/`;
   * `fonts` are the `@font-face` rules found in the theme's stylesheets
   * (`$stylesheets`, see themeStylesheets()) plus the Google/Adobe font
   * stylesheets its libraries link. Every URL is absolute. Empty lists when
   * the theme has nothing, never an error.
   */
  protected function themeAssets(string $theme, array $stylesheets): array {
    $assets = ['logo' => NULL, 'favicon' => NULL, 'icons' => [], 'fonts' => []];
    if ($theme === '') {
      return $assets;
    }
    foreach (['logo', 'favicon'] as $key) {
      try {
        $url = (string) (theme_get_setting($key . '.url', $theme) ?? '');
        $path = (string) (theme_get_setting($key . '.path', $theme) ?? '');
        $use_default = (bool) theme_get_setting($key . '.use_default', $theme);
      }
      catch (\Throwable) {
        continue;
      }
      if ($url === '' && $path === '') {
        continue;
      }
      $assets[$key] = [
        'url' => $this->absoluteUrl($url !== '' ? $url : $path),
        'path' => $path,
        'useDefault' => $use_default,
      ];
    }
    $theme_path = $this->themePath($theme);
    if ($theme_path !== '') {
      $root = rtrim(DRUPAL_ROOT, '/');
      foreach (['resources/img', 'resources/images', 'images', 'img', 'patterns'] as $dir) {
        $absolute = $root . '/' . $theme_path . '/' . $dir;
        if (!is_dir($absolute)) {
          continue;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
          if (strtolower($file->getExtension()) !== 'svg' || count($assets['icons']) >= 200) {
            continue;
          }
          $relative = $theme_path . '/' . $dir . '/' . str_replace($absolute . '/', '', $file->getPathname());
          $relative = str_replace('\\', '/', $relative);
          $assets['icons'][] = [
            'name' => $file->getBasename('.svg'),
            'path' => $relative,
            'url' => $this->absoluteUrl('/' . $relative),
            'bytes' => (int) $file->getSize(),
          ];
        }
      }
    }
    foreach ($stylesheets as $sheet) {
      $content = $sheet['content'] ?? NULL;
      if ($content === NULL && !empty($sheet['path'])) {
        $content = @file_get_contents(rtrim(DRUPAL_ROOT, '/') . '/' . $sheet['path']) ?: '';
      }
      foreach ($this->fontFacesIn((string) $content, (string) ($sheet['path'] ?? '')) as $face) {
        $assets['fonts'][] = $face;
      }
    }
    foreach ($this->externalFontStylesheets($theme) as $url) {
      $assets['fonts'][] = [
        'family' => $this->fontFamilyFromUrl($url),
        'weight' => NULL,
        'style' => NULL,
        'src' => [$url],
        'source' => str_contains($url, 'typekit') ? 'adobe-fonts' : 'google',
      ];
    }
    return $assets;
  }

  /**
   * The compiled stylesheets of the default theme's own libraries.
   *
   * Each `{library, path, url, bytes, content?}`; `content` is inlined below
   * 256 KiB so a consumer can scan hover rules, shadows and transitions
   * without fetching. Base themes' sheets are not included: their look is in
   * the settings; the default theme's sheet is what overrides them.
   */
  protected function themeStylesheets(string $theme): array {
    if ($theme === '' || !\Drupal::hasService('library.discovery')) {
      return [];
    }
    try {
      $libraries = \Drupal::service('library.discovery')->getLibrariesByExtension($theme);
    }
    catch (\Throwable) {
      return [];
    }
    $sheets = [];
    $root = rtrim(DRUPAL_ROOT, '/');
    foreach ((array) $libraries as $library_name => $library) {
      foreach ((array) ($library['css'] ?? []) as $css) {
        if (($css['type'] ?? 'file') !== 'file' || empty($css['data'])) {
          continue;
        }
        $path = ltrim((string) $css['data'], '/');
        $absolute = $root . '/' . $path;
        if (!is_file($absolute)) {
          continue;
        }
        $bytes = (int) filesize($absolute);
        $entry = [
          'library' => $theme . '/' . $library_name,
          'path' => $path,
          'url' => $this->absoluteUrl('/' . $path),
          'bytes' => $bytes,
        ];
        if ($bytes <= 262144) {
          $entry['content'] = (string) @file_get_contents($absolute);
        }
        $sheets[] = $entry;
      }
    }
    return $sheets;
  }

  /**
   * External font stylesheets (Google Fonts, Adobe Fonts) the theme's
   * libraries link.
   */
  protected function externalFontStylesheets(string $theme): array {
    if (!\Drupal::hasService('library.discovery')) {
      return [];
    }
    try {
      $libraries = \Drupal::service('library.discovery')->getLibrariesByExtension($theme);
    }
    catch (\Throwable) {
      return [];
    }
    $urls = [];
    foreach ((array) $libraries as $library) {
      foreach ((array) ($library['css'] ?? []) as $css) {
        $data = (string) ($css['data'] ?? '');
        if (($css['type'] ?? '') === 'external' && preg_match('#fonts\.googleapis\.com|use\.typekit\.net|fonts\.bunny\.net#', $data)) {
          $urls[] = $data;
        }
      }
    }
    return array_values(array_unique($urls));
  }

  /**
   * `@font-face` rules of a stylesheet: family, weight, style, absolute src URLs.
   */
  protected function fontFacesIn(string $css, string $css_path): array {
    $faces = [];
    if ($css === '' || !preg_match_all('/@font-face\s*\{([^}]*)\}/i', $css, $blocks)) {
      return $faces;
    }
    $base_dir = $css_path !== '' ? dirname($css_path) : '';
    foreach ($blocks[1] as $block) {
      $face = ['family' => '', 'weight' => '400', 'style' => 'normal', 'src' => [], 'source' => 'self-hosted'];
      if (preg_match('/font-family\s*:\s*([^;]+);/i', $block, $m)) {
        $face['family'] = trim($m[1], " \t\n\r\0\x0B'\"");
      }
      if (preg_match('/font-weight\s*:\s*([^;]+);/i', $block, $m)) {
        $face['weight'] = trim($m[1]);
      }
      if (preg_match('/font-style\s*:\s*([^;]+);/i', $block, $m)) {
        $face['style'] = trim($m[1]);
      }
      if (preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $block, $urls)) {
        foreach ($urls[2] as $url) {
          if (str_starts_with($url, 'data:')) {
            continue;
          }
          $url = preg_replace('/[?#].*$/', '', $url);
          if (preg_match('#^(https?:)?//#', $url)) {
            $face['src'][] = $url;
          }
          elseif (str_starts_with($url, '/')) {
            $face['src'][] = $this->absoluteUrl($url);
          }
          else {
            $joined = $this->normalizePath(($base_dir !== '' ? $base_dir . '/' : '') . $url);
            $face['src'][] = $this->absoluteUrl('/' . $joined);
          }
        }
      }
      if ($face['family'] !== '') {
        $faces[] = $face;
      }
    }
    return $faces;
  }

  /**
   * The first `family=` of a Google Fonts URL, for the assets list.
   */
  protected function fontFamilyFromUrl(string $url): string {
    if (preg_match('/family=([^&:]+)/', $url, $m)) {
      return str_replace('+', ' ', urldecode($m[1]));
    }
    return '';
  }

  /**
   * The default theme's path relative to DRUPAL_ROOT, '' when unknown.
   */
  protected function themePath(string $theme): string {
    $handler = $this->themeHandler ?? (\Drupal::hasService('theme_handler') ? \Drupal::service('theme_handler') : NULL);
    if ($handler === NULL) {
      return '';
    }
    $info = $handler->listInfo();
    if (!isset($info[$theme]) || !method_exists($info[$theme], 'getPath')) {
      return '';
    }
    return trim((string) $info[$theme]->getPath(), '/');
  }

  /**
   * `a/b/../c` → `a/c`.
   */
  protected function normalizePath(string $path): string {
    $parts = [];
    foreach (explode('/', $path) as $part) {
      if ($part === '' || $part === '.') {
        continue;
      }
      if ($part === '..') {
        array_pop($parts);
        continue;
      }
      $parts[] = $part;
    }
    return implode('/', $parts);
  }

  /**
   * An absolute URL for a stream URI, a root-relative path or an already
   * absolute URL.
   */
  protected function absoluteUrl(string $value): string {
    if ($value === '' || preg_match('#^(https?:)?//#', $value)) {
      return $value;
    }
    if (str_contains($value, '://')) {
      try {
        return $this->fileUrlGenerator->generateAbsoluteString($value);
      }
      catch (\Throwable) {
        return $value;
      }
    }
    try {
      $host = \Drupal::request()->getSchemeAndHttpHost();
    }
    catch (\Throwable) {
      $host = '';
    }
    return $host . '/' . ltrim($value, '/');
  }

  /**
   * The manifest's one-line view of the design: enough to know it is there.
   */
  protected function themeSummary(): array {
    $chain = $this->themeChain();
    $settings = $this->configFactory->get('iq_barrio.settings')->get() ?: [];
    $stylesheets = $this->themeStylesheets($chain[0] ?? '');
    $assets = $this->themeAssets($chain[0] ?? '', $stylesheets);
    return [
      'name' => $chain[0] ?? NULL,
      'baseTheme' => $chain[1] ?? NULL,
      'palette' => $this->paletteFromSettings($settings),
      'hasLogo' => !empty($assets['logo']),
      'hasFavicon' => !empty($assets['favicon']),
      'iconCount' => count($assets['icons']),
      'fontFaceCount' => count($assets['fonts']),
      'customCssBytes' => array_sum(array_map(static fn (array $s): int => (int) ($s['bytes'] ?? 0), $stylesheets)),
    ];
  }

  /**
   * Default theme first, then each base theme in order.
   */
  protected function themeChain(): array {
    $handler = $this->themeHandler ?? (\Drupal::hasService('theme_handler') ? \Drupal::service('theme_handler') : NULL);
    if ($handler === NULL) {
      return [];
    }
    $info = $handler->listInfo();
    $chain = [];
    $name = $handler->getDefault();
    while ($name && isset($info[$name]) && !in_array($name, $chain, TRUE)) {
      $chain[] = $name;
      $name = $info[$name]->base_theme ?? NULL;
    }
    return $chain;
  }

  /**
   * `{name: "#rrggbb"}` for every palette key that holds a colour.
   */
  protected function paletteFromSettings(array $settings): array {
    $palette = [];
    foreach (self::PALETTE_KEYS as $name) {
      $raw = trim((string) ($settings['color_' . $name] ?? ''));
      if ($raw === '') {
        continue;
      }
      if (preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $raw, $m)) {
        $hex = strtolower($m[1]);
        if (strlen($hex) === 3) {
          $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $palette[$name] = '#' . $hex;
      }
      else {
        // rgb()/named colours are legal CSS; pass them through untouched.
        $palette[$name] = $raw;
      }
    }
    return $palette;
  }

  /**
   * Per Pagedesigner pattern: toggleable classes and styling-option selects.
   *
   * Reads the same `additional` keys the Pagedesigner editor reads
   * (`PatternResource`), minus translation: `classes[key] = {label,
   * description, responsive}` and `stylingOptions[key] = {label, options:
   * {classValue: label}}`. `responsive: true` means the editor stores the
   * class as `<key>-<large|medium|small>`.
   */
  protected function exportPatternVocabulary(): array {
    $manager = $this->patternManager
      ?? (\Drupal::hasService('plugin.manager.ui_patterns') ? \Drupal::service('plugin.manager.ui_patterns') : NULL);
    if ($manager === NULL) {
      return [];
    }
    $patterns = [];
    foreach ($manager->getDefinitions() as $id => $definition) {
      $additional = is_object($definition) && method_exists($definition, 'getAdditional')
        ? (array) $definition->getAdditional()
        : (array) $definition;
      if (empty($additional['pagedesigner'])) {
        continue;
      }
      $classes = [];
      foreach ((array) ($additional['classes'] ?? []) as $key => $class) {
        $class = is_array($class) ? $class : [];
        $classes[(string) $key] = [
          'label' => (string) ($class['label'] ?? $key),
          'description' => (string) ($class['description'] ?? ''),
          'responsive' => !empty($class['responsive']),
        ];
      }
      $stylingOptions = [];
      foreach ((array) ($additional['styling_options'] ?? []) as $key => $option) {
        $option = is_array($option) ? $option : [];
        $values = [];
        foreach ((array) ($option['options'] ?? []) as $classValue => $label) {
          $values[(string) $classValue] = (string) $label;
        }
        $stylingOptions[(string) $key] = [
          'label' => (string) ($option['label'] ?? $key),
          'options' => $values,
        ];
      }
      $patterns[(string) $id] = [
        'type' => (string) ($additional['type'] ?? ''),
        'styles' => !empty($additional['styles']),
        'classes' => $classes,
        'stylingOptions' => $stylingOptions,
      ];
    }
    ksort($patterns);
    return $patterns;
  }

  /**
   * Export every vocabulary with its full term tree and translations.
   */
  public function exportTaxonomies(): array {
    $vocabularies = [];
    if (!$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return ['vocabularies' => []];
    }
    $vocabulary_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    foreach ($vocabulary_storage->loadMultiple() as $vocabulary) {
      $terms = [];
      foreach ($term_storage->loadTree($vocabulary->id(), 0, NULL, TRUE) as $term) {
        /** @var \Drupal\taxonomy\TermInterface $term */
        $labels = [];
        foreach ($term->getTranslationLanguages() as $langcode => $language) {
          $labels[$langcode] = $term->getTranslation($langcode)->label();
        }
        $parents = $term_storage->loadParents($term->id());
        $parent = $parents ? (int) reset($parents)->id() : 0;
        $terms[] = [
          'tid' => (int) $term->id(),
          'uuid' => $term->uuid(),
          'name' => $term->label(),
          'labels' => $labels,
          'description' => (string) ($term->getDescription() ?? ''),
          'parent' => $parent,
          'weight' => (int) $term->getWeight(),
        ];
      }
      $vocabularies[] = [
        'vid' => $vocabulary->id(),
        'machine_name' => $vocabulary->id(),
        'label' => $vocabulary->label(),
        // What the vocabulary is FOR, as its editors see it: the mapping gate
        // shows it beside the label so a reviewer matching an unfamiliar
        // site's vocabularies has more than a machine name to go on.
        'description' => trim(strip_tags((string) ($vocabulary->getDescription() ?? ''))),
        'terms' => $terms,
      ];
    }

    return ['vocabularies' => $vocabularies];
  }

  /**
   * Export the accounts that own content, plus the site's roles.
   *
   * Per-node owners travel as `{uid, name}` on the page metadata: enough to
   * say WHO wrote a page, not enough to recreate the account on the target,
   * which matches an owner by e-mail. This is the list those uids resolve
   * against.
   *
   * The anonymous user is skipped (uid 0 owns nothing worth migrating) and the
   * implicit `authenticated` role is dropped from both lists — every account
   * has it, so it is never a mapping decision.
   */
  public function exportUsers(): array {
    if (!$this->entityTypeManager->hasDefinition('user')) {
      return ['users' => [], 'roles' => []];
    }

    $roles = [];
    if ($this->entityTypeManager->hasDefinition('user_role')) {
      foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
        if (in_array($role->id(), ['anonymous', 'authenticated'], TRUE)) {
          continue;
        }
        $roles[] = ['id' => $role->id(), 'label' => (string) $role->label()];
      }
    }

    $users = [];
    $user_storage = $this->entityTypeManager->getStorage('user');
    $uids = $user_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->sort('uid')
      ->execute();
    foreach ($user_storage->loadMultiple($uids) as $account) {
      /** @var \Drupal\user\UserInterface $account */
      $users[] = [
        'uid' => (int) $account->id(),
        'uuid' => $account->uuid(),
        'name' => $account->getAccountName(),
        'mail' => (string) ($account->getEmail() ?? ''),
        'status' => (bool) $account->isActive(),
        'roles' => array_values(array_diff($account->getRoles(), ['authenticated'])),
        'created' => (int) $account->getCreatedTime(),
      ];
    }

    return ['users' => $users, 'roles' => $roles];
  }

  /**
   * Export every webform with its config verbatim and its submissions.
   *
   * A webform is a config entity: elements (a YAML string), settings,
   * handlers, access, third-party settings. The target recreates it from that
   * array as-is — handlers included, recipient addresses and all — so nothing
   * here is normalised. Config translations travel per language when the site
   * has them. Submissions carry their data, timestamps, IP, draft state and
   * the submitter as `uid` plus the account's e-mail (`mail`), which is what
   * the target re-links the submitter by; the source entity a submission was
   * made on (`entity_type`/`entity_id`) is exported for the record only — a
   * source nid means nothing on the target.
   *
   * Example and template forms the webform module ships are skipped, as the
   * Pagedesigner handler skips them. A site without the webform module answers
   * `['webforms' => []]`, never an error.
   *
   * @param bool $withSubmissions
   *   Whether to include submissions (the count is exported either way).
   *
   * @return array
   *   `{webforms: [{id, uuid, label, status, langcode, config, configTranslations,
   *   submissionCount, submissions}]}`.
   */
  public function exportWebforms(bool $withSubmissions = TRUE): array {
    if (!$this->entityTypeManager->hasDefinition('webform')) {
      return ['webforms' => []];
    }
    $webform_storage = $this->entityTypeManager->getStorage('webform');
    $submission_storage = $this->entityTypeManager->hasDefinition('webform_submission')
      ? $this->entityTypeManager->getStorage('webform_submission')
      : NULL;
    $user_storage = $this->entityTypeManager->hasDefinition('user')
      ? $this->entityTypeManager->getStorage('user')
      : NULL;
    $languages = array_keys($this->languageManager->getLanguages());
    $default_langcode = $this->languageManager->getDefaultLanguage()->getId();
    $mail_by_uid = [];

    $webforms = [];
    foreach ($webform_storage->loadMultiple() as $webform_id => $webform) {
      $webform_id = (string) $webform_id;
      if (str_starts_with($webform_id, 'example_') || str_starts_with($webform_id, 'template_')) {
        continue;
      }
      $config_name = 'webform.webform.' . $webform_id;
      $config = $this->configFactory->get($config_name)->getRawData();
      unset($config['_core']);

      $translations = [];
      if ($this->languageManager instanceof ConfigurableLanguageManagerInterface) {
        foreach ($languages as $langcode) {
          if ($langcode === $default_langcode) {
            continue;
          }
          $override = $this->languageManager->getLanguageConfigOverride($langcode, $config_name)->get();
          if (!empty($override)) {
            $translations[$langcode] = $override;
          }
        }
      }

      $submission_ids = [];
      if ($submission_storage !== NULL) {
        $submission_ids = $submission_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('webform_id', $webform_id)
          ->sort('sid')
          ->execute();
      }
      $submissions = [];
      if ($withSubmissions && $submission_ids) {
        foreach (array_chunk(array_values($submission_ids), 200) as $chunk) {
          foreach ($submission_storage->loadMultiple($chunk) as $submission) {
            /** @var \Drupal\webform\WebformSubmissionInterface $submission */
            $uid = (int) $submission->getOwnerId();
            if ($uid > 0 && !array_key_exists($uid, $mail_by_uid)) {
              $account = $user_storage?->load($uid);
              $mail_by_uid[$uid] = $account ? (string) ($account->getEmail() ?? '') : '';
            }
            $source_entity = $submission->getSourceEntity();
            $submissions[] = [
              'sid' => (int) $submission->id(),
              'uuid' => $submission->uuid(),
              'created' => (int) $submission->getCreatedTime(),
              'completed' => (int) $submission->getCompletedTime(),
              'changed' => (int) $submission->getChangedTime(),
              'in_draft' => (bool) $submission->isDraft(),
              'langcode' => $submission->language()->getId(),
              'remote_addr' => (string) $submission->getRemoteAddr(),
              'uid' => $uid,
              'mail' => $uid > 0 ? ($mail_by_uid[$uid] ?? '') : '',
              'entity_type' => $source_entity ? $source_entity->getEntityTypeId() : NULL,
              'entity_id' => $source_entity ? (string) $source_entity->id() : NULL,
              'sticky' => (bool) $submission->isSticky(),
              'locked' => (bool) $submission->isLocked(),
              'notes' => (string) $submission->getNotes(),
              'data' => $submission->getData(),
            ];
          }
          $submission_storage->resetCache($chunk);
        }
      }

      $webforms[] = [
        'id' => $webform_id,
        'uuid' => $webform->uuid(),
        'label' => (string) $webform->label(),
        'status' => $webform->status(),
        'langcode' => (string) ($config['langcode'] ?? $default_langcode),
        'config' => $config,
        'configTranslations' => $translations,
        'submissionCount' => count($submission_ids),
        'submissions' => $submissions,
      ];
    }

    return ['webforms' => $webforms];
  }

  /**
   * Export custom menus with their menu_link_content trees.
   */
  public function exportMenus(): array {
    $menus = [];
    if (!$this->entityTypeManager->hasDefinition('menu_link_content')) {
      return ['menus' => []];
    }
    $menu_storage = $this->entityTypeManager->getStorage('menu');
    $link_storage = $this->entityTypeManager->getStorage('menu_link_content');

    foreach ($menu_storage->loadMultiple() as $menu) {
      $link_ids = $link_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('menu_name', $menu->id())
        ->sort('weight', 'ASC')
        ->execute();
      if (!$link_ids) {
        continue;
      }
      $links = [];
      foreach ($link_storage->loadMultiple($link_ids) as $link) {
        /** @var \Drupal\menu_link_content\MenuLinkContentInterface $link */
        $titles = [];
        foreach ($link->getTranslationLanguages() as $langcode => $language) {
          $titles[$langcode] = $link->getTranslation($langcode)->getTitle();
        }
        $links[] = [
          'uuid' => $link->uuid(),
          'title' => $link->getTitle(),
          'titles' => $titles,
          'uri' => $link->link->uri ?? '',
          'parent' => (string) $link->getParentId(),
          'weight' => (int) $link->getWeight(),
          'enabled' => $link->isEnabled(),
          'expanded' => $link->isExpanded(),
          'langcode' => $link->language()->getId(),
        ];
      }
      $menus[] = [
        'id' => $menu->id(),
        'label' => $menu->label(),
        'links' => $links,
      ];
    }

    return ['menus' => $menus];
  }

  /**
   * Discover content entities without pagedesigner roots.
   *
   * @param array $options
   *   Export options (entity_type, bundle filters apply here too).
   * @param array $pd_entity_ids
   *   Map of "entity_type:id" already exported as pagedesigner pages.
   *
   * @return array
   *   Manifest entity entries (without content_hash yet).
   */
  public function discoverPlainEntities(array $options, array $pd_entity_ids): array {
    $entityTypeId = (string) ($options['entity_type'] ?? 'node');
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if (!$entityType || !$entityType->entityClassImplements(ContentEntityInterface::class)) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $idKey = $entityType->getKey('id') ?: 'id';
    $includeUnpublished = (bool) ($options['include_unpublished'] ?? FALSE);
    $limit = isset($options['entity_limit']) && $options['entity_limit'] !== NULL && $options['entity_limit'] !== ''
      ? (int) $options['entity_limit']
      : NULL;

    $query = $storage->getQuery()->accessCheck(FALSE)->sort($idKey, 'ASC');
    $ids = $query->execute();
    $entries = [];
    $pd_fields_by_bundle = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      $key = $entityTypeId . ':' . $entity->id();
      if (isset($pd_entity_ids[$key])) {
        continue;
      }
      // A pagedesigner-bearing entity is a composition page even when the
      // page limit kept it out of this run's pages — never a plain entity.
      $bundle = $entity->bundle();
      // Pagedesigner-ecosystem bundles are editor infrastructure, never
      // migratable content (see discoverMigrationPages).
      if (str_starts_with($bundle, 'pagedesigner')) {
        continue;
      }
      if (!array_key_exists($bundle, $pd_fields_by_bundle)) {
        $pd_fields_by_bundle[$bundle] = $this->getPagedesignerFields($entityTypeId, $bundle, NULL);
      }
      if ($pd_fields_by_bundle[$bundle]) {
        $all_langcodes = $this->getExportableLangcodes($entity, TRUE);
        if ($this->getPagedesignerRootsByField($entity, $pd_fields_by_bundle[$bundle], $all_langcodes)) {
          continue;
        }
      }
      $langcodes = $this->getExportableLangcodes($entity, $includeUnpublished);
      if (!$langcodes) {
        continue;
      }
      $titles = [];
      $paths = [];
      $statuses = [];
      foreach ($langcodes as $langcode) {
        $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
        $titles[$langcode] = $translation->label();
        $paths[$langcode] = $this->getEntityPath($translation, $langcode);
        $statuses[$langcode] = $translation instanceof EntityPublishedInterface ? $translation->isPublished() : TRUE;
      }
      $entry = [
        'id' => $key,
        'entity_type' => $entityTypeId,
        'bundle' => $entity->bundle(),
        'entity_id' => is_numeric($entity->id()) ? (int) $entity->id() : $entity->id(),
        'uuid' => method_exists($entity, 'uuid') ? $entity->uuid() : NULL,
        'default_langcode' => $entity->language()->getId(),
        'langcodes' => $langcodes,
        'titles' => $titles,
        'paths' => $paths,
        'statuses' => $statuses,
        'export_file' => 'entities/' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', $entityTypeId . '-' . $entity->id()) . '.json',
      ];
      $entry += $this->buildEntityMetadata($entity, $langcodes);
      $entries[] = $entry;
      if ($limit !== NULL && count($entries) >= $limit) {
        break;
      }
    }

    return $entries;
  }

  /**
   * Base and bookkeeping fields no migration ever maps.
   *
   * Identity, workflow flags and revision metadata: the manifest already
   * carries what matters (title, status, dates, author, path), and the rest is
   * storage plumbing.
   */
  protected const SKIP_FIELDS = [
    'nid', 'vid', 'id', 'uuid', 'type', 'uid', 'title', 'status', 'created',
    'changed', 'promote', 'sticky', 'default_langcode', 'langcode', 'path',
    'menu_link', 'comment', 'revision_timestamp', 'revision_uid',
    'revision_log', 'revision_default', 'revision_translation_affected',
    'content_translation_source', 'content_translation_outdated',
    'metatag',
  ];

  /**
   * Field types that are plugin plumbing, never migratable content.
   *
   * SEO modules attach these under project-specific names
   * (`field_meta_tags`, `field_yoast_seo`), so the type is the only stable
   * way to recognise them.
   */
  protected const SKIP_FIELD_TYPES = ['metatag', 'yoast_seo'];

  /**
   * One translation's own field values, enriched and optionally sanitized.
   *
   * Shared by plain entities and pagedesigner pages: a composition page's node
   * carries content of its own (lead text, teaser image, documents) that the
   * element tree knows nothing about, and the migration cannot map what was
   * never exported.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The entity in the language being exported.
   * @param string $langcode
   *   That language.
   * @param bool $sanitizeLocalUrls
   *   Whether to rewrite local URLs.
   * @param string[] $additionalSkipFields
   *   Extra field names to omit — a page's pagedesigner field, whose tree is
   *   exported separately and must not be duplicated here.
   *
   * @return array
   *   Field name => field values.
   */
  /**
   * Declared schema of the fields an entity exports.
   *
   * The values alone cannot be migrated into a target field: a consumer that
   * only sees `[{"value": "..."}]` has to guess the type, and guesses cannot
   * create a field. This exports what Drupal actually declares, so target
   * setup can create the counterpart without asking a human to retype it.
   *
   * Language-independent, so it is exported once per entity rather than per
   * translation.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity in its default language.
   * @param string[] $additionalSkipFields
   *   Extra field names to omit, matching collectEntityFields().
   *
   * @return array
   *   Field name => declared schema.
   */
  protected function collectFieldDefinitions(ContentEntityInterface $entity, array $additionalSkipFields = []): array {
    $skipFields = array_merge(self::SKIP_FIELDS, $additionalSkipFields);
    $definitions = [];
    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if (in_array($fieldName, $skipFields, TRUE) || str_starts_with($fieldName, 'revision_')) {
        continue;
      }
      if (in_array($definition->getType(), self::SKIP_FIELD_TYPES, TRUE)) {
        continue;
      }
      if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
        continue;
      }
      $settings = $definition->getSettings();
      $handlerSettings = $settings['handler_settings'] ?? [];
      $info = [
        'type' => $definition->getType(),
        // -1 means unlimited; the observed value count cannot reveal this.
        'cardinality' => (int) $definition->getFieldStorageDefinition()->getCardinality(),
        'label' => (string) $definition->getLabel(),
        // The editor-facing help text. A label says what a field is called,
        // this says what it is FOR — the one fact a reviewer mapping an
        // unfamiliar site's fields cannot reconstruct from a sample value.
        'description' => trim(strip_tags((string) $definition->getDescription())),
        'required' => (bool) $definition->isRequired(),
        'translatable' => (bool) $definition->isTranslatable(),
      ];
      if ($info['description'] === '') {
        unset($info['description']);
      }
      if (!empty($settings['target_type'])) {
        $info['target_type'] = (string) $settings['target_type'];
      }
      if (!empty($handlerSettings['target_bundles'])) {
        $info['target_bundles'] = array_values(array_map('strval', $handlerSettings['target_bundles']));
      }
      if (!empty($settings['max_length'])) {
        $info['max_length'] = (int) $settings['max_length'];
      }
      $definitions[$fieldName] = $info;
    }
    return $definitions;
  }

  protected function collectEntityFields(ContentEntityInterface $translation, string $langcode, bool $sanitizeLocalUrls, array $additionalSkipFields = []): array {
    $skipFields = array_merge(self::SKIP_FIELDS, $additionalSkipFields);
    $fields = [];
    foreach ($translation->getFieldDefinitions() as $fieldName => $definition) {
      if (in_array($fieldName, $skipFields, TRUE) || str_starts_with($fieldName, 'revision_')) {
        continue;
      }
      if (in_array($definition->getType(), self::SKIP_FIELD_TYPES, TRUE)) {
        continue;
      }
      if (!$translation->hasField($fieldName) || $translation->get($fieldName)->isEmpty()) {
        continue;
      }
      $values = $translation->get($fieldName)->getValue();
      $values = $this->enrichReferenceFieldValues($values, $definition, $langcode, $sanitizeLocalUrls);
      if ($sanitizeLocalUrls) {
        $values = $this->sanitizeFieldValues($values);
      }
      $fields[$fieldName] = $values;
    }
    return $fields;
  }

  /**
   * Export a plain (non-pagedesigner) entity's fields per translation.
   */
  public function exportPlainEntity(array $entry, bool $sanitizeLocalUrls): array {
    $storage = $this->entityTypeManager->getStorage((string) $entry['entity_type']);
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $storage->load($entry['entity_id']);

    $translations = [];
    foreach ($entry['langcodes'] as $langcode) {
      $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
      $translations[$langcode] = [
        'title' => $translation->label(),
        'status' => $translation instanceof EntityPublishedInterface ? $translation->isPublished() : TRUE,
        'fields' => $this->collectEntityFields($translation, $langcode, $sanitizeLocalUrls),
      ];
    }

    return [
      'schema_version' => self::MIGRATION_SCHEMA_VERSION,
      'id' => $entry['id'],
      'entity_type' => $entry['entity_type'],
      'bundle' => $entry['bundle'],
      'entity_id' => $entry['entity_id'],
      'uuid' => $entry['uuid'],
      'default_langcode' => $entry['default_langcode'],
      // Declared schema, once per entity: the values alone cannot tell a
      // consumer what field to create on the target.
      'field_definitions' => $this->collectFieldDefinitions($entity),
      'translations' => $translations,
    ];
  }

  /**
   * Export a pagedesigner element tree and all translations.
   *
   * @param int $elementId
   *   The root element ID to export.
   * @param string $langcode
   *   The default language code (will include all translations).
   * @param bool $sanitizeLocalUrls
   *   Whether absolute local URLs should be converted to relative paths.
   *
   * @return array
   *   Structured export data containing all elements and translations.
   *
   * @throws \Exception
   *   If element cannot be loaded.
   */
  public function export(int $elementId, string $langcode = 'de', bool $sanitizeLocalUrls = TRUE): array {
    /** @var \Drupal\pagedesigner\Entity\Element $root */
    $root = $this->entityTypeManager->getStorage('pagedesigner_element')->load($elementId);
    if (!$root) {
      throw new \Exception("Cannot load pagedesigner_element {$elementId}");
    }

    $logger = $this->loggerFactory->get('pagedesigner_export');
    $allLanguages = array_keys($this->languageManager->getLanguages());
    $defaultLangcode = $langcode;

    // Build a map of all element IDs in the tree for all available languages.
    $elementIds = [];
    foreach ($allLanguages as $langcode) {
      if ($root->hasTranslation($langcode)) {
        $visited = [];
        $elementIds = array_merge($elementIds, $this->collectElementIds($root, $langcode, $visited));
      }
    }
    $elementIds = array_values(array_unique($elementIds));
    $logger->notice("Exporting " . count($elementIds) . " elements from tree (root: " . $elementId . ").");

    // Export each element with all its translations.
    $data = [
      'root_id' => $elementId,
      'default_langcode' => $defaultLangcode,
      'exported_at' => time(),
      'elements' => [],
    ];

    foreach ($elementIds as $eid) {
      /** @var \Drupal\pagedesigner\Entity\Element $element */
      $element = $this->entityTypeManager->getStorage('pagedesigner_element')->load($eid);
      if (!$element) {
        $logger->warning("Could not load element " . $eid);
        continue;
      }

      $elementData = [];

      // Export each translation.
      foreach ($allLanguages as $lang) {
        if ($element->hasTranslation($lang)) {
          $translation = $element->getTranslation($lang);
          $elementData[$lang] = $this->exportTranslation($translation, $sanitizeLocalUrls);
        }
      }

      if ($elementData) {
        $data['elements'][$eid] = $elementData;
      }
    }

    $logger->notice('Exported ' . count($data['elements']) . ' elements.');
    return $data;
  }

  /**
   * Discover source pages/entities that contain pagedesigner root references.
   *
   * @param string $entityTypeId
   *   Content entity type ID.
   * @param array $options
   *   Discovery options.
   *
   * @return array
   *   Manifest page entries.
   *
   * @throws \Exception
   *   If the entity type cannot be exported.
   */
  protected function discoverMigrationPages(string $entityTypeId, array $options): array {
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if (!$entityType) {
      throw new \Exception("Unknown entity type: {$entityTypeId}");
    }
    if (!$entityType->entityClassImplements(ContentEntityInterface::class)) {
      throw new \Exception("Entity type is not a content entity: {$entityTypeId}");
    }

    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $bundleKey = $entityType->getKey('bundle');
    $idKey = $entityType->getKey('id') ?: 'id';
    $bundleFilter = $options['bundle'] ?? NULL;
    $fieldFilter = $options['field'] ?? NULL;
    $includeUnpublished = (bool) ($options['include_unpublished'] ?? FALSE);
    $sanitizeLocalUrls = (bool) ($options['sanitize_local_urls'] ?? TRUE);
    $limit = isset($options['limit']) && $options['limit'] !== NULL && $options['limit'] !== '' ? (int) $options['limit'] : NULL;
    $bundles = $this->getBundles($entityTypeId, $bundleFilter);
    $pages = [];

    foreach ($bundles as $bundle) {
      // Pagedesigner-ecosystem bundles (pagedesigner_part reusable snippets
      // etc.) are editor infrastructure, not site content — their markup is
      // already inlined where pages use them. Never migrate them.
      if (str_starts_with($bundle, 'pagedesigner')) {
        continue;
      }
      $pagedesignerFields = $this->getPagedesignerFields($entityTypeId, $bundle, $fieldFilter);
      if (!$pagedesignerFields) {
        continue;
      }

      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->sort($idKey, 'ASC');

      if ($bundleKey && $bundle !== $entityTypeId) {
        $query->condition($bundleKey, $bundle);
      }

      $fieldGroup = $query->orConditionGroup();
      foreach ($pagedesignerFields as $fieldName) {
        $fieldGroup->exists($fieldName);
      }
      $query->condition($fieldGroup);

      $remaining = $limit !== NULL ? $limit - count($pages) : NULL;
      if ($remaining !== NULL && $remaining <= 0) {
        break;
      }
      if ($remaining !== NULL) {
        $query->range(0, $remaining);
      }

      $entityIds = $query->execute();
      if (!$entityIds) {
        continue;
      }

      /** @var \Drupal\Core\Entity\ContentEntityInterface[] $entities */
      $entities = $storage->loadMultiple($entityIds);
      foreach ($entities as $entity) {
        $langcodes = $this->getExportableLangcodes($entity, $includeUnpublished);
        if (!$langcodes) {
          continue;
        }

        $rootsByField = $this->getPagedesignerRootsByField($entity, $pagedesignerFields, $langcodes);
        $rootCount = array_sum(array_map('count', $rootsByField));
        foreach ($rootsByField as $fieldName => $rootIds) {
          foreach ($rootIds as $rootId) {
            $pages[] = $this->buildPageManifestEntry($entity, $fieldName, (string) $rootId, $langcodes, $rootCount > 1, $sanitizeLocalUrls);
            if ($limit !== NULL && count($pages) >= $limit) {
              break 3;
            }
          }
        }
      }
    }

    $this->stampCompositionSize($pages);

    return $pages;
  }

  /**
   * Stamp each page with how many elements its composition root holds.
   *
   * A bundle can carry a PageDesigner field and still have nothing in it: on
   * the Trachtverein fleet every bundle has `field_pagedesigner_content`, and
   * 879 of 945 roots are childless. The consumer fetches one tree per page, so
   * without this it spends 879 round-trips discovering that those pages have
   * no composition — and the node's own fields, which is all they actually
   * carry, are already on this manifest entry.
   *
   * Two figures, answering two different questions:
   *
   * - `pagedesigner_child_count` — the root's DIRECT children. One field read
   *   per root, and enough to skip a tree fetch: a root with no children holds
   *   nothing, always.
   * - `pagedesigner_content_count` — the CONTENT elements anywhere under the
   *   root. This is the one a consumer classifies on. Every node with the field
   *   gets a root container whether or not anyone ever composed the page, and
   *   an editor who adds a row and deletes its contents leaves a child behind
   *   that carries nothing: on the direct count that page reads as composed,
   *   and a reviewer gets a row for a page with nothing on it.
   *
   * The recursive figure is no more expensive than the direct one, because
   * every descendant carries a denormalised root reference (`container`): one
   * grouped count over the whole site, no tree walk. It is omitted entirely
   * when the database is unavailable, and the consumer then falls back to the
   * direct count exactly as it did before this existed.
   */
  protected function stampCompositionSize(array &$pages): void {
    $rootIds = [];
    foreach ($pages as $page) {
      $rootId = (int) ($page['pagedesigner_root_id'] ?? 0);
      if ($rootId) {
        $rootIds[$rootId] = TRUE;
      }
    }
    if (!$rootIds) {
      return;
    }

    // One load for the whole site: per-page loads would trade the consumer's
    // round-trips for our own.
    $roots = $this->entityTypeManager->getStorage('pagedesigner_element')
      ->loadMultiple(array_keys($rootIds));
    $contentCounts = $this->contentElementCounts(array_keys($rootIds));

    foreach ($pages as &$page) {
      $rootId = (int) ($page['pagedesigner_root_id'] ?? 0);
      $root = $roots[$rootId] ?? NULL;
      if ($root === NULL) {
        continue;
      }
      $page['pagedesigner_child_count'] = $root->hasField('children')
        ? $root->get('children')->count()
        : 0;
      if ($contentCounts !== NULL) {
        $page['pagedesigner_content_count'] = $contentCounts[$rootId] ?? 0;
      }
    }
    unset($page);
  }

  /**
   * Content elements under each root, keyed by root id.
   *
   * @param int[] $rootIds
   *   The composition roots to count under.
   *
   * @return array<int,int>|null
   *   Root id → content element count, or NULL when the count cannot be taken
   *   (no database, or an element schema this query does not recognise). NULL
   *   means "not measured" and must not be read as zero.
   */
  protected function contentElementCounts(array $rootIds): ?array {
    if ($this->database === NULL || !$rootIds) {
      return NULL;
    }

    $table = 'pagedesigner_element_field_data';
    try {
      $schema = $this->database->schema();
      if (!$schema->tableExists($table)) {
        return NULL;
      }
      // Without these two the question cannot be asked at all; the caller then
      // falls back to the direct child count.
      foreach (['id', 'container', 'type'] as $column) {
        if (!$schema->fieldExists($table, $column)) {
          return NULL;
        }
      }
      $query = $this->database->select($table, 'e');
      $query->addField('e', 'container', 'root_id');
      // DISTINCT because the data table carries one row per translation and a
      // translated element would otherwise count once per language.
      $query->addExpression('COUNT(DISTINCT e.id)', 'total');
      $query->condition('e.container', $rootIds, 'IN');
      $query->condition('e.type', self::NON_CONTENT_ELEMENT_TYPES, 'NOT IN');
      // Soft-deleted elements are not rendered, so they are not content — the
      // same test the renderer applies. Skipped rather than fatal where the
      // column does not exist: over-counting keeps a page in the reviewer's
      // hands, which is the safe direction to be wrong in.
      if ($schema->fieldExists($table, 'deleted')) {
        $deleted = $query->orConditionGroup()
          ->condition('e.deleted', 0)
          ->isNull('e.deleted');
        $query->condition($deleted);
      }
      $query->groupBy('e.container');

      $counts = [];
      foreach ($query->execute() as $row) {
        $counts[(int) $row->root_id] = (int) $row->total;
      }
      return $counts;
    }
    catch (\Exception $error) {
      // A schema that does not match (an older pagedesigner, a different
      // storage backend) must not fail the manifest: the consumer degrades to
      // the direct child count.
      $this->loggerFactory->get('pagedesigner_export')->warning(
        'Could not count composition content elements: @message',
        ['@message' => $error->getMessage()],
      );
      return NULL;
    }
  }

  /**
   * Get bundle IDs for an entity type.
   *
   * @param string $entityTypeId
   *   Entity type ID.
   * @param string|null $bundleFilter
   *   Optional bundle filter.
   *
   * @return string[]
   *   Bundle IDs.
   */
  protected function getBundles(string $entityTypeId, ?string $bundleFilter): array {
    if ($bundleFilter) {
      return [$bundleFilter];
    }

    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
    return $bundleInfo ? array_keys($bundleInfo) : [$entityTypeId];
  }

  /**
   * Get pagedesigner entity reference fields for a bundle.
   *
   * @param string $entityTypeId
   *   Entity type ID.
   * @param string $bundle
   *   Bundle ID.
   * @param string|null $fieldFilter
   *   Optional field filter.
   *
   * @return string[]
   *   Field names.
   */
  protected function getPagedesignerFields(string $entityTypeId, string $bundle, ?string $fieldFilter): array {
    $fields = [];
    $definitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    foreach ($definitions as $fieldName => $definition) {
      if ($fieldFilter && $fieldName !== $fieldFilter) {
        continue;
      }
      if ($definition->getSetting('target_type') === 'pagedesigner_element' && in_array($definition->getType(), ['entity_reference', 'entity_reference_revisions', 'pagedesigner_item'], TRUE)) {
        $fields[] = $fieldName;
      }
    }

    return $fields;
  }

  /**
   * Get exportable language codes for an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity.
   * @param bool $includeUnpublished
   *   Whether unpublished translations are included.
   *
   * @return string[]
   *   Language codes.
   */
  protected function getExportableLangcodes(ContentEntityInterface $entity, bool $includeUnpublished): array {
    $defaultLangcode = $entity->language()->getId();
    $languages = [$defaultLangcode => $defaultLangcode];
    if ($entity->isTranslatable()) {
      foreach ($entity->getTranslationLanguages() as $langcode => $language) {
        $languages[$langcode] = $langcode;
      }
    }

    $exportable = [];
    foreach ($languages as $langcode) {
      $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
      if (!$includeUnpublished && $translation instanceof EntityPublishedInterface && !$translation->isPublished()) {
        continue;
      }
      $exportable[] = $langcode;
    }

    usort($exportable, static function (string $a, string $b) use ($defaultLangcode): int {
      if ($a === $defaultLangcode) {
        return -1;
      }
      if ($b === $defaultLangcode) {
        return 1;
      }
      return $a <=> $b;
    });

    return array_values(array_unique($exportable));
  }

  /**
   * Collect pagedesigner root IDs grouped by field for exportable translations.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity.
   * @param string[] $fieldNames
   *   Candidate pagedesigner fields.
   * @param string[] $langcodes
   *   Exportable language codes.
   *
   * @return array
   *   Root IDs keyed by field name.
   */
  protected function getPagedesignerRootsByField(ContentEntityInterface $entity, array $fieldNames, array $langcodes): array {
    $rootsByField = [];
    foreach ($fieldNames as $fieldName) {
      $rootIds = [];
      foreach ($langcodes as $langcode) {
        $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
        if (!$translation->hasField($fieldName) || $translation->get($fieldName)->isEmpty()) {
          continue;
        }
        foreach ($translation->get($fieldName)->getValue() as $item) {
          if (!empty($item['target_id'])) {
            $rootIds[(string) $item['target_id']] = (string) $item['target_id'];
          }
        }
      }
      if ($rootIds) {
        $rootsByField[$fieldName] = array_values($rootIds);
      }
    }

    return $rootsByField;
  }

  /**
   * Build a manifest page entry for one source entity/root pair.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity.
   * @param string $fieldName
   *   Pagedesigner field name.
   * @param string $rootId
   *   Pagedesigner root element ID.
   * @param string[] $langcodes
   *   Exported language codes.
   * @param bool $forceUniqueId
   *   Whether the page ID should include field/root details.
   *
   * @return array
   *   Manifest page entry.
   */
  protected function buildPageManifestEntry(ContentEntityInterface $entity, string $fieldName, string $rootId, array $langcodes, bool $forceUniqueId, bool $sanitizeLocalUrls = TRUE): array {
    $entityTypeId = $entity->getEntityTypeId();
    $entityId = (string) $entity->id();
    $defaultLangcode = $entity->language()->getId();
    $titles = [];
    $paths = [];
    $statuses = [];

    foreach ($langcodes as $langcode) {
      $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
      $titles[$langcode] = $translation->label();
      $paths[$langcode] = $this->getEntityPath($translation, $langcode);
      $statuses[$langcode] = $translation instanceof EntityPublishedInterface ? $translation->isPublished() : TRUE;
    }

    $pageId = "{$entityTypeId}:{$entityId}";
    if ($forceUniqueId) {
      $pageId .= ":{$fieldName}:root:{$rootId}";
    }

    $entry = [
      'id' => $pageId,
      'entity_type' => $entityTypeId,
      'bundle' => $entity->bundle(),
      'source_entity_id' => is_numeric($entityId) ? (int) $entityId : $entityId,
      'entity_id' => is_numeric($entityId) ? (int) $entityId : $entityId,
      'default_langcode' => $defaultLangcode,
      'languages' => $langcodes,
      'langcodes' => $langcodes,
      'titles' => $titles,
      'title' => $titles,
      'paths' => $paths,
      'path' => $paths,
      'status' => $statuses[$defaultLangcode] ?? reset($statuses),
      'statuses' => $statuses,
      'pagedesigner_root_id' => $rootId,
      'pagedesigner_field' => $fieldName,
      'export_file' => 'pages/' . $this->buildPageExportFilename($entityTypeId, $entityId, $fieldName, $rootId, $forceUniqueId),
    ];

    if (method_exists($entity, 'uuid')) {
      $entry['uuid'] = $entity->uuid();
    }

    $entry += $this->buildEntityMetadata($entity, $langcodes, $sanitizeLocalUrls, [$fieldName]);

    return $entry;
  }

  /**
   * Build schema 0.2.0 entity metadata for one manifest page entry.
   *
   * Everything here is additive and null-safe so 0.1.x consumers keep
   * working: dates and authorship per translation, taxonomy assignments,
   * menu placement, and redirects pointing at the entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity.
   * @param string[] $langcodes
   *   Exported language codes.
   *
   * @return array
   *   Metadata keys to merge into the manifest entry.
   */
  protected function buildEntityMetadata(ContentEntityInterface $entity, array $langcodes, bool $sanitizeLocalUrls = TRUE, array $skipFields = []): array {
    $created = [];
    $changed = [];
    $authors = [];
    $fields = [];
    foreach ($langcodes as $langcode) {
      $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
      $translationFields = $this->collectEntityFields($translation, $langcode, $sanitizeLocalUrls, $skipFields);
      if ($translationFields) {
        $fields[$langcode] = $translationFields;
      }
      if (method_exists($translation, 'getCreatedTime')) {
        $created[$langcode] = (int) $translation->getCreatedTime();
      }
      if ($translation instanceof EntityChangedInterface) {
        $changed[$langcode] = (int) $translation->getChangedTime();
      }
      if ($translation instanceof EntityOwnerInterface) {
        $owner = $translation->getOwner();
        $authors[$langcode] = [
          'uid' => (int) $translation->getOwnerId(),
          'name' => $owner ? $owner->getDisplayName() : NULL,
        ];
      }
    }

    $metadata = [];
    if ($created) {
      $metadata['created'] = $created;
    }
    if ($changed) {
      $metadata['changed'] = $changed;
    }
    if ($authors) {
      $metadata['authors'] = $authors;
    }
    // The node's own fields, per language. A composition page carries content
    // the element tree knows nothing about — lead text, teaser image,
    // documents — and the migration cannot map what was never exported.
    if ($fields) {
      $metadata['fields'] = $fields;
      $metadata['field_definitions'] = $this->collectFieldDefinitions($entity, $skipFields);
    }

    $taxonomies = $this->buildTaxonomyAssignments($entity);
    if ($taxonomies) {
      $metadata['taxonomies'] = $taxonomies;
    }
    $menuLinks = $this->buildMenuPlacement($entity);
    if ($menuLinks) {
      $metadata['menu_links'] = $menuLinks;
    }
    $redirects = $this->buildRedirects($entity);
    if ($redirects) {
      $metadata['redirects'] = $redirects;
    }

    return $metadata;
  }

  /**
   * Collect taxonomy-term assignments from the entity's reference fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity (default translation; term identity is shared).
   *
   * @return array
   *   One entry per populated taxonomy reference field.
   */
  protected function buildTaxonomyAssignments(ContentEntityInterface $entity): array {
    $assignments = [];
    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if ($definition->getSetting('target_type') !== 'taxonomy_term') {
        continue;
      }
      if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
        continue;
      }
      $terms = [];
      foreach ($entity->get($fieldName) as $item) {
        $term = $item->entity;
        if (!$term instanceof ContentEntityInterface) {
          continue;
        }
        $labels = [];
        foreach ($term->getTranslationLanguages() as $termLangcode => $language) {
          $labels[$termLangcode] = $term->getTranslation($termLangcode)->label();
        }
        $terms[] = [
          'tid' => is_numeric($term->id()) ? (int) $term->id() : $term->id(),
          'uuid' => method_exists($term, 'uuid') ? $term->uuid() : NULL,
          'name' => $term->label(),
          'labels' => $labels,
          'vocabulary' => $term->bundle(),
        ];
      }
      if ($terms) {
        $assignments[] = [
          'field' => $fieldName,
          'terms' => $terms,
        ];
      }
    }

    return $assignments;
  }

  /**
   * Collect menu placement for a node entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity.
   *
   * @return array
   *   Menu link entries ({menu_name, title, parent, weight, enabled}).
   */
  protected function buildMenuPlacement(ContentEntityInterface $entity): array {
    if (!$this->menuLinkManager || $entity->getEntityTypeId() !== 'node') {
      return [];
    }

    $links = [];
    try {
      $instances = $this->menuLinkManager->loadLinksByRoute('entity.node.canonical', ['node' => $entity->id()]);
    }
    catch (\Exception) {
      return [];
    }
    foreach ($instances as $instance) {
      $links[] = [
        'menu_name' => $instance->getMenuName(),
        'title' => (string) $instance->getTitle(),
        'parent' => $instance->getParent() ?: NULL,
        'weight' => (int) $instance->getWeight(),
        'enabled' => (bool) $instance->isEnabled(),
      ];
    }

    return $links;
  }

  /**
   * The source site's search setup, for a consumer deciding the target's.
   *
   * Search results on a Pagedesigner page are a views block over a Search
   * API index; the block id alone (`views_block__search_api_block_4`) does
   * not say so reliably, and it never says which backend serves it. The
   * servers' backend plugin ids (`search_api_solr`, `search_api_db`,
   * `elasticsearch_connector`, …) are what a target has to reproduce or
   * replace, so they travel in the manifest. Empty lists when Search API is
   * not installed; any storage failure degrades to the same shape.
   *
   * @return array
   *   {installed, backend_modules, servers[], indexes[], views[]}.
   */
  protected function searchSummary(): array {
    $summary = [
      'installed' => (bool) ($this->moduleHandler && $this->moduleHandler->moduleExists('search_api')),
      'backend_modules' => [],
      'servers' => [],
      'indexes' => [],
      'views' => [],
    ];
    if (!$summary['installed']) {
      return $summary;
    }
    foreach (['search_api_solr', 'search_api_db', 'elasticsearch_connector', 'search_api_opensearch', 'search_api_algolia'] as $module) {
      if ($this->moduleHandler->moduleExists($module)) {
        $summary['backend_modules'][] = $module;
      }
    }
    try {
      if ($this->entityTypeManager->hasDefinition('search_api_server')) {
        foreach ($this->entityTypeManager->getStorage('search_api_server')->loadMultiple() as $server) {
          $summary['servers'][] = [
            'id' => $server->id(),
            'label' => (string) $server->label(),
            'backend' => method_exists($server, 'getBackendId') ? (string) $server->getBackendId() : NULL,
            'status' => (bool) $server->status(),
          ];
        }
      }
      if ($this->entityTypeManager->hasDefinition('search_api_index')) {
        foreach ($this->entityTypeManager->getStorage('search_api_index')->loadMultiple() as $index) {
          $datasources = [];
          if (method_exists($index, 'getDatasources')) {
            foreach ($index->getDatasources() as $datasource) {
              $configuration = method_exists($datasource, 'getConfiguration') ? $datasource->getConfiguration() : [];
              $datasources[$datasource->getEntityTypeId()] = array_values($configuration['bundles']['selected'] ?? []);
            }
          }
          $summary['indexes'][] = [
            'id' => $index->id(),
            'label' => (string) $index->label(),
            'server' => method_exists($index, 'getServerId') ? $index->getServerId() : NULL,
            'status' => (bool) $index->status(),
            'datasources' => $datasources,
          ];
        }
      }
      if ($this->entityTypeManager->hasDefinition('view')) {
        foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
          $baseTable = (string) $view->get('base_table');
          if (!str_starts_with($baseTable, 'search_api_index_')) {
            continue;
          }
          $summary['views'][] = [
            'id' => $view->id(),
            'label' => (string) $view->label(),
            'index' => substr($baseTable, strlen('search_api_index_')),
            'status' => (bool) $view->status(),
            'displays' => array_keys((array) $view->get('display')),
          ];
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('pagedesigner_export')->warning('Search summary incomplete: @message', ['@message' => $e->getMessage()]);
    }
    return $summary;
  }

  /**
   * Collect redirects that point at the entity's canonical path.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity.
   *
   * @return array
   *   Redirect entries ({source, langcode, status_code}).
   */
  protected function buildRedirects(ContentEntityInterface $entity): array {
    if (!$this->moduleHandler || !$this->moduleHandler->moduleExists('redirect')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('redirect');

    $redirects = [];
    try {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('redirect_redirect__uri', [
          'internal:/' . $entity->getEntityTypeId() . '/' . $entity->id(),
          'entity:' . $entity->getEntityTypeId() . '/' . $entity->id(),
        ], 'IN')
        ->execute();
    }
    catch (\Exception) {
      return [];
    }
    foreach ($storage->loadMultiple($ids) as $redirect) {
      $source = method_exists($redirect, 'getSourcePathWithQuery') ? $redirect->getSourcePathWithQuery() : NULL;
      $redirects[] = [
        'source' => $source,
        'langcode' => $redirect->language()->getId(),
        'status_code' => method_exists($redirect, 'getStatusCode') ? (int) $redirect->getStatusCode() : NULL,
      ];
    }

    return $redirects;
  }

  /**
   * Build a stable page tree export filename.
   *
   * @param string $entityTypeId
   *   Entity type ID.
   * @param string $entityId
   *   Entity ID.
   * @param string $fieldName
   *   Pagedesigner field name.
   * @param string $rootId
   *   Root element ID.
   * @param bool $includeField
   *   Include the field name in the filename.
   *
   * @return string
   *   Filename relative to pages/.
   */
  protected function buildPageExportFilename(string $entityTypeId, string $entityId, string $fieldName, string $rootId, bool $includeField): string {
    $parts = [$entityTypeId, $entityId];
    if ($includeField) {
      $parts[] = $fieldName;
    }
    $parts[] = 'root';
    $parts[] = $rootId;

    return preg_replace('/[^A-Za-z0-9_.-]+/', '-', implode('-', $parts)) . '.json';
  }

  /**
   * Get a source entity path/alias for a language.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity translation.
   * @param string $langcode
   *   Language code.
   *
   * @return string|null
   *   Path or alias, if available.
   */
  protected function getEntityPath(ContentEntityInterface $entity, string $langcode): ?string {
    if ($entity->getEntityTypeId() === 'node') {
      return $this->aliasManager->getAliasByPath('/node/' . $entity->id(), $langcode);
    }

    try {
      if ($entity->hasLinkTemplate('canonical')) {
        return $entity->toUrl('canonical')->toString();
      }
    }
    catch (\Exception) {
      // Fall through to NULL for entities without usable canonical routes.
    }

    return NULL;
  }

  /**
   * Get the current request base URL when available.
   *
   * @return string|null
   *   Base URL or NULL.
   */
  protected function getBaseUrl(): ?string {
    try {
      $request = \Drupal::request();
      if ($request && $request->getHost()) {
        return $request->getSchemeAndHttpHost();
      }
    }
    catch (\Exception) {
      // CLI contexts may not have a meaningful request.
    }

    return NULL;
  }

  /**
   * Write a JSON file using the package JSON encoding contract.
   *
   * @param string $filePath
   *   Destination file path.
   * @param array $data
   *   Data to encode.
   *
   * @throws \Exception
   *   If encoding or writing fails.
   */
  protected function writeJsonFile(string $filePath, array $data): void {
    $directory = dirname($filePath);
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new \Exception("Failed to create directory: {$directory}");
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      throw new \Exception('JSON encoding failed: ' . json_last_error_msg());
    }

    if (file_put_contents($filePath, $json . "\n") === FALSE) {
      throw new \Exception("Failed to write file: {$filePath}");
    }
  }

  /**
   * Export a single element translation.
   *
   * @param \Drupal\pagedesigner\Entity\Element $element
   *   The element translation to export.
   * @param bool $sanitizeLocalUrls
   *   Whether absolute local URLs should be converted to relative paths.
   *
   * @return array
   *   The element data.
   */
  protected function exportTranslation(Element $element, bool $sanitizeLocalUrls = TRUE): array {
    $data = [
      'type' => $element->bundle(),
      'name' => $element->get('name')->value,
      'status' => (bool) $element->get('status')->value,
      'langcode' => $element->language()->getId(),
    ];

    // Export base structure fields explicitly, including empty values.
    if ($element->hasField('container')) {
      $data['container'] = $element->get('container')->target_id ?? NULL;
    }
    if ($element->hasField('parent')) {
      $data['parent'] = $element->get('parent')->target_id ?? NULL;
    }

    // Export all configurable fields.
    $fieldDefinitions = $element->getFieldDefinitions();
    $skipFields = [
      'id',
      'vid',
      'uuid',
      'type',
      'user_id',
      'name',
      'status',
      'created',
      'changed',
      'revision_translation_affected',
      'entity',
      'container',
      'parent',
      'children',
      'deleted',
      'default_langcode',
      'content_translation_source',
      'content_translation_outdated',
      'content_translation_uid',
      'content_translation_created',
      'content_translation_changed',
      'langcode',
    ];

    foreach ($fieldDefinitions as $fieldName => $definition) {
      if (in_array($fieldName, $skipFields)) {
        continue;
      }

      if ($element->hasField($fieldName)) {
        $values = $element->get($fieldName)->getValue();
        if (!empty($values)) {
          $values = $this->enrichReferenceFieldValues($values, $definition, $element->language()->getId(), $sanitizeLocalUrls);
          if ($sanitizeLocalUrls) {
            $values = $this->sanitizeFieldValues($values);
          }
          $data['fields'][$fieldName] = $values;
        }
      }
    }

    // Export children references.
    if ($element->hasField('children')) {
      $childrenValues = $element->get('children')->getValue();
      $data['children'] = array_map(static function ($item) {
        return [
          'target_id' => $item['target_id'],
        ];
      }, $childrenValues ?? []);
    }

    return $data;
  }

  /**
   * Collect all element IDs in a language-specific tree.
   *
   * @param \Drupal\pagedesigner\Entity\Element $element
   *   The root element.
   * @param string $langcode
   *   The language code to traverse.
   * @param array $visited
   *   Internal visited map to avoid loops.
   *
   * @return array
   *   Array of element IDs in the tree.
   */
  protected function collectElementIds(Element $element, string $langcode, array &$visited = []): array {
    // Always traverse the language-specific tree when translation exists.
    if ($element->hasTranslation($langcode)) {
      $element = $element->getTranslation($langcode);
    }

    $id = (int) $element->id();
    if (isset($visited[$id])) {
      return [];
    }
    $visited[$id] = TRUE;

    $ids = [$id];

    if ($element->hasField('children')) {
      foreach ($element->get('children') as $childRef) {
        if (!$childRef->entity) {
          continue;
        }
        $ids = array_merge($ids, $this->collectElementIds($childRef->entity, $langcode, $visited));
      }
    }

    // Also collect style references.
    if ($element->hasField('field_styles')) {
      foreach ($element->get('field_styles') as $styleRef) {
        if (!$styleRef->entity) {
          continue;
        }
        $ids = array_merge($ids, $this->collectElementIds($styleRef->entity, $langcode, $visited));
      }
    }

    return $ids;
  }

  /**
   * Add referenced media/file metadata to exported field payload values.
   *
   * The standalone migration runner needs downloadable source URLs for the
   * Drupal Migrate file/media stages. Pagedesigner fields often only store a
   * media target_id, so enrich these raw field values while keeping the source
   * item shape and target_id intact.
   *
   * @param array $values
   *   Raw field item values.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   Field definition.
   * @param string $langcode
   *   Current export language.
   * @param bool $sanitizeLocalUrls
   *   Whether local generated file URLs should be sanitized.
   *
   * @return array
   *   Enriched field item values.
   */
  protected function enrichReferenceFieldValues(array $values, FieldDefinitionInterface $definition, string $langcode, bool $sanitizeLocalUrls): array {
    $targetType = (string) $definition->getSetting('target_type');
    if (!in_array($targetType, ['media', 'file'], TRUE)) {
      return $values;
    }

    foreach ($values as $delta => $item) {
      if (!is_array($item) || empty($item['target_id'])) {
        continue;
      }

      $referencedEntity = $this->entityTypeManager->getStorage($targetType)->load($item['target_id']);
      if (!$referencedEntity instanceof ContentEntityInterface) {
        continue;
      }
      if ($referencedEntity->hasTranslation($langcode)) {
        $referencedEntity = $referencedEntity->getTranslation($langcode);
      }

      $metadata = $targetType === 'file'
        ? $this->buildFileReferenceMetadata($referencedEntity, NULL, $sanitizeLocalUrls)
        : $this->buildMediaReferenceMetadata($referencedEntity, $sanitizeLocalUrls);

      if (!$metadata) {
        continue;
      }

      $values[$delta]['referenced_entity'] = $metadata;
      $payloadFile = $this->pickMediaPayloadFile($metadata);
      if ($payloadFile) {
        $firstFile = $payloadFile;
        $values[$delta]['file_target_id'] = $firstFile['target_id'] ?? NULL;
        $values[$delta]['filename'] = $firstFile['filename'] ?? NULL;
        $values[$delta]['uri'] = $firstFile['uri'] ?? NULL;
        $values[$delta]['url'] = $firstFile['url'] ?? NULL;
        $values[$delta]['sourceUrl'] = $firstFile['url'] ?? NULL;
        $values[$delta]['mime_type'] = $firstFile['mime_type'] ?? NULL;
      }
      elseif ($targetType === 'file') {
        $values[$delta]['filename'] = $metadata['filename'] ?? NULL;
        $values[$delta]['uri'] = $metadata['uri'] ?? NULL;
        $values[$delta]['url'] = $metadata['url'] ?? NULL;
        $values[$delta]['sourceUrl'] = $metadata['url'] ?? NULL;
        $values[$delta]['mime_type'] = $metadata['mime_type'] ?? NULL;
      }
    }

    return $values;
  }

  /**
   * Build metadata for a referenced media entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $media
   *   Referenced media entity.
   * @param bool $sanitizeLocalUrls
   *   Whether local generated file URLs should be sanitized.
   *
   * @return array
   *   Media metadata with nested file references.
   */
  protected function buildMediaReferenceMetadata(ContentEntityInterface $media, bool $sanitizeLocalUrls): array {
    $files = [];
    foreach ($media->getFieldDefinitions() as $fieldName => $definition) {
      if (!$media->hasField($fieldName) || $media->get($fieldName)->isEmpty()) {
        continue;
      }

      $fieldType = $definition->getType();
      $targetType = (string) $definition->getSetting('target_type');
      if (!in_array($fieldType, ['image', 'file'], TRUE) && $targetType !== 'file') {
        continue;
      }

      foreach ($media->get($fieldName) as $item) {
        if (empty($item->target_id) || empty($item->entity) || !$item->entity instanceof ContentEntityInterface) {
          continue;
        }

        $fileMetadata = $this->buildFileReferenceMetadata($item->entity, $fieldName, $sanitizeLocalUrls);
        if ($fileMetadata) {
          $files[] = $fileMetadata + [
            'field_name' => $fieldName,
            'alt' => isset($item->alt) ? (string) $item->alt : '',
            'title' => isset($item->title) ? (string) $item->title : '',
          ];
        }
      }
    }

    return [
      'entity_type' => $media->getEntityTypeId(),
      'id' => is_numeric($media->id()) ? (int) $media->id() : $media->id(),
      'uuid' => method_exists($media, 'uuid') ? $media->uuid() : NULL,
      'bundle' => $media->bundle(),
      'label' => $media->label(),
      'langcode' => $media->language()->getId(),
      // `files` is field-definition ordered, so `thumbnail` — Drupal's
      // generated preview — can precede the media's real payload. Naming the
      // source field lets a consumer pick the file the media actually is.
      'source_field' => $this->getMediaSourceFieldName($media),
      'files' => $files,
    ];
  }

  /**
   * The file a media entity actually carries, not its generated thumbnail.
   *
   * @param array $metadata
   *   Media reference metadata from buildMediaReferenceMetadata().
   *
   * @return array|null
   *   The payload file entry, or NULL when the media carries no file.
   */
  protected function pickMediaPayloadFile(array $metadata): ?array {
    $files = array_values(array_filter($metadata['files'] ?? [], 'is_array'));
    if (!$files) {
      return NULL;
    }
    $sourceField = (string) ($metadata['source_field'] ?? '');
    if ($sourceField !== '') {
      foreach ($files as $file) {
        if (($file['field_name'] ?? '') === $sourceField) {
          return $file;
        }
      }
    }
    foreach ($files as $file) {
      if (($file['field_name'] ?? '') !== 'thumbnail') {
        return $file;
      }
    }
    return $files[0];
  }

  /**
   * The field a media type stores its actual file in.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $media
   *   The media entity.
   *
   * @return string
   *   The source field name, or '' when it cannot be resolved.
   */
  protected function getMediaSourceFieldName(ContentEntityInterface $media): string {
    if (!method_exists($media, 'getSource')) {
      return '';
    }
    try {
      $configuration = $media->getSource()->getConfiguration();
      return (string) ($configuration['source_field'] ?? '');
    }
    catch (\Throwable $exception) {
      return '';
    }
  }

  /**
   * Build metadata for a referenced file entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $file
   *   Referenced file entity.
   * @param string|null $fieldName
   *   Parent media/source field name, if available.
   * @param bool $sanitizeLocalUrls
   *   Whether local generated file URLs should be sanitized.
   *
   * @return array
   *   File metadata.
   */
  protected function buildFileReferenceMetadata(ContentEntityInterface $file, ?string $fieldName, bool $sanitizeLocalUrls): array {
    $uri = method_exists($file, 'getFileUri') ? $file->getFileUri() : ($file->hasField('uri') ? $file->get('uri')->value : NULL);
    $url = $uri ? $this->fileUrlGenerator->generateAbsoluteString($uri) : NULL;
    if ($url && $sanitizeLocalUrls) {
      $url = $this->sanitizeLocalUrl($url);
    }

    return [
      'entity_type' => $file->getEntityTypeId(),
      'field_name' => $fieldName,
      'target_id' => is_numeric($file->id()) ? (int) $file->id() : $file->id(),
      'uuid' => method_exists($file, 'uuid') ? $file->uuid() : NULL,
      'filename' => method_exists($file, 'getFilename') ? $file->getFilename() : $file->label(),
      'uri' => $uri,
      'url' => $url,
      'mime_type' => method_exists($file, 'getMimeType') ? $file->getMimeType() : NULL,
      'filesize' => method_exists($file, 'getSize') ? (int) $file->getSize() : NULL,
    ];
  }

  /**
   * Recursively sanitize field payload values.
   *
   * @param mixed $value
   *   The value to sanitize.
   *
   * @return mixed
   *   Sanitized value.
   */
  protected function sanitizeFieldValues(mixed $value): mixed {
    if (is_array($value)) {
      $sanitized = [];
      foreach ($value as $key => $item) {
        $sanitized[$key] = $this->sanitizeFieldValues($item);
      }

      if (isset($sanitized['attributes']) && is_array($sanitized['attributes']) && isset($sanitized['attributes']['href']) && is_string($sanitized['attributes']['href'])) {
        $sanitized['attributes']['href'] = $this->sanitizeLocalUrl($sanitized['attributes']['href']);
      }

      return $sanitized;
    }

    return $value;
  }

  /**
   * Convert absolute local URL to relative path.
   *
   * @param string $url
   *   The URL to sanitize.
   *
   * @return string
   *   The sanitized URL.
   */
  protected function sanitizeLocalUrl(string $url): string {
    $parts = parse_url($url);
    if ($parts === FALSE || empty($parts['host'])) {
      return $url;
    }

    $host = strtolower($parts['host']);
    $isLocalHost = $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.ddev.site');
    if (!$isLocalHost) {
      return $url;
    }

    $path = $parts['path'] ?? '/';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    return $path . $query . $fragment;
  }

}
