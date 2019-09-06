<?php
namespace web\model;

use database\MyRedis;
use config\Tab as Tab;

class ApiModel extends BaseModel{


    public static function editUserCard($qid, $cid, $num)
    {
        try{
            $con = MyRedis::getConnect();
            $key = Tab::userCard.$qid;
            $add = $con -> hincrby($key, $cid, $num);
            $con -> close();
            return $add;
        } catch (\Exception $e) {
            return false;
        }
    }


    /*
     *  @ opt redis操作命令
     *  @ start 开始时间
     *  @ end 结束时间
     *  张鹏日志查询操作*/
    public static function aConOptLog($opt, $start, $end)
    {
        try{
            $db = Tab::OPT_FLOW_LOG;
            $r = MyRedis::getActionLogConnect();

            if($opt == 'LRANGE' || $opt == 'LTRIM'){
                $data = $r->$opt($db, $start, $end);
                if (!$data) return '';
                return $data;
            }
            if(($opt == 'LLEN')){
                $len = $r->LLEN($db);
                if (!$len) $len = 0;
                return $len;
            }
        }  catch (\Exception $e){
                return returnArr(400, 'false', 'error');
        }
    }

    /*
     *  @ log 日志内容
     *  redis操作添加日志*/
    public static function setOptFlowLog($log)
    {
        try{
            optFlowLog($log);
            return true;
        }  catch (\Exception $e){
            return false;
        }
    }

}