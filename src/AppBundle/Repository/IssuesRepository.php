<?php

namespace AppBundle\Repository;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Symfony\Component\Config\Definition\Exception\Exception;

class IssuesRepository
{
    /**
     * @var HandlerStack
     */
    private $stack;

    /**
     * @var CacheMiddleware
     */
    private $cacheClient;

    /**
     * @var Client
     */
    private $guzzleClient;

    /**
     * GithubRepository constructor.
     * @param HandlerStack $stack
     * @param CacheMiddleware $cacheClient
     * @param Client $guzzleClient
     */
    public function __construct(HandlerStack $stack, CacheMiddleware $cacheClient, Client $guzzleClient)
    {
        $this->stack = $stack;
        $this->cacheClient = $cacheClient;
        $this->guzzleClient = $guzzleClient;
    }

    /**
     * @param string $url
     * @param string $apiToken
     * @param string $method
     * @param array $params
     * @return array
     * @throws \Exception
     */
    public function callGithubUrl(string $url, string $apiToken, string $method = 'GET', bool $callRecursive = true): array
    {
        $headers = [
            'Authorization' => 'token ' . $apiToken,
            'User-Agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13',
            'Accept' => 'application/json',
        ];

        // Create default HandlerStack
        if (!empty(getenv('REDIS_URL'))) {
            $this->stack->push(
                $this->cacheClient,
                'cache'
            );
        }

        if (!$apiToken) {
            throw new \Exception('API token missing', 401);
        }

        try {
            $options = [
                /* re-adding handler here is necessary for \GuzzleHttp\Client::transfer to pick it up */
                'handler' => $this->stack,
                'headers' => $headers,
            ];
            $response = $this->guzzleClient->request($method, $url, $options);
        } catch (\Exception $e) {
            var_dump($url);
            var_dump($method);
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
        $allPageItems = array_merge($allPageItems, $currentPageItems);

        $nextPageItems = null;
        $nextPageUrl = null;

        $responseHeaders = $response->getHeaders();

        $nextPageItems = null;
        if ($callRecursive && !empty($responseHeaders['Link'])) {
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
                        $nextPageItems = $this->callGithubUrl($nextPageUrl, $apiToken);
                    }
                }
            }
        }

        if ($nextPageItems) {
            $allPageItems = array_merge($allPageItems, $nextPageItems);
        } else {
            $allPageItems = $currentPageItems;
        }

        return $allPageItems;
    }

    /**
     * @param string $apiToken
     * @return array
     * @throws \Exception
     */
    public function getIssues(string $apiToken): array
    {
        $allOrganizationIssues = [];

        //normal issues
        $url = 'https://api.github.com/issues?filter=all';
        $userIssues = $this->callGithubUrl($url, $apiToken);

        //get organizations
        $url = 'https://api.github.com/user/orgs?';
        $organizations = $this->callGithubUrl($url, $apiToken);

        //and each of their issues
        $organizationIssuesWithRepositoryInfo = [];

        foreach ($organizations as $org) {
            if (!$org) {
                continue;
            }

            $url = $org->repos_url;

            $thisOrganizationRepos = $this->callGithubUrl($url, $apiToken);
            foreach ($thisOrganizationRepos as $thisOrganizationRepo) {
                if ($thisOrganizationRepo == 'Bad credentials') {
                    throw new Exception('Bad credentials');
                }

                $issuesUrl = $thisOrganizationRepo->issues_url;
                $issuesUrl = str_replace('{/number}', '', $issuesUrl);
                $thisOrganizationIssues = $this->callGithubUrl($issuesUrl, $apiToken);

                foreach ($thisOrganizationIssues as $originalOrganizationIssues) {
                    $originalOrganizationIssues->repoInfo = $thisOrganizationRepo;
                    array_push($organizationIssuesWithRepositoryInfo, $originalOrganizationIssues);
                }

                if ($thisOrganizationIssues !== null) {
                    $allOrganizationIssues = array_merge($allOrganizationIssues, $organizationIssuesWithRepositoryInfo);
                }
            }
        }

        return array_merge($userIssues, $allOrganizationIssues);
    }
}
