<?php
namespace database;
/**
 * Created by PhpStorm.
 * User: USER
 * Date: 2017/6/14
 * Time: 15:05
 */
class Mcache {

    public function index(){
        //此静态化功能仅供学习，半成品。

    }
    //手动创建文件缓存，生成静态页面
    public function create_cache(){
        ob_start();
        $cache_file="cache/static.html";
        $cache_time=10*30;
        if(file_exists($cache_file) && time()-$cache_time<filemtime($cache_file)){
            include($cache_file);
            exit();
        }
        $fp=fopen($cache_file,'w');
        fwrite($fp,ob_get_contents());
        fclose($fp);
        ob_end_flush();
    }

    //shell脚本自动后台执行创建静态页面，linux coretab 执行
    public function shell_create_cache(){
        ob_start();
        $cache_file="cache/static.html";
        $fp=fopen($cache_file,'w');
        fwrite($fp,ob_get_contents());
        fclose($fp);
        ob_end_flush();
    }


    /*
     * set,get 方法可以将数据库查询的$data 数据存入文件中，进行缓存
     * $ttl 过期时间
     * */

    public function set($key,$data,$ttl){
        $h=fopen($this->get_filename($key),'a+');
        if($h) throw new Exception('Could not write to cache');
        flock($h,LOCK_EX);//要取得独占锁定（写入的程序），在写完之前文件关闭不可在写入
        fseek($h,0);//到该文件头
        ftruncate($h,0);//清空文件内容
        $t=time()+$ttl;
        $data=serialize(array($t,$data));//根据生存周期$ttl写入到期的时间
        if(fwrite($h,$data)===false){
            throw new Exception('Could not write to cache');
        }
        fclose($h);
    }

    public function get($key){
        $filename=$this->get_filename($key);
        if(!file_exists($filename))return false;
        $h=fopen($filename,'r');
        if(!$h)return false;
        flock($h,LOCK_SH);//要取得共享锁定（读取的程序）
        $data=file_get_contents($filename);
        fclose($h);
        $data=@unserialize($data);
        if(!$data){
            unlink($filename); //删除文件
            return false;
        }
        if(time()>data[0]){
           unlink($filename);
            return false;
        }
        return $data[1];
    }

    //删除关闭文件
    public function clear($key){
        $filename=$this->get_filename($key);
        if(file_exists($filename)){
            return unlink($filename);
        }else{
            return false;
        }
    }

    public function X_cache_set(){

}

    private function get_filename($key){
        return "public/cache".md5($key);
    }

}