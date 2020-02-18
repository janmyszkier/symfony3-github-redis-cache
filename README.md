symfony3-github-redis-cache
===========================

A Symfony project created on February 18, 2020, 6:47 pm.

Modify \AppBundle\Controller\DefaultController::callGithubUrl $apiToken with your github token

Run  `bin/console server:run`

Open http://127.0.0.1:8000/

Wait till the page loads

check `redis-cli keys '\[github:*'` for added keys

