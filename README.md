# CacheNesting
A Processwire module that manages nested caches and their dependencies. It enables you to cache multiple parts of a page with varying expiration.

## Description
The CacheNesting module uses the Processwire WireCache class to manage nested caches and their varying expirations.
For example, you could cache your complete page output which consists of an header, body and footer. The header and footer will almost never expire and can be easily cached. Whereas, the body consists of an article cache which expires when the page is saved, and an RSS feed which should expire every 6 hours.
Since you cannot say which cache will expire next, with WireCache alone it is not possible to create a cache of the whole page which expires whenever a nested cache does.

The CacheNesting module stores dependencies for each cache, so if any of its dependencies expires the dependent cache will be recreated as well.

## How To Use
In your template files, call `CacheNesting::getCache` to receive the cached data and `CacheNesting::createCache` to store data to cache.

### Example
The following code shows the usecase of a page which consists of the data of its `body` field and its `dataThatChangesHourly` field. The `body`'s cache should expire whenever the page is saved. However, the field `dataThatChangesHourly` expires (as the name suggests) hourly.
The cache of the complete page depends on the caches of the two fields.

```php
<?php
    $html = CacheNesting::getCache("completePage");

    if (!$html) {
        $body = CacheNesting::getCache("body");
        if (!$body)
            $body = $page->body;
        $shortDated = CacheNesting::getCache("shortDated");
        if (!$shortDated)
            $shortDated = $page->dataThatChangesHourly;

        $html = $body . $shortDated;

        CacheNesting::createCache("body", $body, "id=$page->id");
        CacheNesting::createCache("shortDated", $shortDated, 3600);
        CacheNesting::createCache("completePage", $html, null, array("body", "shortDated"));
    }
    echo $html;
```

## Syntax
### Get Cache
Use `getCache` to get the cache (just the same as with WireCache).
```php
CacheNesting::getCache($cacheName)
```
**Arguments**
 - `$cacheName` (string): The name of the cache, e.g. "body".

**Return**
 - Returns the cached content, or `null` if cache is expired or not available.



### Save Cache
Use `createCache` to store data to the cache.
```php
CacheNesting::createCache($cacheName, $cacheData, $cacheExpire = null, $dependencies = array())
```
**Arguments**
 - `$cacheName` (string): The name of the cache, e.g. "body".
 - `$cacheData` (string, Array of non-object values, PageArray): Data to be cached.
 - *optional:* `$cacheExpire` (int, WireCache constant, string, null): Expiration of the cache as specified by `WireCache::save`:
   - Lifetime of this cache, in seconds, OR one of the following:
	 - Specify one of the `WireCache::expire*` constants. 
	 - Specify the future date you want it to expire (as unix timestamp or any `strtotime()` compatible date format)  
	 - Provide a `Page` object to expire when any page using that template is saved.  
	 - Specify `WireCache::expireNever` to prevent expiration.  
	 - Specify `WireCache::expireSave` to expire when any page or template is saved.   
	 - Specify selector string matching pages that–when saved–expire the cache. 
 - *optional:* `$dependencies` (Array): Array of dependency cache names, e.g. `array("dep1", "dep2")`.
