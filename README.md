Views Advanced Cache
====================

The views advanced cache module provides a cache plugin that allows more precise manipulation of cache tags and cache contexts.
It is intended for advanced technical users with an understanding of the Drupal 8 
[Cache API](https://www.drupal.org/docs/8/api/cache-api/cache-api). Misconfiguration can lead to incorrect or 
stale cache results including bypassing of standard content access restrictions.  


Features
========
* A views cache plugin that allows adding and removing cache metadata (contexts, tags, max-age).
* Dynamic cache tags based on argument token replacements.

Requirements
============
* The only requirement is views.

Configuration
============
This module exposes an _Advanced Caching_ cache plugin on the view display under the *Advanced* section *Caching* settings.

Example Use Cases
=================
## Override the node_list cache tag

By default views adds a node_list cache tag to all node views and invalidates cache entries with this tag whenever 
*ANY* node is created or updated. By overriding the default `node_list` cache tag with a bundle-specific alternative 
we can improve the cache HIT rate of views when unrelated content is saved.

The code below will invalidate the custom cache tag on node CRUD events.
```
/**
 * Invalidate a "my_custom:node_list:{bundle}" cache tag on node save.
 */
function my_custom_module_node_presave(NodeInterface $node) {
  $tags = ['my_custom:node_list:' . $node->getType()];
  Cache::invalidateTags($tags);
}
```

And the below cache tag settings will cache a view until page nodes are saved.
```
- node_list
my_custom:node_list:page
```

## Add / Remove cache contexts

Modifying cache contexts is a riskier proposition as incorrect configuration may result in incorrect results and not
just stale cached results. *USE WITH CAUTION* a solid knowledge of the cache api 
[cache contexts](https://www.drupal.org/docs/8/api/cache-api/cache-contexts) and views cache configs is recommended. 

This option allows altering of a view display plugin's cache_metadata contexts. An example use is to limit the 
`url.query_args` in a rest_export view as below:
```
- url.query_args
url.query_args:page
url.query_args:items_per_page
url.query_args:offset
url.query_args:my_custom_filter
``` 

This can be used to override the default pager caching per all page query arguments instead only considering those
relevent to the view.

Related Modules
===============
* [Views Custom Cache Tags](https://www.drupal.org/project/views_custom_cache_tag)

Maintainers
===========
* [Malcolm Poindexter (malcolm_p)](https://www.drupal.org/u/malcolm_p)
