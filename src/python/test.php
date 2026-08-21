<?php

$ipv6 = "2a02:8424:894c:a901:211:32ff:fe99:630b";
$url = 'http://[' . $ipv6 . ']:80/';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
]);

$content = curl_exec($ch);

if ($content === false) {
    die('Erreur : ' . curl_error($ch));
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP $httpCode\n";
echo $content;