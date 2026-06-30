<?php

require 'YouTubeMusic.php';

header('Content-Type: application/json');

$yt = new YouTubeMusic();

if (!empty($_GET['query'])) {

    echo json_encode(
        $yt->search($_GET['query']),
        JSON_UNESCAPED_UNICODE
    );

} elseif (!empty($_GET['videoId'])) {

    echo json_encode(
        $yt->playlist($_GET['videoId']),
        JSON_UNESCAPED_UNICODE
    );

} elseif (!empty($_GET['musicId'])) {

    $yt->download($_GET['musicId']);

} else {

    echo json_encode([
        'success' => false,
        'error' => 'query ou videoId requis'
    ]);
}