<?php
namespace database;

use config\Config;

Class MyRedis{
    private static $redisInstance_write;
    private static $redisInstance_read;
    private static $redisInstance_lock;
    private static $logRedisInstance;
    private static $logActionRedisInstance;


    private function __construct()
    {

    }

    /*
     * 专门写redis*/
    static public function getConnect()
    {
        if(!self::$redisInstance_write instanceof self){
            self::$redisInstance_write = new self;
        }
        $temp=self::$redisInstance_write;
        return $temp->connRedis();
    }


    /*
     * 专门读redis*/
    static public function getConnectRead()
    {
        if(!self::$redisInstance_read instanceof self){
            self::$redisInstance_read=new self;
        }
        $temp=self::$redisInstance_read;
        return $temp->connRedisRead();
    }
    /*
         * 专门读锁*/
    static public function getConnectLock()
    {
        if(!self::$redisInstance_lock instanceof self){
            self::$redisInstance_lock=new self;
        }
        $temp=self::$redisInstance_lock;
        return $temp->connRedisLock();
    }

    /*
     * 获取日志服务器连接 */
    static public function getLogConnect()
    {
        if(!self::$logRedisInstance instanceof self){
            self::$logRedisInstance=new self;
        }
        $temp=self::$logRedisInstance;
        return $temp->connLogRedis();
    }

    /*
     * 获取行为日志服务器连接 */
    static public function getActionLogConnect()
    {
        if(!self::$logActionRedisInstance instanceof self){
            self::$logActionRedisInstance=new self;
        }
        $temp=self::$logActionRedisInstance;
        return $temp->connActionLogRedis();
    }


    static private function connRedis()
    {
        $configArr=Config::config('redis');
        try {
            $redis_write = new \Redis();
            $redis_write->pconnect($configArr['host'],$configArr['port'],3);
            $redis_write->auth($configArr['auth']);
            $redis_write->select($configArr["db"]);
        }catch (\Exception $e){
            $msg=$e->getMessage();
            $arr=array('msg'=>$msg);
            mLogs('Redis',"connRedis",$arr);
        }
        return $redis_write;
    }


    static private function connRedisRead()
    {
        $configArr=Config::config('redis_read');
        try {
            $redis_Read = new \Redis();
            $redis_Read->pconnect($configArr['host'],$configArr['port'],3);
            $redis_Read->auth($configArr['auth']);
            $redis_Read->select($configArr["db"]);
        }catch (\Exception $e){
            $msg=$e->getMessage();
            $arr=array('msg'=>$msg);
            mLogs('Redis',"connRedisRead",$arr);
        }
        return $redis_Read;
    }


    static private function connRedisLock()
    {
        $configArr=Config::config('redis_lock');
        try {
            $redis_Lock = new \Redis();
            $redis_Lock->pconnect($configArr['host'],$configArr['port'],3);
            $redis_Lock->auth($configArr['auth']);
            $redis_Lock->select($configArr["db"]);
        }catch (\Exception $e){
            $msg=$e->getMessage();
            $arr=array('msg'=>$msg);
            mLogs('Redis',"connRedislock",$arr);
        }
        return $redis_Lock;
    }


    /*
     * 日志服务器
     */
    static private function connLogRedis()
    {
        $configArr=Config::config('logRedis');
        try {
            $redis_log = new \Redis();
            $redis_log->pconnect($configArr['host'],$configArr['port'],3);
            $redis_log->auth($configArr['auth']);
            $redis_log->select($configArr["db"]);
        }catch (\Exception $e){
            $msg=$e->getMessage();
            $arr=array('msg'=>$msg);
            mLogs('Redis',"getLogRedisConn",$arr);
        }
        return $redis_log;
    }

    /*
     * 行为记录日志服务器
     */
    static private function connActionLogRedis()
    {
        $configArr=Config::config('logActionRedis');
        try {
            $redis_log = new \Redis();
            $redis_log->pconnect($configArr['host'],$configArr['port'],3);
            $redis_log->auth($configArr['auth']);
            $redis_log->select($configArr["db"]);
        }catch (\Exception $e){
            $msg=$e->getMessage();
            $arr=array('msg'=>$msg);
            mLogs('Redis',"getLogActionRedisConn",$arr);
        }
        return $redis_log;
    }

    private function __clone()
    {

    }
    public function __destruct()
    {

    }

}