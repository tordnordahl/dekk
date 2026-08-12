<?php
return [
    'repository_path' => env('DEPLOY_REPOSITORY_PATH', dirname(base_path())),
    'remote' => env('DEPLOY_GIT_REMOTE', 'origin'),
    'branch' => env('DEPLOY_GIT_BRANCH'),
    'git_binary' => env('DEPLOY_GIT_BINARY', '/usr/bin/git'),
];
