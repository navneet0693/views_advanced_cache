<?php

namespace Drupal\views_advanced_cache\Plugin\views\cache;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\views\Views;
use Drupal\views\Plugin\views\cache\CachePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Advanced cache metadata views cache plugin.
 *
 * Advanced caching of query results for Views displays allowing the
 * specification of cache tags, cache contexts, and output / results cache
 * lifetime (which is used to calculate max-age).
 *
 * @ingroup views_cache_plugins
 *
 * @ViewsCache(
 *   id = "advanced_views_cache",
 *   title = @Translation("Advanced Caching"),
 *   help = @Translation("Caching based on tags, context, and max-age. Caches will persist until any related cache tags are invalidated or the max-age is reached.")
 * )
 */
class AdvancedViewsCache extends CachePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesOptions = TRUE;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  protected $lifespans = [60, 300, 900, 1800, 3600, 21600, 43200, 86400, 604800];

  public $lifespanOptions = [];

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, DateFormatterInterface $date_formatter) {
    $this->dateFormatter = $date_formatter;

    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->lifespanOptions = array_map([$this->dateFormatter, 'formatInterval'], array_combine($this->lifespans, $this->lifespans));
    $this->lifespanOptions = [-1 => $this->t('Always cache'), 0 => $this->t('Never cache')] + $this->lifespanOptions + ['custom' => $this->t('Custom')];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function summaryTitle() {
    // Display a summary of: cache tags,
    $num_cache_tags = count(array_merge($this->options['cache_tags'], $this->options['cache_tags_exclude']) ?: []);
    $cache_tags = '';
    if ($num_cache_tags > 0) {
      $cache_tags = "$num_cache_tags tags";
    }

    // cache contexts,
    $num_cache_contexts = count(array_merge($this->options['cache_contexts'], $this->options['cache_contexts_exclude']) ?: []);
    $cache_contexts = '';
    if ($num_cache_contexts > 0) {
      $cache_contexts = "$num_cache_contexts contexts";
    }

    // and max-age.
    $results_lifespan = $this->getLifespan('results');
    $output_lifespan = $this->getLifespan('output');
    $lifetime = '';
    if ($results_lifespan >= 0 || $output_lifespan >= 0) {
      $lifetime = $this->dateFormatter->formatInterval($results_lifespan, 1) . '/' . $this->dateFormatter->formatInterval($output_lifespan, 1);
    }
    return implode(' | ', array_filter([$cache_tags, $cache_contexts, $lifetime])) ?: 'Always cache';
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    // Cache Tags.
    $options['cache_tags'] = ['default' => []];
    $options['cache_tags_exclude'] = ['default' => []];
    // Cache Contexts.
    $options['cache_contexts'] = ['default' => []];
    $options['cache_contexts_exclude'] = ['default' => []];
    // Max Age.
    $options['results_lifespan'] = ['default' => -1];
    $options['results_lifespan_custom'] = ['default' => NULL];
    $options['output_lifespan'] = ['default' => -1];
    $options['output_lifespan_custom'] = ['default' => NULL];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    self::buildCacheTagOptions($form, $form_state);
    self::buildCacheContextOptions($form, $form_state);
    self::buildLifespanOptions($form, $form_state);
  }

  /**
   * Build the cache metadata options form.
   *
   * By prefixing the tag with a "-" it will
   * be removed from the cache metadata. Removing cache tags should be
   * used sparingly but may be useful to resolve issues uneccessary cache tags
   * added by other modules
   * (ex. https://www.drupal.org/project/drupal/issues/2352175).
   *
   * @see https://www.drupal.org/project/views_custom_cache_tag
   */
  public function buildCacheTagOptions(array &$form, FormStateInterface $form_state) {
    $cache_tags_docs = Link::fromTextAndUrl('documentation', Url::fromUri('https://www.drupal.org/docs/8/api/cache-api/cache-tags'))->toString();
    $cache_tags_exclude = array_map(function ($c) { return '- ' . $c; }, $this->options['cache_tags_exclude']);
    // Filter out some of the default cache tags we don't care about.
    // This should mostly be the entity_type list cache tags ex. node_list.
    $default_cache_tags = array_diff(parent::getCacheTags(), ['extensions', 'config:views.view.' . $this->view->id()]);
    $cache_tags = !empty($this->options['cache_tags']) ? $this->options['cache_tags'] : $default_cache_tags;

    $form['cache_tags'] = [
      '#type' => 'details',
      '#title' => $this->t('Cache Tags'),
      '#description' => $this->t('Add cache tags for fine-grained view cache invalidation (see the official @docs for more information).', ['@docs' => $cache_tags_docs]),
      '#open' => TRUE,
    ];

    $form['cache_tags']['cache_tags'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cache Tags'),
      '#description' => $this->t('List cache tags (separated by new lines) that should invalidate the cache for this view, separated by new lines. Note that this does not control the invalidation of custom tags.'),
      '#default_value' => implode("\n", array_merge($cache_tags, $cache_tags_exclude)),
    ];

    $optgroup_arguments = (string) t('Arguments');

    $options = [];
    // $globalTokens = $this->getAvailableGlobalTokens(FALSE, ['current-user']);
    $options['current-user']['[current-user:uid]'] = $this->t('Current User: The current user id.');

    foreach ($this->view->display_handler->getHandlers('argument') as $arg => $handler) {
      $options[$optgroup_arguments]["{{ arguments.$arg }}"] = $this->t('@argument title', ['@argument' => $handler->adminLabel()]);
      $options[$optgroup_arguments]["{{ raw_arguments.$arg }}"] = $this->t('@argument input', ['@argument' => $handler->adminLabel()]);
    }

    // We have some options, so make a list.
    $items = [];
    if (!empty($options)) {
      $output['description'] = [
        '#markup' => '<p>' . $this->t("The following replacement tokens are available for this field. Note that due to rendering order, you cannot use fields that come after this field; if you need a field not listed here, rearrange your fields.") . '</p>',
      ];
      foreach (array_keys($options) as $type) {
        if (!empty($options[$type])) {
          foreach ($options[$type] as $key => $value) {
            $items[] = $key . ' == ' . $value;
          }
        }
      }
      $item_list = [
        '#theme' => 'item_list',
        '#items' => $items,
      ];
      $output['list'] = $item_list;
      $form['cache_tags']['tokens'] = $output;
    }
  }

  /**
   * Specify cache contexts to be used by the view.
   *
   * By prefixing the context with a "-" it will be removed
   * from the cache metadata.
   */
  public function buildCacheContextOptions(&$form, FormStateInterface $form_state) {
    $cache_context_docs = Link::fromTextAndUrl('documentation', Url::fromUri('https://www.drupal.org/docs/8/api/cache-api/cache-contexts'))->toString();
    $form['cache_contexts'] = [
      '#type' => 'details',
      '#title' => $this->t('Cache Contexts'),
      '#description' => $this->t('Add cache contexts to vary the cache according to their value (see the official @docs for more information).', ['@docs' => $cache_context_docs]),
      '#open' => TRUE,
    ];

    $cache_contexts_exclude = array_map(function ($c) { return '- ' . $c; }, $this->options['cache_contexts_exclude']);
    $form['cache_contexts']['cache_contexts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cache Contexts'),
      '#default_value' => implode("\n", array_merge($this->options['cache_contexts'], $cache_contexts_exclude)) ,
      '#description' => $this->t('List cache contexts (separated by new lines).'),
    ];
  }

  /**
   * Specify output and results cache lifetimes.
   *
   * @see \Drupal\views\Plugin\cache\Time
   */
  public function buildLifespanOptions(&$form, FormStateInterface $form_state) {
    $form['cache_lifespan'] = [
      '#type' => 'details',
      '#title' => $this->t('Max-Age and Results Cache'),
      '#description' => $this->t('Set caching of the view results and rendered html output. If set the <em>max-age</em> will be determined by the output lifetime.'),
      '#open' => TRUE,
    ];
    $form['cache_lifespan']['results_lifespan'] = [
      '#type' => 'select',
      '#title' => $this->t('Query results'),
      '#description' => $this->t('The length of time raw query results should be cached. When using display suite these results will only contain the node ids and other fields added to the view. The rendered entity will use the render cache.'),
      '#options' => $this->lifespanOptions,
      '#default_value' => $this->options['results_lifespan'],
    ];
    $form['cache_lifespan']['results_lifespan_custom'] = [
      '#type' => 'number',
      '#title' => $this->t('Seconds'),
      '#size' => '25',
      '#min' => -1,
      '#maxlength' => '30',
      '#description' => $this->t('Length of time in seconds raw query results should be cached.'),
      '#default_value' => $this->options['results_lifespan_custom'],
      '#states' => [
        'visible' => [
          ':input[name="cache_options[cache_lifespan][results_lifespan]"]' => ['value' => 'custom'],
        ],
      ],
    ];
    $form['cache_lifespan']['output_lifespan'] = [
      '#type' => 'select',
      '#title' => $this->t('Rendered output'),
      '#description' => $this->t('The length of time rendered HTML output should be cached.'),
      '#options' => $this->lifespanOptions,
      '#default_value' => $this->options['output_lifespan'],
    ];
    $form['cache_lifespan']['output_lifespan_custom'] = [
      '#type' => 'number',
      '#title' => $this->t('Seconds'),
      '#size' => '25',
      '#min' => -1,
      '#maxlength' => '30',
      '#description' => $this->t('Length of time in seconds rendered HTML output should be cached.'),
      '#default_value' => $this->options['output_lifespan_custom'],
      '#states' => [
        'visible' => [
          ':input[name="cache_options[cache_lifespan][output_lifespan]"]' => ['value' => 'custom'],
        ],
      ],
    ];
  }

  /**
   * Perform validation and cleanup of plugin configuration.
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    $lifespan = [];
    $cache_lifespan = $form_state->getValue(['cache_options', 'cache_lifespan']);
    // Validate lifespan format
    foreach (['output_lifespan', 'results_lifespan'] as $field) {
      $custom = $cache_lifespan[$field] == 'custom';
      if ($custom && !is_numeric($cache_lifespan[$field . '_custom'])) {
        $form_state->setError($form['cache_lifespan'][$field . '_custom'], $this->t('Custom time values must be numeric.'));
      }
      else {
        $lifespan[$field] = (int) ($custom ? $cache_lifespan[$field . '_custom'] : $cache_lifespan[$field]);
      }
    }

    // Require that output lifetime is < results lifetime.
    if (!empty($lifespan['output_lifespan']) && !empty($lifespan['results_lifespan']) &&
      $lifespan['results_lifespan'] >= 0 && $lifespan['output_lifespan'] > $lifespan['results_lifespan']) {
      $form_state->setError($form['cache_lifespan']['output_lifespan'], $this->t('Output lifespan must not be greater than results lifespan.'));
    }

    // Parse cache tags and cache contexts into arrays and separate out
    // the excluded tags and contexts.
    $cache_tags = $form_state->getValue(['cache_options', 'cache_tags', 'cache_tags']);
    $cache_tags = preg_split('/\r\n|[\r\n]+/', $cache_tags) ?: [];
    $cache_tags = array_filter(array_map('trim', $cache_tags));
    $cache_tags_exclude = [];
    foreach ($cache_tags as $i => $cache_tag) {
      if (strpos($cache_tag, '-') === 0) {
        unset($cache_tags[$i]);
        $cache_tags_exclude[] = trim($cache_tag, '- ');
      }
    }
    $form_state->setValue(['cache_options', 'cache_tags', 'cache_tags'], $cache_tags);
    $form_state->setValue(['cache_options', 'cache_tags', 'cache_tags_exclude'], $cache_tags_exclude);

    $cache_contexts = $form_state->getValue(['cache_options', 'cache_contexts', 'cache_contexts']);
    $cache_contexts = preg_split('/\r\n|[\r\n]+/', $cache_contexts) ?: [];
    $cache_contexts = array_filter(array_map('trim', $cache_contexts));
    $cache_contexts_exclude = [];
    foreach ($cache_contexts as $i => $context) {
      if (strpos($context, '-') === 0) {
        unset($cache_contexts[$i]);
        $cache_contexts_exclude[] = trim($context, '- ');
      }
    }
    $form_state->setValue(['cache_options', 'cache_contexts', 'cache_contexts'], $cache_contexts);
    $form_state->setValue(['cache_options', 'cache_contexts', 'cache_contexts_exclude'], $cache_contexts_exclude);
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    // Remap values onto the top of the tree in 'cache_options'.
    foreach (['cache_tags', 'cache_contexts', 'cache_lifespan'] as $key) {
      $values = $form_state->getValue(['cache_options', $key]);
      $form_state->unsetValue(['cache_options', $key]);
      foreach ($values as $option => $value) {
        $form_state->setValue(['cache_options', $option], $value);
      }
    }

    parent::submitOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $cache_tags = $this->options['cache_tags'] ?: [];
    // @see Drupal\views\ViewExecutable::_postExecute
    // By default only handlers (ex. argument, filter, sort) are invoked after
    // view execution to update the cache tags based on rows.
    // We are also checking if a plugin is a CacheableDependencyInterface then
    // add it's cache_tags.
    foreach (Views::getPluginTypes('plugin') as $plugin_type) {
      $plugin = $this->view->display_handler->getPlugin($plugin_type);
      if ($plugin instanceof CacheableDependencyInterface) {
        $cache_tags = Cache::mergeTags($cache_tags, $plugin->getCacheTags());
      }
    }
    if (!empty($cache_tags)) {
      $default_cache_tags = parent::getCacheTags();
      $cache_tags = array_map(function ($tag) {
        $value = $this->view->getStyle()->tokenizeValue($tag, 0);
        return \Drupal::token()->replace($value);
      }, $cache_tags);
      $cache_tags = Cache::mergeTags($cache_tags, $default_cache_tags);
    }
    else {
      $cache_tags = parent::getCacheTags();
    }

    // Remove cache tags marked for exclusion.
    if (!empty($this->options['cache_tags_exclude'])) {
      $cache_tags_exclude = $this->options['cache_tags_exclude'];
      $cache_tags = array_diff($cache_tags, $cache_tags_exclude);
    }

    return $cache_tags;
  }

  /**
   * {@inheritdoc}
   */
  public function alterCacheMetadata(CacheableMetadata $cache_metadata) {
    if (!empty($this->options['cache_contexts'])) {
      $cache_metadata->addCacheContexts($this->options['cache_contexts']);
    }
    if (!empty($this->options['cache_contexts_exclude'])) {
      $contexts = $cache_metadata->getCacheContexts();
      $contexts = array_diff($contexts, $this->options['cache_contexts_exclude']);
      $cache_metadata->setCacheContexts($contexts);
    }
  }

  /**
   * Get the cache lifetime for results or output.
   *
   * @param string|int $type
   *   A value of "results" or "output" for the corresponding cache.
   *
   * @return int
   *   The cache lifespan.
   */
  protected function getLifespan($type) {
    $lifespan = $this->options[$type . '_lifespan'] == 'custom' ? $this->options[$type . '_lifespan_custom'] : $this->options[$type . '_lifespan'];
    return (int) $lifespan;
  }

  /**
   * {@inheritdoc}
   */
  protected function cacheExpire($type) {
    $lifespan = $this->getLifespan($type);
    if ($lifespan >= 0) {
      $cutoff = REQUEST_TIME - $lifespan;
      return $cutoff;
    }
    else {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function cacheSetMaxAge($type) {
    $lifespan = $this->getLifespan($type);
    if ($lifespan >= 0) {
      return $lifespan;
    }
    else {
      return Cache::PERMANENT;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultCacheMaxAge() {
    // The max age, unless overridden by some other piece of the rendered code
    // is determined by the output time setting.
    return (int) $this->cacheSetMaxAge('output');
  }

}
