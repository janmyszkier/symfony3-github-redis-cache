<?php

namespace AppBundle\Controller;

use AppBundle\ApiCache\Strategy\GithubCacheStrategy;
use Doctrine\Common\Cache\PredisCache;
use Exception\ApiException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\DoctrineCacheStorage;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends Controller
{
    /**
     * @Route("/", name="homepage")
     */
    public function indexAction(Request $request)
    {
        $issues = $this->getIssues();

        // replace this example code with whatever you need
        return $this->render('default/index.html.twig', [
            'base_dir' => realpath($this->getParameter('kernel.project_dir')).DIRECTORY_SEPARATOR,
            'issues' => $issues,
        ]);
    }

    public function callGithubUrl($url, $method = 'GET', $params = null)
    {
        if(empty(getenv('REDIS_URL'))){
            putenv('REDIS_URL=tcp://127.0.0.1:6379?database=0&persistent=1');
        }

        $apiToken ='';
        $headers = [
            'Authorization' => 'token '.$apiToken,
            'User-Agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13',
            'Accept' => 'application/json',
        ];
        // Create default HandlerStack
        $stack = HandlerStack::create();
        if(!empty(getenv('REDIS_URL'))){
            $stack->push(
                new CacheMiddleware(
                    new GithubCacheStrategy(
                        new DoctrineCacheStorage(
                            new PredisCache(
                                new \Predis\Client(getenv('REDIS_URL'))
                            )
                        )
                    )
                ),
                'cache'
            );
        }

        $client = new \GuzzleHttp\Client([
            'handler' => $stack,
            'headers'=> $headers
        ]);

        if (!$apiToken) {
            throw new \Exception('API token missing', 401);
        }

        try {
            $options = [
                /* re-adding handler here is necessary for \GuzzleHttp\Client::transfer to pick it up */
                'handler' => $stack,
                'headers' => $headers,
            ];
            $response = $client->request($method, $url,$options);
        } catch (\Exception $e){
            var_dump($url);
            var_dump($method);
            //var_dump($options);
            die($e->getMessage());
        }

        $currentPageItems = json_decode($response->getBody());
        if (!is_array($currentPageItems)) {
            if (isset($currentPageItems->message)) {
                die($currentPageItems->message);
            }
            $currentPageItems = [$currentPageItems];
        }

        $allPageItems = [];

        $allPageItems = array_merge($allPageItems,$currentPageItems);

        $nextPageItems = null;
        $nextPageUrl = null;

        $responseHeaders = $response->getHeaders();

        $nextPageItems = null;
        if(!empty($responseHeaders['Link'])) {
            if (stristr($responseHeaders['Link'][0], '; rel="next"') > -1) {
                $headerLinks = explode(',', $responseHeaders['Link'][0]);
                foreach ($headerLinks as $headerLink) {
                    if (stristr($headerLink, '; rel="next"') === -1) {
                        continue;
                    }

                    $pattern = '#<(.*?)>; rel="next"#m';
                    preg_match($pattern, $headerLink, $linkResponseHeaders);
                    if ($linkResponseHeaders) {
                        $nextPageUrl = $linkResponseHeaders[1];
                        $nextPageItems = $this->callGithubUrl($nextPageUrl);
                    }
                }
            }
        }

        if($nextPageItems) {
            $allPageItems = array_merge($allPageItems,$nextPageItems);
        } else {
            $allPageItems = $currentPageItems;
        }

        return $allPageItems;
    }

    function getIssues()
    {
        $allOrganizationIssues = [];

        //normal issues
        $url = 'https://api.github.com/issues?filter=all';
        $userIssues = $this->callGithubUrl($url);

        //get organizations
        $url = 'https://api.github.com/user/orgs?';
        $organizations = $this->callGithubUrl($url);

        //and each of their issues
        $organizationIssuesWithRepositoryInfo =[];
        $userIssuesArray =[];

        foreach($organizations as $org) {
            if(!$org) {
                continue;
            }

            $url = $org->repos_url;

            $thisOrganizationRepos = $this->callGithubUrl($url);
            foreach ($thisOrganizationRepos as $thisOrganizationRepo) {
                if($thisOrganizationRepo == 'Bad credentials'){
                    throw new Exception('Bad credentials');
                }

                $issuesUrl = $thisOrganizationRepo->issues_url;
                $issuesUrl = str_replace('{/number}', '', $issuesUrl);
                $thisOrganizationIssues = $this->callGithubUrl($issuesUrl);

                foreach ($thisOrganizationIssues as $originalOrganizationIssues) {
                    $originalOrganizationIssues -> repoInfo = $thisOrganizationRepo;
                    array_push($organizationIssuesWithRepositoryInfo, $originalOrganizationIssues);
                }

                if($thisOrganizationIssues !== null) {
                    $allOrganizationIssues = array_merge($allOrganizationIssues, $organizationIssuesWithRepositoryInfo);
                }
            }
        }
        return array_merge($userIssues,$allOrganizationIssues);
    }

}
