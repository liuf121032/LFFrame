<?php
error_reporting(0);//忽略notice错误--
// header("Access-Control-Allow-Origin:*");
date_default_timezone_set("PRC");
ini_set('default_socket_timeout', -1);
mb_internal_encoding("UTF-8");
include_once "vendor/autoload.php";
define('APP_NAME',__DIR__.DIRECTORY_SEPARATOR);
define('SERVER_TYPE', 'local');
\core\Bootstrap::run();
function cache_shutdown_error() {
    $_error = error_get_last();
    if ($_error && in_array($_error["type"], array(1,2, 4, 16, 64, 256, 4096, E_ALL))) {
        echo '<font color=red>error:</font></br>';
        echo '致命错误:' . $_error["message"] . '</br>';
        echo '文件:' . $_error["file"] . '</br>';
        echo '在第' . $_error["line"] . '行</br>';
    }
}
register_shutdown_function("cache_shutdown_error");




