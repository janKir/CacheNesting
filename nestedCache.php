<?php
/**
 * User: Jan
 * Date: 25.09.2017
 * Time: 12:32
 */

namespace ProcessWire;


class nestedCache
{
    protected static $dependencyList = array();

    protected static $cacheAvailable = array();

    protected static $cacheAvailableVerbose = array();

    protected static $cacheStatus = array();

    protected static $cachePage = "unknownPage";

    public static function getCachePage() {
        return self::$cachePage;
    }

    public static function setCachePage($cachePage) {
        if (is_string($cachePage)) {
            self::$cachePage = $cachePage;
        }
    }

    public static function initCacheStatus($pageName) {

        if (count(self::$dependencyList) > 0) {
            throw new WireException("Cannot initialize cache dependencies, because the dependency List is not empty.");
        }

        if (!is_string($pageName)){
            throw new WireException("Argument pageName was expected to be a string, but given type ". gettype($pageName));
        }

        self::$cachePage = $pageName;
        //bd($pageName);

        $cache = wire()->cache;
        $cache->maintenance(); // remove expired caches
        $cache->preloadFor($pageName);
        $cachedDeps = $cache->getFor($pageName, "cacheDependencyTree");
        if (is_array($cachedDeps)) {
            self::$dependencyList = $cachedDeps;
        }

        bd(self::$dependencyList, "dependency list");

        self::$cacheAvailableVerbose = $cache->getInfo(false);
        bd(self::$cacheAvailableVerbose, "cache available verbose list");

        self::$cacheAvailable = array_combine(
            array_map(function($ele) { return $ele["name"];}, self::$cacheAvailableVerbose),
            array_map(
                function($ele) {
                    $res = strtotime($ele["expires"]);
                    return $res ? $res - time() : $ele["expires"];
                },
                self::$cacheAvailableVerbose)
        );
        self::$cacheAvailable = array_filter(
            self::$cacheAvailable,
            function($k) { return substr($k, 0, strlen(self::$cachePage)) == self::$cachePage; },
            ARRAY_FILTER_USE_KEY);
        self::$cacheAvailable = array_filter(
            self::$cacheAvailable,
            function($v) { return is_string($v) || $v <  -60000000 || $v > 0; });
        bd(self::$cacheAvailable, "cache available list: " . self::$cachePage);

        self::traverseCacheDeps();
        bd(self::$cacheStatus, "cache status list");
        bd(self::$dependencyList, "deps after init");
    }

    protected static function traverseCacheDeps($tree = null) {
        $cache = wire()->cache;

        $full_traverse = false;
        if (!is_array($tree)){
            $tree = self::$dependencyList;
            $full_traverse = true;
        }

        $all_available = true;
        foreach ($tree as $depName => $depValue) {
            // skip "this" values
            if ($depName === "this")
                continue;

            // skip entries which are already checked as non-available
            if (array_key_exists($depName, self::$cacheStatus) && self::$cacheStatus[$depName] === false) {
                $all_available = false;
                if (!$full_traverse)
                    return false;
                continue;
            }

            // only use cache if node is availabale and ALL children, too.
            $this_available = true;
            if (array_key_exists($depName, self::$cacheAvailable)) {
                if (is_array($depValue)){
                    // check dependencies
                    if (!self::traverseCacheDeps($depValue)) {
                        $this_available = false;
                    }
                }
            }
            else {
                $this_available = false;
            }

            if (array_key_exists($depName, self::$cacheStatus)){
                l("nestedCache::traverse(): checked item is aleady inside cacheStatus. name: $depName -  value: " . self::$cacheStatus[$depName] . " - newValue: $this_available");
            }

            self::$cacheStatus[$depName] = $this_available;

            if (!$this_available) {
                unset(self::$dependencyList[$depName]);
                l("nestedCache::traverse(): cache not available: $depName");
                $all_available = false;
                // and delete straight from cache db
                if (!$cache->delete($depName))
                    bd("failed deleting cache: $depName");
            }

            // skip if false and no full traverse needed
            if (!$all_available && !$full_traverse) {
                return false;
            }
        }

        return $all_available;
    }

    public static function getCache($cacheName) {
        if (!is_string($cacheName)){
            throw new WireException("Argument cacheName was expected to be a string, but given type ". gettype($cacheName));
        }

        $cacheFullName = self::$cachePage . "__" . $cacheName;
        if (array_key_exists($cacheFullName, self::$cacheStatus) && self::$cacheStatus[$cacheFullName] === true){
            $cache = wire()->cache;
            return $cache->getFor(self::$cachePage, $cacheName);
        }

        return null;
    }

    public static function saveCacheStatus() {
        // create cache
        $cache = wire()->cache;
        $success = $cache->saveFor(self::$cachePage, "cacheDependencyTree", self::$dependencyList, WireCache::expireNever);
        if ($success)
            l("nestedCache: successfully saved cache dependency tree.");
        else
            db("failed saving cache dependency tree!");
    }

    public static function createCacheOnce($cacheName, $cacheData, $cacheExpire = null, $dependencies = array()) {
        $cacheFullName = self::$cachePage . "__" . $cacheName;
        if (!array_key_exists($cacheFullName, self::$dependencyList)) {
            self::createCache($cacheName, $cacheData, $cacheExpire, $dependencies);
        }
    }

    public static function createCache($cacheName, $cacheData, $cacheExpire = null, $dependencies = array()) {
        if (!is_string($cacheName)){
            throw new WireException("Argument cacheName was expected to be a string, but given type ". gettype($cacheName));
        }

        $cacheFullName = self::$cachePage . "__" . $cacheName;

        // check input type of $dependencies
        if (!is_array($dependencies)) {
            if (is_string($dependencies))
                $dependencies = array ( $dependencies );
            else
                $dependencies = array();
        }

        // remove existing entry
        if (array_key_exists($cacheFullName, self::$dependencyList)) {
            bd(self::$dependencyList[$cacheFullName], "nestedCache dependency already exists: $cacheFullName");
            unset(self::$dependencyList[$cacheFullName]);

            // TODO: also remove entries referencing this entry to avoid deprecated values?
        }

        // if this cache is a non-leaf node
        if (count($dependencies) > 0) {
            // create dependencies array
            $thisDependencies = array();

            if ($cacheExpire) {
                $thisDependencies["this"] = $cacheExpire;
            }

            foreach ($dependencies as $dependency) {
                $dependency = self::$cachePage . "__" . $dependency;
                // check if dependencies are available
                if (!array_key_exists($dependency, nestedCache::$dependencyList))
                    throw new WireException("nestedCache::createCache() : trying to reference a non-available dependency: $dependency");

                // create nested dependency list
                $thisDependencies[$dependency] = nestedCache::$dependencyList[$dependency];
            }

        }
        // if this cache is a leaf node
        else {
            $thisDependencies = $cacheExpire;
        }

        // add dependencies to list
        nestedCache::$dependencyList[$cacheFullName] = $thisDependencies;

        // create cache
        $cache = wire()->cache;
        $cache->saveFor(self::$cachePage, $cacheName, $cacheData, $cacheExpire);
    }

    public static function getDep() {
        return nestedCache::$dependencyList;
    }
}