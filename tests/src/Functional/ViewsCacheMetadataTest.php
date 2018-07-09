<?php

namespace Drupal\Tests\views_advanced_cache\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Component\Utility\NestedArray;
use Drupal\views\Views;
use Drupal\views\ViewExecutable;
use Drupal\views\Entity\View;

/**
 * Class ViewsCacheMetadataTest.
 *
 * @group views_advanced_cache
 */
class ViewsCacheMetadataTest extends BrowserTestBase {

  public static $modules = ['views', 'views_advanced_cache_test'];

  protected $strictConfigSchema = FALSE;

  // # Tests.
  /**
   * Test changing the view cache metadata.
   */
  public function testCacheMetadata() {
    $view_name = 'views_advanced_cache_test';
    $display_name = 'block_test';

    // Render the view with the default cache tags,
    $view = Views::getView($view_name);
    $element = $this->render($view, $display_name);
    // And verify that the default node entity_type list cache tag is present.
    $this->assertTrue(in_array('node_list', $element['#cache']['tags']), 'The view has the node_list cache tag.');

    // Load the view config entity,
    $cacheOptions = $this->getCacheOptions($view->storage, $display_name);
    // And update its cache tags.
    $cacheOptions = NestedArray::mergeDeep($cacheOptions, [
      'cache_tags' => ['vact:node_list:test'],
      'cache_tags_exclude' => ['node_list'],
    ]);
    $this->setCacheOptions($view->storage, $display_name, $cacheOptions);
    $view->storage->save();

    // Now re-render the view element and check its cache tags.
    $view = Views::getView($view_name);
    $element = $this->render($view, $display_name);
    $this->assertTrue(in_array('vact:node_list:test', $element['#cache']['tags']), 'The view has the vact:node_list:bundle cache tag.');
    $this->assertFalse(in_array('node_list', $element['#cache']['tags']), 'The view no longer has the node_list cache tag.');

    // Test adding / removing cache contexts.
    $query_args_context = in_array('url.query_args:page', $element['#cache']['contexts']) ? 'url.query_args:page' : 'url.query_args';
    $this->assertTrue(in_array($query_args_context, $element['#cache']['contexts']), 'The view has the url.query_args cache context.');
    $view = Views::getView($view_name);
    $cacheOptions = $this->getCacheOptions($view->storage, $display_name);
    $cacheOptions = NestedArray::mergeDeep($cacheOptions, [
      'cache_contexts' => ['url.query_args:test'],
      'cache_contexts_exclude' => [$query_args_context],
    ]);
    $this->setCacheOptions($view->storage, $display_name, $cacheOptions);
    $view->storage->save();

    $view = Views::getView($view_name);
    $element = $this->render($view, $display_name);
    $this->assertTrue(in_array('url.query_args:test', $element['#cache']['contexts']), 'The view has the url.query_args:test cache context.');
    $this->assertFalse(in_array($query_args_context, $element['#cache']['contexts']), 'The view no longer has the url.query_args cache context.');

    // Now let's cache the view for 600 seconds.
    $view = Views::getView($view_name);
    $cacheOptions = $this->getCacheOptions($view->storage, $display_name);
    $cacheOptions = NestedArray::mergeDeep($cacheOptions, [
      'results_lifespan' => 600,
      'output_lifespan' => 600,
    ]);
    $this->setCacheOptions($view->storage, $display_name, $cacheOptions);
    $view->storage->save();

    $view = Views::getView($view_name);
    $element = $this->render($view, $display_name);
    $this->assertEquals(600, $element['#cache']['max-age'], 'The view is cached for 600 seconds.');
  }

  /**
   * Test argument replacement in cache tags.
   */
  public function testArgumentReplacement() {
    $view_name = 'views_advanced_cache_test';
    $display_name = 'block_args';

    // Create some test nodes.
    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = [];
    for ($i = 1; $i <= 2; $i++) {
      $nodes[] = $this->drupalCreateNode(['title' => "Test $i", 'type' => 'test']);
    }
    $nid = $nodes[0]->id();

    // Render the view with the nid argument.
    $view = Views::getView($view_name);
    $element = $this->render($view, $display_name, [$nid]);
    // And verify that our test cache tag with the nid is present.
    $this->assertTrue(in_array("node_test:$nid", $element['#cache']['tags']), 'The view has the node_test{nid} cache tag.');
  }

  // # Helpers.
  /**
   * Build a renderable array for the view display.
   */
  protected function render(ViewExecutable $view, $display_name, array $args = []) {
    $view->setDisplay($display_name);
    if (!empty($args)) {
      $view->setArguments($args);
    }
    $view->preExecute();
    $view->execute($display_name);
    $element = $view->buildRenderable($display_name, $args);
    if (!empty($args)) {
      $element['#cache']['keys'][] = 'args';
      $element['#cache']['keys'][] = implode(',', $args);
    }
    return $element;
  }

  /**
   * Get the cache plugin options for a view display.
   */
  protected function getCacheOptions(View $view, $display_name) {
    return $view->getDisplay($display_name)['display_options']['cache']['options'] ?? [];
  }

  /**
   * Set the cache plugin options for a view display.
   */
  protected function setCacheOptions(View $view, $display_name, array $options) {
    $display = &$view->getDisplay($display_name);
    $display['display_options']['cache']['options'] = $options;
  }

}
