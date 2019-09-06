<?php
namespace core;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Exception;
use Lcobucci\JWT\Signer\Hmac\Sha256;

class Router {

    public static function router($class, $action){

//   路由规则  d 是用来检查控制器是否存在, c【控制器/方法】
//      http://www.xman2.com/index.php?d=index&c=index/test
        echo  ( new $class )->$action();exit;
    }

    public static function cliRouter($class,$action,$argv=array()){
        echo  ( new $class )->$action($argv);exit;
    }

  public static function authRouter($class,$action,$argv=array()){
//    TODO::这里可以做auth的统一验证管理,可以调用https://github.com/lcobucci/jwt,JWT这个工具用来生成auth做验证管理


        echo  ( new $class )->$action($argv);exit;
  }




}