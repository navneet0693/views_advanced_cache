<?php

namespace Drupal\Tests\views_advanced_cache\Functional;

use Drupal;
use Drupal\Tests\BrowserTestBase;
use Drupal\Component\Utility\NestedArray;
use Drupal\views\Views;
use Drupal\views\ViewExecutable;
use Drupal\views\Entity\View;

/**
 * Class ViewsCacheMetadataTest
 *
 * @group views_advanced_cache
 */
class ViewsCacheMetadataTest extends BrowserTestBase {

  public static $modules = ['views', 'views_advanced_cache_test'];

  protected $strictConfigSchema = FALSE;

  public function testCacheMetadata() {
    $view_name = 'views_advanced_cache_test';
    $display_name = 'block_test';

    // Render the view with the default cache tags,

    $view = Views::getView($view_name);
    $view->setDisplay($display_name);
    $element = $this->render($view);
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
    $view->setDisplay($display_name);
    $element = $this->render($view);
    $this->assertFalse(in_array('node_list', $element['#cache']['tags']), 'The view no longer has the node_list cache tag.');
    $this->assertTrue(in_array('vact:node_list:test', $element['#cache']['tags']), 'The view has the vact:node_list:bundle cache tag.');
  }

  /**
   * @param $view
   * @return array
   */
  protected function render(ViewExecutable $view) {
    $view->preExecute();
    $view->execute();
    return $view->buildRenderable();
  }

  /**
   * @param $view
   * @return mixed
   */
  protected function getCacheOptions(View $view, $display_name) {
    return $view->getDisplay($display_name)['display_options']['cache']['options'] ?? [];
  }

  /**
   * @param $view
   * @param array $options
   */
  protected function setCacheOptions(View $view, $display_name, array $options) {
    $display = &$view->getDisplay($display_name);
    $display['display_options']['cache']['options'] = $options;
  }

  public function _testArgumentReplacement() {
    // TODO
    $this->markTestIncomplete();
  }

  public function _testPageCache() {
    // TODO
    $this->markTestIncomplete();
  }


}
