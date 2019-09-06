<?php
namespace database;

use config\Config;


Class MySql{
    private static $redisInstance;

    private function __construct()
    {

    }
/*
 * $dbName 库名
 * */
    static public function getConnect($dbName = null)
    {
        if(!self::$redisInstance instanceof self){
            self::$redisInstance=new self;
        }
        $temp=self::$redisInstance;
        if ($dbName == null) {
            $dbName = Config::config('mysql')["db_name"];
        }
        return $temp->connMysql($dbName );
    }

    static private function connMysql($dbName)
    {
        try {
            ini_set('default_socket_timeout',60);
            $host=Config::config('mysql')['host'];
            $username=Config::config('mysql')['username'];
            $password=Config::config('mysql')['pwd'];
            $port=Config::config('mysql')['port'];
            $dsn='mysql:host='.$host.';port='.$port;
            $pdo = new \PDO($dsn,$username,$password);
            $pdo->setAttribute(\PDO::ATTR_PERSISTENT, true); // 设置数据库连接为持久连接
            $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 50); // 设置数据库连接为持久连接
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION); // 设置抛出错误
            $pdo->setAttribute(\PDO::ATTR_CASE,\PDO::CASE_NATURAL);//设置数据的方式,原始字段大小写输出
            $pdo->setAttribute(\PDO::ATTR_ORACLE_NULLS, true); // 设置当字符串为空转换为 SQL 的 NULL
            $pdo->query('SET NAMES utf8'); // 设置数据库编码
            $pdo->query("use $dbName");
            return $pdo;
        }catch (\PDOException $e){
            $msg=$e->getMessage();
            $arr=array('status'=>$e->getCode(),'msg'=>$msg);
            mLogs('Mysql',"getConnect",$arr);
        }

    }

    private function __clone()
    {

    }
    public function __destruct()
    {

    }

}