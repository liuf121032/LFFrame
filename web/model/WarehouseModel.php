<?php
/**
 * @description 仓库相关服务
 * @author 张鹏 zhangpeng@tvmining.com
 * @version 1.0
 * @date 2018-01-18
 * @copyright Copyright (c) 2008 Tvmining (http://www.tvmining.com)
 */
namespace web\model;

use config\Tab;
use database\MyRedis;
use config\FactoryConfig;


class WarehouseModel extends BaseModel
{
    public static function index()
    {
        return true;
    }

    /**
     * 合成材料
     * @param  [array] array(
                         [string] qid,
                         [int] cid 材料id,
                         [int] amount 材料数量 1级材料需要
                         [string] service 服务器参数
                         )
     * @return [bool] 
     */
    public static function storage($array)
    {
        $qid = isset($array['qid']) ? $array['qid'] : '';
        $cid = isset($array['cid']) ? $array['cid'] : '';
        if ($qid == '' || $cid == '') return false;
        $amount = isset($array['amount']) ? $array['amount'] : 1;
        $service = isset($array['service']) ? $array['service'] : '';

        $cardList = FactoryConfig::propArr();
        if (!$cardList || empty($cardList)) return false;
        $data = $cardList[$cid];
        $stime = getMillisecond();
        switch ($data['type']) { // 2,3:合成   1:生产
            case 1:
                $addCard = self::editUserCard($qid, $cid, $amount);
                if (!$addCard) {
                    mLogs('addCard','func storage',$qid.'-'.$cid.'-'.$amount);
                    return false;
                }
                $addCardService = self::addUserCardService($qid, $cid, $amount, $service); // 增加服务器素材数量
                if (!$addCardService) mLogs('addCardService','func storage',$qid.'-'.$cid.'-'.$amount.'-'.$service);
                break;

            default:
                $material = $data['compound']; // material是合成的素材内容
                $tmpArr = array();
                foreach ($material as $cardId => $val) { // cardId是合成所需材料的id
                    $num = $val['num'] * $amount; // 消耗数量
                    $minCard = self::editUserCard($qid, $cardId, '-'.$num); // 减掉个人1级材料
                    array_push($tmpArr, array($cardId => $num)); // 记录已扣材料的id和数量
                    if ($minCard < 0) { // 如果扣完材料出现负值
                        mLogs('minCard','func storage',$qid.'-'.$cardId.'-'.$num.'出现负值'.$minCard);
                        foreach ($tmpArr as $tmpk => $tmpval) {
                            $add = self::editUserCard($qid, array_keys($tmpval)[0], array_values($tmpval)[0]); // 将前面扣的加回去

                            $cidFlow = ltrim(array_keys($tmpval)[0], 'c');
                            $optLog = "7\t".$qid."\t".$cidFlow."\t".array_values($tmpval)[0]."\t".$add."\t".$stime; // 推日志
                            optFlowLog($optLog);

                            if (!$add) mLogs('minCard','func storage',$qid.'-'.array_keys($tmpval)[0].'-'.array_values($tmpval)[0].'回滚添加失败');
                        }
                        return false;
                    }
                    if (!$minCard) {
                        $check = self::checkCardNum($qid, $cardId); // 检查库里材料数量是否为0
                        if ($check) {
                            mLogs('minCard','func storage',$qid.'-'.$cardId.'-'.$num);
                            foreach ($tmpArr as $tmpk => $tmpval) {
                                $add = self::editUserCard($qid, array_keys($tmpval)[0], array_values($tmpval)[0]); // 将前面扣的加回去

                                $cidFlow = ltrim(array_keys($tmpval)[0], 'c');
                                $optLog = "7\t".$qid."\t".$cidFlow."\t".array_values($tmpval)[0]."\t".$add."\t".$stime; // 推日志
                                optFlowLog($optLog);

                                if (!$add) mLogs('minCard','func storage',$qid.'-'.array_keys($tmpval)[0].'-'.array_values($tmpval)[0].'回滚添加失败');
                            }
                            return false;
                        }
                    }
                    $cidFlow = ltrim($cardId, 'c');
                    $optLog = "4\t".$qid."\t".$cidFlow."\t".$num."\t".$minCard."\t".$stime; // 推日志
                    optFlowLog($optLog);
                }
                $addCard = self::editUserCard($qid, $cid, $amount); // 增加个人合成品数量
                if (!$addCard) {
                    mLogs('addCard','func storage',$qid.'-'.$cid.'-'.$amount);
                    return false;
                }
                $addCardService = self::addUserCardService($qid, $cid, $amount, $service); // 增加服务器素材数量
                if (!$addCardService) mLogs('addCardService','func storage',$qid.'-'.$cid.'-'.$amount.'-'.$service);
                break;
        }
        return true;
    }

    public static function wareHouseList($qid, $type)
    {
        $con = MyRedis::getConnectRead();
        $key = Tab::userCard.$qid;
        $data = $con -> hgetall($key);
        $con -> close();
        $cardList = FactoryConfig::propArr();
        $res = array();
        switch ($type) {
            case '1': // 基础材料
                $i = 0;
                foreach ($cardList as $k => $value) {
                    if ($value['type'] == 1) {
                        $res[$i] = $value;
                        $res[$i]['num'] = isset($data[$k]) ? $data[$k] : 0;
                        if ($res[$i]['num'] < 0) $res[$i]['num'] = 0;
                        $res[$i]['cid'] = $k;
                    }
                    $i++;
                }
                break;
            default: // 矿机
                $i = 0;
                foreach ($cardList as $k => $value) {
                    if ($value['type'] == 2) {
                        $res[$i] = $value;
                        $res[$i]['num'] = isset($data[$k]) ? $data[$k] : 0;
                        if ($res[$i]['num'] < 0) $res[$i]['num'] = 0;
                        $res[$i]['cid'] = $k;
                        unset($res[$i]['compound']);
                    }
                    $i++;
                }
                break;
        }
        unset($key, $value);
        foreach ($res as $key => $value) {
            $ret[] = $value;
        }
        return $ret;
    }

    private static function editUserCard($qid, $cid, $num)
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
    *   查询库里材料是否为0
    */
    private static function checkCardNum($qid, $cid)
    {
        try{
            $con = MyRedis::getConnectRead();
            $key = Tab::userCard.$qid;
            $num = $con -> hget($key, $cid);
            $con -> close();
            if ($num == 0) return false;
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 增加服务器材料数量
     * @param  [string] qid
     * @param  [string] cid
     * @param  [int] num
     * @param  [int] service服务器标识
     * @return [bool] 
     */
    public static function  addUserCardService($qid, $cid, $num, $service)
    {
        try{
            $con = MyRedis::getConnect();
            $keyService = Tab::userCard.$service;
            $addService = $con -> hincrby($keyService, $cid, $num);
            $con -> close();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 挖矿机列表
     * @param  [string] qid
     * @return [array] 
     */
    public static function  digList($qid)
    {
        $digArr = array('c9301', 'c9302', 'c9303');
        $cardList = FactoryConfig::propArr();
        $con = MyRedis::getConnectRead();

        $res = array();

        foreach ($digArr as $key => $value) {
            $num = $con -> hget(Tab::userCard.$qid, $value);
            $res[$value]['num'] = isset($num) && $num != '' && $num >= 0 ? $num : 0;
            $res[$value]['name'] = isset($cardList[$value]['name']) ? $cardList[$value]['name'] : '';
            $res[$value]['multiple'] = isset($cardList[$value]['multiple']) ? $cardList[$value]['multiple'] : 0;
            $res[$value]['time'] = isset($cardList[$value]['time']) ? $cardList[$value]['time'] : 0;
        }
        $con -> close();
        return $res;
    }

    /**
     * 使用挖矿机
     * @param  [string] qid
     * @param  [int] cid
     * @param  [int] 挖矿机数量
     * @return [bool] 
     */
    public static function  digUse($qid, $cid, $num)
    {
        $checkNum = self::checkDig($qid);
        if($checkNum >=5){
            return false;
        }
        if($num>5){
            return false;
        }
        $tmpNum = 5 - (int)$checkNum;
        if($num>$tmpNum){
            return false;
        }

        $cardList = FactoryConfig::propArr();
        $cardData = $cardList[$cid];
        if (!$cardData || empty($cardData)){
            return false;
        }
        $multiple = $cardData['multiple'];
        $stime = getMillisecond();
        $hData = array(
                    'amount' => $num,
                    'stime' => $stime,
                    'time_slot' => $cardData['time'].'000',
                    'status' => 1
            );
        $hData = json_encode($hData);

        $con = MyRedis::getConnect();
        $hkey = $qid.'_k'.$multiple;
        $hlen = $con -> hlen($hkey);
        $key = 'n'.($hlen + 1);
        $addHash = $con -> hset($hkey, $key, $hData); // 存哈希队列

        $listData = array(
                    'opt' => 1,
                    'qid' => $qid,
                    'tid' => 'k'.$multiple,
                    'batch' => $hlen + 1,
                    'amount' => $num,
                    'stime' => $stime,
                    'time_slot' => $cardData['time'].'000',
                    'status' => 1
            );
        $listData = json_encode($listData);
        $addList = $con -> lpush('qs_queue_kj', $listData); // 存list队列

        $minCard = self::editUserCard($qid, $cid, '-'.$num); // 减掉个人仓库矿机(minCard是剩余量)

        $cid = ltrim($cid, 'c');
        $optLogA = "26\t".$qid."\t".$cid."\t".$num."\t".$minCard."\t".$stime."\t1"; // 推日志
        $optLogB = "3\t".$qid."\t".$cid."\t".$num."\t".$minCard."\t".$stime."\t1"; // 推日志
        opt_log_for_produce($optLogA);
        optFlowLog($optLogB);
        $con -> close();
        return true;
    }

    /**
     * 根据tvmid获取使用中挖矿机信息
     * @param  [string] qid
     * @return [array] 
     */
    public static function digData($qid)
    {
        $con = MyRedis::getConnectRead();
        $hkey = $qid.'_k*';
        $hData = $con -> keys($hkey);

        $multiple['all']['multiple'] = 0;
        $multiple['all']['time'] = 0;
        $multiple['all']['alltime'] = 0;
        $multiple['all']['page'] = 0;
        $checkA = $checkB = $checkTimes = array();
        // $page = 0;
        foreach ($hData as $key => $value) {
            $times = ltrim(substr($value, stripos($value, '_')), '_k'); // 倍数
            
            array_push($checkTimes, $times);
            if ($times != '' && is_numeric($times)) {
                $content = $con -> hgetall($value);
                if (!empty($content)) {
                    $amountAll = 0;
                    $checkTime = array();
                    $name = '';
                    foreach ($content as $k => $val) { // 遍历所有倍数卡
                        $val = json_decode($val, true);
                        if ($val['status'] == 1) {
                            $amount = $val['amount'];
                            $stime = $val['stime'] / 1000;
                            $timeSlot = $val['time_slot'] / 1000; // 有效时间

                            $amountAll += $amount; // 某一倍数卡的总数(使用中)

                            if ($times == 5 || $times == 12) {
                                $reSecond = re_second(); //当天剩余时间
                            } else {
                                $reSecond = ($stime + $timeSlot) - time();
                            }

                            array_push($checkTime, $reSecond);
                            array_push($checkA, $reSecond);
                            array_push($checkB, $timeSlot);
                            // $page += $amountAll;
                            if ($name == '') $name = isset($val['name']) ? $val['name'] : '';
                        }
                    }
                    if ($amountAll != 0) {
                        $multiple['data'][$times]['multiple'] = $times * $amountAll; // 总倍数
                        $time = min($checkTime);
                        $multiple['data'][$times]['time'] = $time < 0 ? 0 : $time; // 剩余时间
                        $multiple['data'][$times]['alltime'] = $timeSlot; // 总时间
                        $multiple['data'][$times]['page'] = $amountAll; // 总数量 
                        $multiple['data'][$times]['times'] = $times; //倍数
                        $multiple['data'][$times]['name'] = $name; //

                        $multiple['all']['multiple'] += $multiple['data'][$times]['multiple'];
                        $allTime = min($checkA);
                        $multiple['all']['time'] = $allTime < 0 ? 0 : $allTime;
                        $multiple['all']['alltime'] = min($checkB);
                        $multiple['all']['page'] += $multiple['data'][$times]['page'];
                    }
                }
            }
        }
        $con -> close();
        return $multiple;
    }

    /*
    *矿及数量
    */
    public static function digNum($qid)
    {
        $con = MyRedis::getConnectRead();
        $digKeyArr = array('c9301', 'c9302', 'c9303');
        $hkey = Tab::userCard.$qid;
        $num = 0;
        foreach ($digKeyArr as $key => $value) {
            $num += $con -> hget($hkey, $value);
        }
        $con -> close();
        return $num;
    }

    /*
    *矿机倍数
    */
    public static function getDigTimes($qid)
    {
        $con = MyRedis::getConnectRead();
        $key = $qid.'_kj_times';
        $times = $con -> get($key);
        if (!$times) return false;
        $con -> close();
        return $times;
    }

    /*
    * 查询用户使用中矿机数量
    */
    public static function checkDig($qid)
    {
        $con = MyRedis::getConnectRead();
        $hkey = $qid . '_k*';
        $hData = $con->keys($hkey);
        $i = 0;
        foreach ($hData as $key => $value) {
            $content = $con->hgetall($value);
            if (!empty($content)) {
                foreach ($content as $k => $val) {
                    $val = json_decode($val, true);
                    if ($val['status'] == 1) {
                        $i += $val['amount'];
                    }
                }
            }
        }
        $con->close();
        return $i;
    }
}