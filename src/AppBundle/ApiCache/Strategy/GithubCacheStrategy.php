<?php

namespace AppBundle\ApiCache\Strategy;

use Kevinrob\GuzzleCache\CacheEntry;
use Kevinrob\GuzzleCache\KeyValueHttpHeader;
use Kevinrob\GuzzleCache\Storage\CacheStorageInterface;
use Kevinrob\GuzzleCache\Strategy\PrivateCacheStrategy;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class GithubCacheStrategy extends PrivateCacheStrategy
{
    public function __construct(CacheStorageInterface $cache = null)
    {
        parent::__construct($cache);
        array_unshift($this->ageKey, 's-maxage');
    }

    /**
     * {@inheritdoc}
     */
    protected function getCacheObject(RequestInterface $request, ResponseInterface $response)
    {
        $method = $request->getMethod();

        if($response->getStatusCode() !== 403){
            $remaining = $response->getHeader('X-RateLimit-Remaining');
            if($remaining == 0) {
                $time = $response->getHeader('X-RateLimit-Reset');
                return new CacheEntry($request, $response, new \DateTime($time));
            }
        }

        if($method=='DELETE' || $method =='POST'){
            return new CacheEntry($request, $response, new \DateTime('-1 second'));
        }

        if($response->getStatusCode() === 500){
            return new CacheEntry($request, $response, new \DateTime('-1 second'));
        }

        if(stristr($request->getUri(),'users')) {
            return new CacheEntry($request, $response, new \DateTime('+24 hours'));
        }

        if(stristr($request->getUri(),'repos')) {
            return new CacheEntry($request, $response, new \DateTime('+6 hours'));
        }

        /* everything else: lifetime of 15 minutes */
        return new CacheEntry($request, $response, new \DateTime('+15 minutes'));
    }

    public function getCacheKey(RequestInterface $request, KeyValueHttpHeader $varyHeaders = null)
    {
        $key = preg_replace("/[^A-Za-z0-9-_\. ]/", '-', $request->getUri());
        return 'github:'.$key;
    }

    public function fetch(RequestInterface $request)
    {
        /** @var int|null $maxAge */
        $maxAge = null;

        if ($request->hasHeader('Cache-Control')) {
            $reqCacheControl = new KeyValueHttpHeader($request->getHeader('Cache-Control'));
            if ($reqCacheControl->has('no-cache')) {
                // Can't return cache
                return null;
            }

            $maxAge = $reqCacheControl->get('max-age', null);
        } elseif ($request->hasHeader('Pragma')) {
            $pragma = new KeyValueHttpHeader($request->getHeader('Pragma'));
            if ($pragma->has('no-cache')) {
                // Can't return cache
                return null;
            }
        }

        $cache = $this->storage->fetch($this->getCacheKey($request));
        if ($cache !== null) {
            $varyHeaders = $cache->getVaryHeaders();

            // vary headers exist from a previous response, check if we have a cache that matches those headers
            if (!$varyHeaders->isEmpty()) {
                $cache = $this->storage->fetch($this->getCacheKey($request, $varyHeaders));

                if (!$cache) {
                    return null;
                }
            }

            if ((string)$cache->getOriginalRequest()->getUri() !== (string)$request->getUri()) {
                return null;
            }

            if ($maxAge !== null) {
                if ($cache->getAge() > $maxAge) {
                    // Cache entry is too old for the request requirements!
                    return null;
                }
            }

            if (!$cache->isVaryEquals($request)) {
                return null;
            }
        }

        return $cache;
    }

    public function cache(RequestInterface $request, ResponseInterface $response)
    {
        $reqCacheControl = new KeyValueHttpHeader($request->getHeader('Cache-Control'));
        if ($reqCacheControl->has('no-store')) {
            // No caching allowed
            return false;
        }

        $cacheObject = $this->getCacheObject($request, $response);
        if ($cacheObject !== null) {
            // store the cache against the URI-only key
            $success = $this->storage->save(
                $this->getCacheKey($request),
                $cacheObject
            );

            $varyHeaders = $cacheObject->getVaryHeaders();

            if (!$varyHeaders->isEmpty()) {
                // also store the cache against the vary headers based key
                $success = $this->storage->save(
                    $this->getCacheKey($request, $varyHeaders),
                    $cacheObject
                );
            }

            return $success;
        }

        return false;
    }

    public function delete(RequestInterface $request)
    {
        return $this->storage->delete($this->getCacheKey($request));
    }
}
