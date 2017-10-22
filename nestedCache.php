<?php
/**
 * CacheNestedDependencies (0.0.1)
 * This module manages caches and dependencies of nested caches.
 * 
 * @author Jan Kirchner
 * 
 * ProcessWire 2.x
 * Copyright (C) 2011 by Ryan Cramer
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 * 
 */

namespace ProcessWire;


class CacheNestedDependencies extends WireData implements Module
{
    /**
     * PW module info method
     */
    public static function getModuleInfo(){
        return array(
            'title' => "CacheNestedDependencies",
            'version' => "0.0.1",
            'summary' => "This module helps managing pages with multiple parts to be cached and different expiration.",
            'author' => "Jan Kirchner",
            'href' => "", // TODO: add url
            'icon' => "sitemap",

            'autoload' => true, // sure?
            'singular' => true,
            'requires' => "ProcessWire>=2.6"
        );
    }

    public function ready() {
        $this->addHookBefore("Page::render", $this, "hookInit");
        $this->addHookAfter("Page::render", $this, "hookFinish");   
    }
    
    /**
     * cache prefix
     */
    private static $cachePrefix = "cnd";

    /**
     * list of page's cache dependencies
     */
    protected static $dependencyList = array();

    /**
     * associative array to store the expiration value of every dependency cache
     */
    protected static $cacheAvailable = array();

    /**
     * stores the $cache->getInfo array
     */
    protected static $cacheAvailableVerbose = array();

    /**
     * associative array to store the status of every dependency cache
     * key: cache name
     * value: true if cache is available, otherwise false
     */
    protected static $cacheStatus = array();

    /**
     * current page's cache name
     */
    protected static $cachePage = "unknownPage";

    public static function hookInit($event) {
        $page = $event->arguments(0);
        $prefix = self::$cachePrefix;
        $title = wire()->sanitizer->pageName($page->title);
        $cacheName = "{$prefix}__{$page->id}-{$title}";

        self::initCacheStatus($cacheName);
    }

    public static function hookFinish($event) {
        self::saveCacheStatus();
    }

    /**
     * returns the current cache page name
     */
    public static function getCachePage() {
        return self::$cachePage;
    }

    /**
     * sets the current cache page name
     */
    public static function setCachePage($cachePage) {
        if (is_string($cachePage)) {
            self::$cachePage = $cachePage;
        }
    }

    /**
     * initializes dependency tree for the current page
     * to be called before all caching.
     */
    public static function initCacheStatus($pageName) {

        if (count(self::$dependencyList) > 0) {
            throw new WireException("Cannot initialize cache dependencies, because the dependency List is not empty.");
        }

        if (!is_string($pageName)){
            throw new WireException("Argument pageName was expected to be a string, but given type ". gettype($pageName));
        }

        self::$cachePage = $pageName;

        $cache = wire()->cache;
        $cache->maintenance(); // remove expired caches
        $cache->preloadFor($pageName);
        // load dependency tree from cache
        $cachedDeps = $cache->getFor($pageName, "cacheDependencyTree");
        if (is_array($cachedDeps)) {
            self::$dependencyList = $cachedDeps;
        }

        // load infos about cache 
        self::$cacheAvailableVerbose = $cache->getInfo(false);

        // create an associative array with cache names as keys and expirations as values
        self::$cacheAvailable = array_combine(
            array_map(function($ele) { return $ele["name"];}, self::$cacheAvailableVerbose),
            array_map(
                function($ele) {
                    $res = strtotime($ele["expires"]);
                    return $res ? $res - time() : $ele["expires"];
                },
                self::$cacheAvailableVerbose)
        );

        // keep only elements for the current page's cache
        self::$cacheAvailable = array_filter(
            self::$cacheAvailable,
            function($k) { return substr($k, 0, strlen(self::$cachePage)) == self::$cachePage; },
            ARRAY_FILTER_USE_KEY);
        
        // keep only elements which are not expired by now
        // NOTE: this is somehow neccessary because sometimes even expired caches are returned?!
        self::$cacheAvailable = array_filter(
            self::$cacheAvailable,
            function($v) { return is_string($v) || $v <  -60000000 || $v > 0; });

        self::traverseCacheDeps();
        bd(self::$cacheStatus, "cache status list");
        bd(self::$dependencyList, "deps after init");
    }

    /**
     * traverses the dependecy tree and checks every dependency if it is available
     * returns true if all dependencies are available, otherwise false
     */
    protected static function traverseCacheDeps($tree = null) {
        $cache = wire()->cache;

        $full_traverse = false;

        // if no tree given, use whole dependency list and traverse fully
        if (!is_array($tree)){
            $tree = self::$dependencyList;
            $full_traverse = true;
        }

        // iterate the dependency tree and check which dependencies are available
        $all_available = true;
        foreach ($tree as $depName => $depValue) {
            // skip "this" values
            if ($depName === "this")
                continue;

            // skip entries which are already checked as non-available
            if (array_key_exists($depName, self::$cacheStatus) && self::$cacheStatus[$depName] === false) {
                $all_available = false;
                // skip here if no full traverse is neccessary
                if (!$full_traverse)
                    return false;
                continue;
            }

            // only use cache if node is availabale and ALL children, too.
            $this_available = true;
            if (array_key_exists($depName, self::$cacheAvailable)) {
                if (is_array($depValue)){
                    // recursive call to traverse all child dependencies
                    if (!self::traverseCacheDeps($depValue)) {
                        $this_available = false;
                    }
                }
            }
            // this dependency is not available
            else {
                $this_available = false;
            }

            // if (array_key_exists($depName, self::$cacheStatus)){
            //     l("nestedCache::traverse(): checked item is aleady inside cacheStatus. name: $depName -  value: " . self::$cacheStatus[$depName] . " - newValue: $this_available");
            // }

            // add dependency and status to $cacheStatus 
            self::$cacheStatus[$depName] = $this_available;

            // remove from dependency tree if not available to avoid checking same dependency again
            if (!$this_available) {
                unset(self::$dependencyList[$depName]);
                // l("nestedCache::traverse(): cache not available: $depName");
                $all_available = false;
                // and delete straight from cache db
                if (!$cache->delete($depName))
                    $log->warning("failed deleting cache: $depName");
            }

            // skip if false and no full traverse needed
            if (!$all_available && !$full_traverse) {
                return false;
            }
        }

        // return true if all dependencies are available, otherwise false
        return $all_available;
    }

    /**
     * returns cache for specified cache name or null if expired or not available
     */
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

    /**
     * returns cache for specified cache name
     * or if cache is not available, executes cache function to create and save cache
     */
    public static function getCacheOrCreate($cacheName, $cacheFunction, $cacheExpire = null, $dependencies = array()) {
        $cacheData = self::getCache($cacheName);
        if (!$cacheData) {
            $cacheData = $cacheFunction();
            self::createCache($cacheName, $cacheData, $cacheExpire, $dependencies);
        }
        
        return $cacheData;
    }

    /**
     * saves the cache dependency tree to cache
     * to be called when all caching is done and output is created
     */
    public static function saveCacheStatus() {
        // create cache
        $cache = wire()->cache;
        $success = $cache->saveFor(self::$cachePage, "cacheDependencyTree", self::$dependencyList, WireCache::expireNever);
        if ($success)
            l("nestedCache: successfully saved cache dependency tree.");
        else
            db("failed saving cache dependency tree!");
    }

    /**
     * creates cache for a given input, only if not specified before
     * same arguments as ::createCache
     */
    public static function createCacheOnce($cacheName, $cacheData, $cacheExpire = null, $dependencies = array()) {
        $cacheFullName = self::$cachePage . "__" . $cacheName;
        if (!array_key_exists($cacheFullName, self::$dependencyList)) {
            self::createCache($cacheName, $cacheData, $cacheExpire, $dependencies);
        }
    }

    /**
     * creates cache for a given input data
     * param $cacheName: name of the cached input
     * param $cacheData: data to be cached
     * param $cacheExpire: expiration value, possible values specified by WireCache or null
     *      e.g. timestamp, seconds, Page object, Page ids, WireCache constants
     * param $dependencies: array of cache names this cache depends on
     *      thus, if any dependency expires, this cache will, too.
     */
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
            //bd(self::$dependencyList[$cacheFullName], "nestedCache dependency already exists: $cacheFullName");
            unset(self::$dependencyList[$cacheFullName]);

            // TODO: also remove entries referencing this entry to avoid deprecated values?
        }

        // if this cache is a non-leaf node
        if (count($dependencies) > 0) {
            // create dependencies array
            $thisDependencies = array();

            // if this cache has expiration, add a "this" entry to dependencies
            if ($cacheExpire) {
                $thisDependencies["this"] = $cacheExpire;
            }

            // add all specified dependencies
            foreach ($dependencies as $dependency) {
                $dependency = self::$cachePage . "__" . $dependency;
                // check if dependencies are available
                if (!array_key_exists($dependency, nestedCache::$dependencyList))
                    throw new WireException("nestedCache::createCache() : trying to reference a non-available dependency: $dependency. Dependencies must be cached before their dependent.");

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

    /**
     * returns the current page's dependency list
     */
    public static function getDep() {
        return nestedCache::$dependencyList;
    }
}