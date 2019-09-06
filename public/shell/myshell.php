<?php
header("Content-Type: text/html;charset=utf8");
date_default_timezone_set("PRC");
Class My_shell{
    public $conf=array();
    public function __construct($conf)
    {
    $this->conf=$conf;
    }

    public function redis_connect(){
        $r=new \Redis();
        $r->connect($this->conf['redis']['host'],$this->conf['redis']['port']);
        $r->auth($this->conf['redis']['pwd']);
        return $r;
    }

    public function set(){
        $r=$this->redis_connect();
        $r->set('name','haha');
        $data=$r->get('name');
        echo $data;
    }
}

//$conf=array(
//    "redis"=>array(
//        "host"=>"10.105.65.113",
//        "port"=>"6381",
//        "pwd"=>"game#2017"
//    ),
//);
$conf=array(
    "redis"=>array(
        "host"=>"127.0.0.1",
        "port"=>"6381",
        "pwd"=>"test111"
    ),
);
(new My_shell($conf))->set();



