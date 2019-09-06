<?php

use database\MyRedis;
use config\ProduceConfig;
use config\Tab;



/*
 * $fType 建筑类型
 * $type 操作类型  类型：1：自建；2：随地交易；
 * 获得工厂日志*/
function opt_log_for_FactoryAdd($serverId, $regionId, $pId, $Qid, $facId, $fid, $fType, $fGrade, $type, $price)
{
    $flog = 8;
    $time = getMillisecond();
    $iconType = 126;
    $str = $flog."\t".$serverId."\t".$regionId."\t".$pId."\t".$Qid."\t".$facId."\t".$fid."\t".$fType."\t".$fGrade."\t".$type."\t".$time."\t".$iconType."\t".$price;
    $r = MyRedis::getLogConnect();
    if(!$r->ping()){
        mLogs('Redis','opt_log_for_Factory',array('msg'=>'opt_log error'));exit;
    }
    $r->rPush(Tab::LOG_QUEUE,$str);
    $r->close();
}

/*
 * $type 类型：1：自拆；2：随地交易；3：幸福值低导致拆掉；
 * 折除工厂日志*/
function opt_log_for_FactoryDel($serverId, $regionId, $pId, $Qid, $facId, $price, $type)
{
    $flog = 9;
    $time = getMillisecond();
    $iconType = 126; //货币类型 金币
    $str = $flog."\t".$serverId."\t".$regionId."\t".$pId."\t".$Qid."\t".$facId."\t".$type."\t".$time."\t".$iconType."\t".$price;
    $r = MyRedis::getLogConnect();
    if(!$r->ping()){
        mLogs('Redis','opt_log_for_FactoryDel',array('msg' => 'opt_log error','data' => $str));exit;
    }
    $r->rPush(Tab::LOG_QUEUE,$str);
    $r->close();
}

/*
 * 升级工厂日志*/
function opt_log_for_FactoryUp($serverId, $regionId, $pId, $Qid, $facId, $fGrade, $price)
{
    $flog = 10;
    $time = getMillisecond();
    $iconType = 126;
    $str = $flog."\t".$serverId."\t".$regionId."\t".$pId."\t".$Qid."\t".$facId."\t".$fGrade."\t".$time."\t".$iconType."\t".$price;
    $r = MyRedis::getLogConnect();
    if(!$r->ping()){
        mLogs('Redis','opt_log_for_FactoryUp',array('msg' => 'opt_log error','data' => $str));exit;
    }
    $r->rPush(Tab::LOG_QUEUE,$str);
    $r->close();
}



/**
 * 工厂生产,合成，打工日志
 *  经验,称号,矿及使用日志
 */
function opt_log_for_produce($queueStr = null)
{
    if(empty($queueStr)){
        return returnArr(400,"","log data is null");
    }
    try{
        $r = MyRedis::getLogConnect();
        if(!$r->ping()){
            $errInfo = array(
                "errMsg" => "setLogQueueErr",
                "msg" => "connect to logRedis err: timeout",
                "queueStr" => $queueStr,
            );
            mLogs("error","opt_log_for_produce",$errInfo,ProduceConfig::PRODUCE_LOG_FILE);
            return returnArr(502,"","connect to logRedis err: timeout");
        }

        $r->rPush(Tab::LOG_QUEUE,$queueStr);
        $r->close();

        return returnArr(200,"","success");

    }catch (\Exception $e){
        $errInfo = array(
            "errMsg" => "setLogQueueErr",
            "msg" => $e->getMessage(),
            "queueStr" => $queueStr,
        );
        mLogs("error","opt_log_for_produce",$errInfo,ProduceConfig::PRODUCE_LOG_FILE);
        return returnArr(403,"",$e->getMessage());
    }
}

/*
* 向行为记录库添加记录日志
*/
function optFlowLog($string)
{
    try{
        $con = MyRedis::getActionLogConnect();
        if (!$con) {
            return false;
            mLogs("error", "opt_flow_log", "timeout");
        }
        $a = $con->rPush(Tab::OPT_FLOW_LOG,$string);
        $con->close();
        return true;
    }catch (\Exception $e){
        return false;
    }
}
