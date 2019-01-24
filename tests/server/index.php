<?php

header('Content-Type: text/plain; charset=utf-8');

if (preg_match('/^\/validate\/yes\\?/', $_SERVER["REQUEST_URI"]) && isset($_GET['casticket'])) {
    
    echo "yes\r\ntest_user ";    // IU CAS adds extra whitespace, which will need to be trimmed
    exit;
} elseif (preg_match('/^\/validate\/no\\?/', $_SERVER["REQUEST_URI"])) {
    echo "no\r\n";
    exit;
}

http_response_code(500);
echo "Server Error\n";
echo $_SERVER["REQUEST_URI"] ."\n";
var_dump($_GET);
exit;