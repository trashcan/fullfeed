<?php
if (isset($_GET['url'])) {
    $url = $_GET['url'];
    header("Location: /f/?url=$url");
    exit;
} else {
    $html = file_get_contents('index.html');
    print $html;
    exit;
}
