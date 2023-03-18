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
]);

[$method, $user, $path, $uri, $query] = parse_request($_SERVER);

if (empty($user) && (CONFIG['PrivateRepos'] || !CONFIG['NoAuth'])) {
    respond(401, 'You must be logged in.');
} elseif (CONFIG['PrivateRepos'] && strpos("$path/", "$user/") !== 0) {
    respond(401, 'You must be logged in.');
}

if (preg_match('~^(' . implode('|', OBJECT_TYPES) . ')/([a-zA-Z0-9]{64})$~', $uri) || in_array($uri, FILE_TYPES)) {
    handle_file($method, joinpath(CONFIG['DataDir'], $path, $uri));
} elseif (in_array($uri, OBJECT_TYPES)) {
    handle_list($method, joinpath(CONFIG['DataDir'], $path, $uri));
} elseif ($uri === '') {
    handle_repo($method, joinpath(CONFIG['DataDir'], $path), $query);
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
function handle_repo(string $method, string $folderPath, string $query)
{
    if ($method === 'POST') {
        if ($query === 'create=true') { // create repo
            if (glob("$folderPath/*")) {
                respond(403, 'Folder not empty');
            }
            foreach (OBJECT_TYPES as $type) {
                mkdir(joinpath($folderPath, $type), 0755, true);
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
function handle_list(string $method, string $filePath)
{
    if ($method === 'GET') {
        $directory = new \RecursiveDirectoryIterator($filePath, \RecursiveDirectoryIterator::SKIP_DOTS);
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
function handle_file(string $method, string $filePath)
{
    if ($is_object = preg_match('/^[a-z0-9]{64}$/', basename($filePath))) {
        $filePath = preg_replace('~/data/((..).{62})$~', '/data/$2/$1', $filePath);
    }
    if ($method === 'GET' || $method === 'HEAD') {
        if (!is_file($filePath) || !is_readable($filePath)) {
            respond(404, 'Not found');
        }
        $content = $method === 'GET' ? file_get_contents($filePath) : NULL;
        respond(200, $content, ['Content-Length: ' . filesize($filePath)]);
    } elseif ($method === 'POST') {
        @mkdir(dirname($filePath), 0755, true);
        if (file_exists($filePath)) {
            respond(403, 'File exists');
        } elseif (!@copy('php://input', $filePath)) { // FIXME: Atomic copy would be nicer...
            respond(500, 'Copy failed');
        } elseif ($is_object && hash_file('sha256', $filePath) !== basename($filePath)) {
            @unlink($filePath);
            respond(500, 'Hash mismatch');
        }
        respond(200);
    } elseif ($method === 'DELETE') {
        if (CONFIG['AppendOnly']) {
            respond(403, 'Forbidden');
        } elseif (!file_exists($filePath)) {
            respond(404, 'Not found');
        } elseif (!@unlink($filePath)) {
            respond(500, 'Deletion failed');
        }
        respond(200);
    } else {
        respond(405, 'Method not allowed');
    }
}
