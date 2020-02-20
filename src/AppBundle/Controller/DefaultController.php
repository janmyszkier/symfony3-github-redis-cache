<?php

namespace AppBundle\Controller;

use AppBundle\ApiCache\Strategy\GithubCacheStrategy;
use AppBundle\Repository\IssuesRepository;
use Doctrine\Common\Cache\PredisCache;
use Exception\ApiException;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\DoctrineCacheStorage;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends Controller
{
    /**
     * @Route("/", name="homepage")
     */
    public function indexAction(Request $request)
    {
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

        $client = new Client();
        $issuesRepository = new IssuesRepository($stack, $cacheMiddleware, $client);
        try {
            $issues = $issuesRepository->getIssues('');
        } catch (\Exception $exception) {
            die($exception->getMessage());
        }

        // replace this example code with whatever you need
        return $this->render('default/index.html.twig', [
            'base_dir' => realpath($this->getParameter('kernel.project_dir')).DIRECTORY_SEPARATOR,
            'issues' => $issues,
        ]);
    }
}
