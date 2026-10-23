<?php

/*
 * Live Server router for PHP's own server (php -S ... router.php): serves the project like
 * php -S does, and adds a small script to every HTML page that reloads it when a file is saved
 * in VS Code (the extension writes the time of the last save to LIVE_VERSION_FILE). Every
 * browser that has the page open refreshes, not only VS Code's.
 */

$root = rtrim((string) realpath($_SERVER['DOCUMENT_ROOT']), '/');
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($path === '/__live') {
    header('Content-Type: text/plain');
    header('Cache-Control: no-store');
    echo @file_get_contents((string) getenv('LIVE_VERSION_FILE')) ?: '0';

    return true;
}

$target = realpath($root.$path);
$inside = $target !== false && ($target === $root || str_starts_with($target, $root.'/'));

if ($inside && is_dir($target)) {
    foreach (['index.php', 'index.html', 'index.htm'] as $index) {
        if (is_file($target.'/'.$index)) {
            $target .= '/'.$index;

            break;
        }
    }
}

$isFile = $inside && is_file($target);
$extension = $isFile ? strtolower(pathinfo($target, PATHINFO_EXTENSION)) : '';

// Images, styles, scripts...: as php -S serves them.
if ($isFile && ! in_array($extension, ['php', 'html', 'htm'], true)) {
    return false;
}

// Anything else goes to the page itself, or to the front controller (index.php), like a framework expects.
$script = $isFile ? $target : (is_file($root.'/index.php') ? $root.'/index.php' : null);

if ($script === null) {
    return false;
}

ob_start();

if (str_ends_with($script, '.php')) {
    $_SERVER['SCRIPT_FILENAME'] = $script;
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = substr($script, strlen($root));
    chdir(dirname($script));
    require $script;
} else {
    header('Content-Type: text/html; charset=utf-8');
    readfile($script);
}

$output = (string) ob_get_clean();
$html = true;

foreach (headers_list() as $header) {
    if (stripos($header, 'content-type:') === 0 && stripos($header, 'text/html') === false) {
        $html = false;
    }
}

if ($html) {
    $reload = '<script>(function(){var v=null;setInterval(function(){fetch("/__live",{cache:"no-store"}).then(function(r){return r.text()}).then(function(t){if(v!==null&&t!==v){location.reload()}v=t}).catch(function(){})},1000)})();</script>';
    $output = stripos($output, '</body>') !== false ? substr_replace($output, $reload.'</body>', strripos($output, '</body>'), 7) : $output.$reload;
}

echo $output;

return true;
