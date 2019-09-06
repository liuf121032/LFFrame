<?php
namespace web\controller;

use \core\View;

class BaseController{
    protected $Class='';
    protected $View='';

    public function __construct()
    {
        $class=get_class($this);
        $class_name=substr($class,strripos($class,"\\")+1);
        $class_name=substr($class_name,0,-strlen("Controller"));
        $this->Class=$class_name;
        $this->View = new View();
        $this->View->getFile($class_name);
        $this->View->with($this->get_version());
    }

    //调用的方法不存在
    public function __call($name, $arguments)
    {
        try{
            throw new \Exception('function error',110);
        }catch(\Exception $e){
            echo jsonOut(returnArr($e->getCode(),'',$e->getMessage()));exit;
        }
    }

    //公共环境变量
    public function get_version()
    {
        $param=array('version'=>"版本：1.0");
        return $param;
    }
}