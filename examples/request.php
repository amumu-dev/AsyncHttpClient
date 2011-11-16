<?php
// autoload
function __autoload($clazz) {
    $file = str_replace('_', '/', $clazz);
    require "/usr/share/pear/$file.php";
}

require __DIR__ . '/../src/AsyncHttpClient.php';

function callbackFunc($result) {
    echo "Result len:";
    echo strlen($result['response']);
    //$response = Zend_Http_Response::fromString($result['response']);
    //echo $response->getBody();
    echo "\n";
}

$base = event_base_new();
$uri = "http://www.baidu.com/";
$config = array(
    'callback' => 'callbackFunc',
    'eventbase' => $base
);

for($i = 0; $i < 3; $i++) {
    $client = new AsyncHttpClient($uri, $config);
    $client->request();
}

event_base_loop($base);
echo "done\n";
