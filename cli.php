<?php
/**
 * Created by PhpStorm.
 * User: root
 * Date: 18-1-5
 * Time: 下午6:08
 */

//error_reporting(0);//忽略notice错误
date_default_timezone_set("PRC");
ini_set('default_socket_timeout', -1);
mb_internal_encoding("UTF-8");
include_once "vendor/autoload.php";
define('APP_NAME',__DIR__.DIRECTORY_SEPARATOR);
define('SERVER_TYPE', 'releas');
\core\Bootstrap::runCli($argv);