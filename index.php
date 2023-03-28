<?php
/**
 * This project implements the restic REST API described at:
 * https://restic.readthedocs.io/en/latest/100_references.html#rest-backend
 */
define('OBJECT_TYPES', ['data', 'keys', 'locks', 'snapshots', 'index']);
define('FILE_TYPES', ['config']);
define('CONFIG', ((@include 'config.php') ?: []) + [
    'AppendOnly' => false,      // --append-only
    'NoAuth' => false,          // --no-auth
    'PrivateRepos' => false,    // --private-repos
    'DataDir' => './restic',    // --path
    'Users' => [],
]);

[$method, $user, $repo, $uri, $query] = parse_request($_SERVER);

if (!CONFIG['NoAuth'] || CONFIG['PrivateRepos']) {
    if (empty($user)) {
        respond(401, 'You must be logged in.');
    } elseif (CONFIG['PrivateRepos'] && strpos("$repo/", "$user/") !== 0) {
        respond(403, 'You do not have access to this path.');
    }
}

if (preg_match('~^(' . implode('|', OBJECT_TYPES) . ')/([a-zA-Z0-9]{64})$~', $uri) || in_array($uri, FILE_TYPES)) {
    handle_file($method, joinpath(CONFIG['DataDir'], $repo, $uri));
} elseif (in_array($uri, OBJECT_TYPES)) {
    handle_list($method, joinpath(CONFIG['DataDir'], $repo, $uri));
} elseif ($uri === '') {
    handle_repo($method, joinpath(CONFIG['DataDir'], $repo), $query);
} else {
    respond(400, 'Bad Request');
}

function parse_request(array $server)
{
    $method = strtoupper($server['REQUEST_METHOD']);
    $user = $server['REMOTE_USER'] ?? '';
    $uri = preg_replace('~/+~', '/', $server['REQUEST_URI']);
    $find = implode('|', array_merge(OBJECT_TYPES, FILE_TYPES));
    if (preg_match("~^(?<p>.*?)(?<u>/($find)(/.*)?|/)(\?(?<q>.*))?$~", $uri, $m)) {
        return [$method, $user, $m['p'], trim($m['u'], '/'), $m['q'] ?? ''];
    }
    return [$method, $user, '', '', ''];
}

function joinpath(string ...$parts)
{
    return rtrim(implode('/', array_filter($parts, 'strlen')), '/');
}

function respond(int $http_code = 200, string $content = null, array $headers = [])
{
    header('Content-Type: application/vnd.x.restic.rest.v1');
    http_response_code($http_code);
    if ($content !== null)
        header('Content-Length: ' . strlen($content));
    foreach ($headers as $header)
        header($header);
    die($content);
}

// {PATH}
function handle_repo(string $method, string $path, string $query)
{
    if ($method === 'POST') {
        if ($query === 'create=true') { // create repo
            if (glob("$path/*")) {
                respond(403, 'Folder not empty');
            }
            foreach (OBJECT_TYPES as $type) {
                mkdir(joinpath($path, $type), 0755, true);
            }
            respond(200);
        }
    } elseif ($method === 'DELETE') { // delete repo
        respond(405, 'Method not allowed');
    } else {
        respond(405, 'Method not allowed');
    }
}

// {PATH}/{TYPE}
function handle_list(string $method, string $path)
{
    if ($method === 'GET') {
        $directory = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = [];
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            $files[] = $file->getBasename();
        }
        respond(200, json_encode($files));
    } else {
        respond(405, 'Method not allowed');
    }
}

// {PATH}/{TYPE}/{NAME} and {PATH}/{NAME}
function handle_file(string $method, string $path)
{
    if ($is_object = preg_match('/^[a-z0-9]{64}$/', basename($path))) {
        $path = preg_replace('~/data/((..).{62})$~', '/data/$2/$1', $path);
    }
    if ($method === 'GET' || $method === 'HEAD') {
        if (!is_file($path) || !is_readable($path)) {
            respond(404, 'Not found');
        }
        $content = $method === 'GET' ? file_get_contents($path) : NULL;
        respond(200, $content, ['Content-Length: ' . filesize($path)]);
    } elseif ($method === 'POST') {
        @mkdir(dirname($path), 0755, true);
        if (file_exists($path)) {
            respond(403, 'File exists');
        } elseif (!@copy('php://input', $path)) { // FIXME: Atomic copy would be nicer...
            respond(500, 'Copy failed');
        } elseif ($is_object && hash_file('sha256', $path) !== basename($path)) {
            @unlink($path);
            respond(500, 'Hash mismatch');
        }
        respond(200);
    } elseif ($method === 'DELETE') {
        if (CONFIG['AppendOnly']) {
            respond(403, 'Forbidden');
        } elseif (!file_exists($path)) {
            respond(404, 'Not found');
        } elseif (!@unlink($path)) {
            respond(500, 'Deletion failed');
        }
        respond(200);
    } else {
        respond(405, 'Method not allowed');
    }
}
