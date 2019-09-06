<?php
namespace config;

Class Config{
    static public function config($type){
        $config_arr=array(
            "redis"=>array(   //可读可写redis (master)
                "host"=>C('redis_host'),
                "port"=>C('redis_port'),
                "auth"=>C('redis_auth'),
                "db"=>C("redis_db"),
            ),
            "logRedis" => array( //老赵日志redis
                "host" => C("redis_log_host"),
                "port" => C("redis_log_port"),
                "auth" => C("redis_log_auth"),
                "db" => C("redis_log_db"),
            ),
            "mysql"=>array(
                'host'=>C('db_host'),
                'port'=>C('db_port'),
                'username'=>C('db_user'),
                'pwd'=>C('db_pwd'),
                "db_name"=>C("db_name"),
            ),
            "redis_read" => array(  //只读redis 分布式到只读本地
                "host"=>C('redis_read_host'),
                "port"=>C('redis_read_port'),
                "auth"=>C('redis_read_auth'),
                "db"=>C("redis_read_db"),
            ),
            "redis_lock" => array(  //redis专用锁
                "host"=>C('redis_lock_host'),
                "port"=>C('redis_lock_port'),
                "auth"=>C('redis_lock_auth'),
                "db"=>C("redis_lock_db"),
            ),
            "logActionRedis" => array( //张鹏日志redis 统计专用
                "host"=>C('redis_flow_log_host'),
                "port"=>C('redis_flow_log_port'),
                "auth"=>C('redis_flow_log_auth'),
                "db"=>C("redis_flow_log_db"),
            ),
        );
        return $config_arr[$type];
    }
}
