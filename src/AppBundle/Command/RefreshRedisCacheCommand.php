<?php

namespace AppBundle\Command;

use AppBundle\ApiCache\Strategy\GithubCacheStrategy;
use AppBundle\Repository\IssuesRepository;
use Doctrine\Common\Cache\PredisCache;
use GuzzleHttp\HandlerStack;
use Kevinrob\GuzzleCache\CacheEntry;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\DoctrineCacheStorage;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RefreshRedisCacheCommand extends ContainerAwareCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'redis:cache:refresh';

    protected function configure()
    {
        $this
            ->setDescription('Fetches new cache.')
            ->setHelp('This command checks for all caches with TTL less than 300 seconds and re-does the request to fetch new data');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Checking for redis keys');
        if (empty(getenv('REDIS_URL'))) {
            putenv('REDIS_URL=tcp://127.0.0.1:6379?database=0&persistent=1');
        }

        $stack = HandlerStack::create();
        $cacheMiddleware = new CacheMiddleware(
            new GithubCacheStrategy(
                new DoctrineCacheStorage(
                    new PredisCache(
                        new \Predis\Client(getenv('REDIS_URL'))
                    )
                )
            )
        );

        $client = new \GuzzleHttp\Client();
        $issuesRepository = new IssuesRepository($stack, $cacheMiddleware, $client);

        $redis = new Client(
            getenv('REDIS_URL')
        );

        $keys = $redis->keys('\[github*');
        foreach ($keys as $key) {
            $redisValue = $redis->get($key);
            $cacheKey = unserialize($redisValue);
            $cacheEntry = unserialize($cacheKey);

            /* @var $cacheEntry CacheEntry */
            $expiry = $cacheEntry->getStaleAt()->getTimestamp();
            $remainingSeconds = $expiry - time();

            if ($remainingSeconds < 300) {
                $output->writeln($key . ' should be recached (' . $remainingSeconds . ' s left)');

                $apiKey = $cacheEntry->getOriginalRequest()->getHeaders()['Authorization'][0];
                $apiKey = str_replace('token ', '', $apiKey);
                $uri = $cacheEntry->getOriginalRequest()->getUri();
                $urlToRecall = $uri->getScheme() . '://' . $uri->getHost() . $uri->getPath() . '?' . $uri->getQuery();

                $cacheMiddleware->getCacheStorage()->delete($cacheEntry->getOriginalRequest());
                $issuesRepository->callGithubUrl($urlToRecall, $apiKey, 'GET', false);
            } else {
                $output->writeln($key . ' should NOT be recached (' . $remainingSeconds . ' s left)');
            }
        }
    }
}
