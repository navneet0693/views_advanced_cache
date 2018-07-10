<?php

namespace Drupal\Tests\views_advanced_cache\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Url;
use Drupal\Tests\system\Functional\Cache\AssertPageCacheContextsAndTagsTrait;
use Drupal\views\Entity\View;

/**
 * Class PageCacheTest.
 *
 * @group views_advanced_cache
 */
class PageCacheTest extends BrowserTestBase {

  use AssertPageCacheContextsAndTagsTrait;

  public static $modules = ['views', 'views_advanced_cache_test'];

  protected $strictConfigSchema = FALSE;

  /**
   * Test page response cache tags for a view.
   */
  public function testPageCache() {
    // Create some test content,
    $nodes = [];
    for ($i = 1; $i <= 3; $i++) {
      $nodes[] = $this->drupalCreateNode(['title' => "Test $i", 'type' => 'test']);
    }
    $node_cache_tags = [];
    foreach ($nodes as $node) {
      $node_cache_tags[] = 'node:' . $node->id();
    }

    // And load the page view with our test cache contexts and tags.
    $cache_contexts = [
      'languages:language_content',
      'languages:language_interface',
      'theme',
      'user',
      'url.query_args:_wrapper_format',
    ];
    $cache_tags = [
      'config:user.role.anonymous',
      'config:views.view.views_advanced_cache_test',
      'http_response',
      'rendered',
      'user:0',
      'vact:node_list:test',
    ];

    $url = Url::fromRoute('view.views_advanced_cache_test.page_test', []);
    $this->assertPageCacheContextsAndTags($url, $cache_contexts, array_merge($cache_tags, $node_cache_tags));

    // Then remove our changes to the contexts and tags,
    $display_name = 'page_test';
    /** @var Drupal\views\Entity\Vie $view */
    $view = View::load('views_advanced_cache_test');
    $cache_options = $view->getDisplay($display_name)['display_options']['cache']['options'] ?? [];
    $cache_options['cache_tags'] = [];
    $cache_options['cache_tags_exclude'] = [];
    $cache_options['cache_contexts'] = [];
    $cache_options['cache_contexts_exclude'] = [];
    $display = &$view->getDisplay($display_name);
    $display['display_options']['cache']['options'] = $cache_options;
    $view->save();

    // And request the view again.
    $cache_contexts = [
      'languages:language_content',
      'languages:language_interface',
      'theme',
      'user.permissions',
      'user.node_grants:view',
      'url.query_args:_wrapper_format',
    ];
    $cache_tags = [
      'config:user.role.anonymous',
      'config:views.view.views_advanced_cache_test',
      'http_response',
      'rendered',
      'node_list',
    ];

    $url = Url::fromRoute('view.views_advanced_cache_test.page_test', []);
    $this->assertPageCacheContextsAndTags($url, $cache_contexts, array_merge($cache_tags, $node_cache_tags));
  }

}
