<?php
require __DIR__ . '/../src/AsyncHttpClient.php';

$base = event_base_new();

$uri = "http://www.google.com/";
$config = array(
    'eventbase' => $base
);

for($i = 0; $i < 10; $i++) {
    $client = new AsyncHttpClient($uri, $config);
    $client->request();
}

event_base_loop($base);
echo "done\n";
