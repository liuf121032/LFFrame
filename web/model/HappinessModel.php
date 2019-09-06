<?php

namespace web\model;

use config\GroundConfig;
use database\MyRedis;

class HappinessModel extends BaseModel {

    /**
     * 购买幸福值前进行查询
     *
     * @param string $service
     * @param string $region
     * @param string $gid
     * @param string $facid
     * @param string $tvmid
     */
    public static function selectHappiness($service, $region, $gid, $facid, $tvmid)
    {
        $happyKey = GroundConfig::GROUND_HAPPY_KEY.$service."_".$region;
        $groundKey = GroundConfig::GROUND_KEY_HEAD.$service."_".$region;

        $readr = MyRedis::getConnectRead();

        if (!$readr->ping()) {
            return GroundModel::returnData(502, array('status' => 3), '服务器连接失败');
        }
        $ground = $readr->hget($groundKey, $gid);
        if ($ground == '') {
            return GroundModel::returnData(410, array('status' => 4), '地块加载失败');
        }
        $groundArray = json_decode($ground,true);
        $qid = getQid($tvmid);
        if (!$qid) {
            return GroundModel::returnData(403, array('status' => 7), '用户匹配错误');
        }
        if (!isset($groundArray['qid']) && $groundArray['qid'] != $qid) {
            return GroundModel::returnData(403, array('status' => 8), '用户记录错误');
        }
        if (isset($groundArray['facid']) && $groundArray['facid'] == $facid) {
            $happy = $readr->hget($happyKey, $gid);
            if ($happy == '' || !is_int((int)$happy)) {
                $readr->close();
                return GroundModel::returnData(410, array('status' => 6) ,'数据处理异常');
            } else {
                $remind = GroundConfig::GROUND_HAPPY_INIT - $happy;
                $money = ceil($remind / GroundConfig::GROUND_HAPPY_UNITPRICE);
                $return = array();
                $return['happiness'] = $happy;
                $return['coin'] = $money;
                $readr->close();
                return GroundModel::returnData(200, $return ,'ok');
            }
        } else {
            $readr->close();
            return GroundModel::returnData(410, array('status' => 5) ,'建筑处理异常');
        }
    }

    /**
     * 购买幸福值
     *
     * @param string $service
     * @param string $region
     * @param string $gid
     * @param string $facid
     * @param string $tvmid
     */
    public static function buyHappiness_old($service, $region, $gid, $facid, $tvmid)
    {
        $happyKey = GroundConfig::GROUND_HAPPY_KEY . $service . "_" . $region;
        $groundKey = GroundConfig::GROUND_KEY_HEAD . $service . "_" . $region;

        $r = MyRedis::getConnect();
        $readr = MyRedis::getConnectRead();

        if (!$readr->ping() || !$r->ping()) {
            return GroundModel::returnData(502, array('status' => 3), '服务器连接失败');
        }
        $keylock = GroundConfig::GROUND_HAPPY_KEY . 'lock' . '_' . $service . '_' . $region . '_' . $gid . '_' . $tvmid;
        $getLock = self::$r->set($keylock, 1, ["NX", "EX" => 5]);

        if (!$getLock) {
            self::$r->close();
            return self::return (503, '', '购买处理繁忙');
        }

        $ground = $readr->hget($groundKey, $gid);
        if ($ground == '') {
            self::$r->del($keylock);
            return GroundModel::returnData(410, array('status' => 4), '地块加载失败');
        }
        $groundArray = json_decode($ground, true);
        $qid = getQid($tvmid);
        if (!$qid) {
            self::$r->del($keylock);
            return self::returnData(406, array('status' => 7), '用户匹配错误');
        }

        if (!isset($groundArray['qid']) && $groundArray['qid'] != $qid) {
            self::$r->del($keylock);
            return self::returnData(406, array('status' => 8), '用户记录错误');
        }
        if (isset($groundArray['facid']) && $groundArray['facid'] == $facid) {
            $happy = $readr->hget($happyKey, $gid);
            if ($happy == '' || !is_int((int)$happy)) {
                self::$r->del($keylock);
                return GroundModel::returnData(410, array('status' => 6) ,'数据处理异常');
            } else {
                $remind = GroundConfig::GROUND_HAPPY_INIT - $happy;
                $money = ceil($remind / GroundConfig::GROUND_HAPPY_UNITPRICE);
                $userCoin = selectCoin($tvmid);
                if (isset($userCoin['data']['main'])) {
                    if ($userCoin['data']['main'] >= $money) {
                        $coin = UserCoinCore('minus', $tvmid, $money,'购买幸福值');
                        if (isset($coin['code']) && $coin['code'] == 200) {
                            $return = array();
                            $return['happiness'] = GroundConfig::GROUND_HAPPY_INIT;
                            $return['coin'] = $money;
                            $result = $r->hset($happyKey, $gid,GroundConfig::GROUND_HAPPY_INIT);
                            if ($result == 0) {
                                $logString = "7" . "\t" . $service . "\t" . $region . "\t" . $gid . "\t" . $facid . "\t" . $qid . "\t" .
                                    $remind . "\t" . GroundConfig::GROUND_HAPPY_INIT . "\t" . getMillisecond() . "\t" . "1" .
                                    "\t" . "126" . "\t" . $money;
                                GroundModel::writeRedislog($logString);
                                self::$r->del($keylock);
                                return GroundModel::returnData(200, $return ,'ok');
                            } else {
                                self::$r->del($keylock);
                                $r->close();
                                $readr->close();
                                return GroundModel::returnData(410, $return ,'幸福值处理异常');
                            }
                        } else {
                            self::$r->del($keylock);
                            $r->close();
                            $readr->close();
                            return self::returnData(410, array('status' => 9), '数据处理异常');
                        }
                    } else {
                        self::$r->del($keylock);
                        $r->close();
                        $readr->close();
                        return self::returnData(410, array('status' => 40), '金币余额不足');
                    }
                }
            }
        } else {
            $r->close();
            $readr->close();
            return GroundModel::returnData(410, array('status' => 5) ,'建筑处理异常');
        }
    }

    /**
     * 购买幸福值
     *
     * @param string $service
     * @param string $region
     * @param string $gid
     * @param string $facid
     * @param string $tvmid
     */
    public static function buyHappiness($service, $region, $gid, $facid, $tvmid)
    {
        $happyKey = GroundConfig::GROUND_HAPPY_KEY . $service . "_" . $region;
        $groundKey = GroundConfig::GROUND_KEY_HEAD . $service . "_" . $region;

        $r = MyRedis::getConnect();
        $readr = MyRedis::getConnectRead();

        if (!$readr->ping() || !$r->ping()) {
            return GroundModel::returnData(502, array('status' => 3), '服务器连接失败');
        }
        $keylock = GroundConfig::GROUND_HAPPY_KEY . 'lock' . '_' . $service . '_' . $region . '_' . $gid . '_' . $tvmid;
        $getLock = $r->set($keylock, 1, ["NX", "EX" => 5]);

        if (!$getLock) {
            $r->close();
            return GroundModel::returnData(503, '', '购买处理繁忙');
        }

        $ground = $readr->hget($groundKey, $gid);
        if ($ground == '') {
            $r->del($keylock);
            return GroundModel::returnData(410, array('status' => 4), '地块加载失败');
        }
        $groundArray = json_decode($ground, true);
        $qid = getQid($tvmid);
        if (!$qid) {
            $r->del($keylock);
            return GroundModel::returnData(406, array('status' => 7), '用户匹配错误');
        }

        if (!isset($groundArray['qid']) && $groundArray['qid'] != $qid) {
            $r->del($keylock);
            return GroundModel::returnData(406, array('status' => 8), '用户记录错误');
        }
        if (isset($groundArray['facid']) && $groundArray['facid'] == $facid) {
            $happy = $readr->hget($happyKey, $gid);
            if ($happy == '' || !is_int((int)$happy)) {
                $r->del($keylock);
                return GroundModel::returnData(410, array('status' => 6) ,'数据处理异常');
            } else {
                $remind = GroundConfig::GROUND_HAPPY_INIT - $happy;
                $money = ceil($remind / GroundConfig::GROUND_HAPPY_UNITPRICE);
                $userCoin = selectCoin($tvmid);
                if (isset($userCoin['data']['main'])) {
                    if ($userCoin['data']['main'] >= $money) {
                        $coin = UserCoinCore('minus', $tvmid, $money,'购买幸福值');
                        if (isset($coin['code']) && $coin['code'] != 200) {
                            $r->del($keylock);
                            $r->close();
                            $readr->close();
                            return GroundModel::returnData(410, array('status' => 9),'数据处理异常');
                        } else {
                            $return = array();
                            $return['happiness'] = GroundConfig::GROUND_HAPPY_INIT;
                            $return['coin'] = $money;
                            $result = $r->hset($happyKey,$gid,GroundConfig::GROUND_HAPPY_INIT);
                            if ($result == 0) {
                                $logString = "7" . "\t" . $service . "\t" . $region . "\t" . $gid . "\t" . $facid . "\t" . $qid . "\t" .
                                    $remind . "\t" . GroundConfig::GROUND_HAPPY_INIT . "\t" . getMillisecond() . "\t" . "1" .
                                    "\t" . "126" . "\t" . $money;
                                GroundModel::writeRedislog($logString);
                                $r->del($keylock);
                                return GroundModel::returnData(200, $return ,'ok');
                            } else {
                                $r->del($keylock);
                                $r->close();
                                $readr->close();
                                return GroundModel::returnData(410, $return ,'幸福值处理异常');
                            }
                        }
                    } else {
                        $r->del($keylock);
                        $r->close();
                        $readr->close();
                        return GroundModel::returnData(410, array('status' => 40),'金币余额不足');
                    }
                } else {
                    $r->del($keylock);
                    $r->close();
                    $readr->close();
                    return GroundModel::returnData(410, array('status' => 44),'金币余额不足');
                }
            }
        } else {
            $r->close();
            $readr->close();
            return GroundModel::returnData(410, array('status' => 5) ,'建筑处理异常');
        }
    }

    /**
     * 幸福值数据查询、添加、删除
     *
     * @param string $serverRegionPid
     * @param string $qid
     * @param string $type
     */
    public static function controlHappiness($serverRegionPid, $qid, $type = 'select')
    {
        $r = MyRedis::getConnect();
        $readr = MyRedis::getConnectRead();

        if (is_array($serverRegionPid)) {
            if ($type != 'select') {
                return false;
            }
            $for_result = array();
            foreach ($serverRegionPid as $id) {
                $input_id = explode("_",$id);
                if (count($input_id) != 5) {
                    continue;
                }

                $happyKey = GroundConfig::GROUND_HAPPY_KEY . $input_id[0] . '_' . $input_id[1];
                $gid = $input_id[2] . "_" . $input_id[3] . "_" . $input_id[4];
                $for_result[$id] = $readr->hget($happyKey, $gid);
            }
            return $for_result;
        }

        $input = explode("_", $serverRegionPid);
        if (count($input) != 5) {
            return false;
        }

        $groundKey = GroundConfig::GROUND_KEY_HEAD.$input[0] . '_' . $input[1];
        $happyKey = GroundConfig::GROUND_HAPPY_KEY.$input[0] . '_' . $input[1];
        $gid = $input[2] . "_" . $input[3] . "_" . $input[4];

        if ($type == 'insert' && !is_array($gid)) {
            $ground = $readr->hget($groundKey, $gid);
            if ($ground == '') {
                return false;
            }
            $groundArray = json_decode($ground,true);
            if ($groundArray['qid'] != $gid) {
                return false;
            }

            return $r->hset($happyKey, $gid,GroundConfig::GROUND_HAPPY_INIT);
        } elseif($type == 'delete' && !is_array($gid)) {
            $r->multi();
            $ground = $r->hget($groundKey, $gid);
            $r->exec();
            if ($ground == '') {
                return false;
            }
            $groundArray = json_decode($ground,true);
            if ($groundArray['qid'] != $qid) {
                return false;
            }
            $r->multi();
            $result = $r->hdel($happyKey, $gid);
            $r->exec();
            $r->close();
            $readr->close();
            return $result;
        } elseif($type == 'select') {
            $return = array();
            $return[$serverRegionPid] = $r->hget($happyKey, $gid);
            $r->close();
            $readr->close();
            return $return;
        }
    }

    /**
     * 打工减少幸福值
     *
     * @param string $service
     * @param string $region
     * @param string $gid
     * @param string $qid
     * @param string $facid
     * @param string $value
     */
    public static function reduceHappiness($service, $region, $gid, $qid, $facid, $value)
    {
        $groundKey = GroundConfig::GROUND_KEY_HEAD . $service . '_' . $region;
        $happyKey = GroundConfig::GROUND_HAPPY_KEY . $service . '_' . $region;

        $r = MyRedis::getConnect();
        $readr = MyRedis::getConnectRead();

        $ground = $readr->hget($groundKey, $gid);

        if ($ground == '') {
            return false;
        }
        $groundArray = json_decode($ground,true);
        if (!isset($groundArray['qid']) || !isset($groundArray['facid'])) {
            return false;
        }

        if ($groundArray['qid'] == $qid && $groundArray['facid'] == $facid) {
            $happy = $readr->hget($happyKey, $gid);
            if ($happy != '') {
                $happy = $happy - $value;
                if ($happy < 0) {
                    $happy = 0;
                }

                $r->hset($happyKey, $gid, $happy);
                $logString = "7" . "\t" . $service . "\t" . $region . "\t" . $gid . "\t" . $facid . "\t" . $qid .
                    "\t" . "-" . $value . "\t" . $happy . "\t" . getMillisecond() . "\t" . "3" . "\t" . "126" . "\t" . "0";
                GroundModel::writeRedislog($logString);
            } else {
                $r->close();
                $readr->close();
                return false;
            }
        } else {
            $r->close();
            $readr->close();
            return false;
        }
        $r->close();
        $readr->close();
        return true;
    }

    /**
     * 增删工厂时更新幸福值数据
     *
     * @param string $service
     * @param string $region
     * @param string $gid
     * @param string $type
     * @param string $facid
     * @param string $qid
     */
    public static function addHappiness($service, $region, $gid, $type, $facid, $qid)
    {
        $r = MyRedis::getConnect();
        if (!$r->ping()) {
            return false;
        }
        $happyKey = GroundConfig::GROUND_HAPPY_KEY . $service . '_' . $region;
        if ($type == 'add') {
            $result = $r->hset($happyKey, $gid, GroundConfig::GROUND_HAPPY_INIT);
            if ($result == 1) {
                //mLogs("add succ","addHappiness",$happyKey.$gid,'happiness');
                $logString = "7" . "\t" . $service . "\t" . $region . "\t" . $gid . "\t" . $facid . "\t" . $qid . "\t" .
                    GroundConfig::GROUND_HAPPY_INIT . "\t" . GroundConfig::GROUND_HAPPY_INIT .
                    "\t" . getMillisecond() . "\t" . "4" . "\t" . "126" . "\t" . "0";
                GroundModel::writeRedislog($logString);
                $r->close();
                return true;
            } else {
                mLogs("add failed","addHappiness",$happyKey.$gid,'happiness');
                $r->close();
                return false;
            }
        } elseif($type == 'del') {
            $result = $r->hdel($happyKey, $gid);
            if ($result == 1) {
                //mLogs("del succ","addHappiness",$happyKey.$gid,'happiness');
                $logString = "7" . "\t" . $service . "\t" . $region . "\t" . $gid . "\t" . $facid . "\t" . $qid . "\t" .
                    "-" . GroundConfig::GROUND_HAPPY_INIT . "\t" . "0" . "\t" . getMillisecond() . "\t" . "5" . "\t" . "126" . "\t" . "0";
                GroundModel::writeRedislog($logString);
                $r->close();
                return true;
            } else {
                mLogs("del failed","addHappiness",$happyKey.$gid,'happiness');
                $r->close();
                return false;
            }
        }
    }

    /**
     * 计算地块幸福影响范围
     *
     * @param string $gid
     */
    public static function groundNearby($gid)
    {
        $temp_gid = explode("_", $gid);
        $buffer = array();
        if (isset($temp_gid[0]) && isset($temp_gid[1]) && isset($temp_gid[2])) {
            $x = (int)substr($temp_gid[0],strpos($temp_gid[0],"a") + 1);
            $y = (int)$temp_gid[1];
            $b = (int)$temp_gid[2];

            switch ($b) {
                case 1:
                    $buffer[] = "a".$x."_".$y."_14";
                    if(self::check_limit($y-1,'y'))
                        $buffer[] = "a".$x."_".($y-1)."_43";
                    if(self::check_limit($y-1,'y'))
                        $buffer[] = "a".$x."_".($y-1)."_4";
                    $buffer[] = "a".$x."_".$y."_21";
                    if(self::check_limit($x+1,'x'))
                        $buffer[] = "a".($x+1)."_".$y."_14";
                    $buffer[] = "a".$x."_".$y."_2";
                    $buffer[] = "a".$x."_".$y."_3";
                    $buffer[] = "a".$x."_".$y."_4";
                    break;
                case 2:
                    if(self::check_limit($x-1,'x'))
                        $buffer[] = "a".($x-1)."_".$y."_3";
                    if(self::check_limit($x-1,'x'))
                        $buffer[] = "a".($x-1)."_".$y."_32";
                    $buffer[] = "a".$x."_".$y."_43";
                    $buffer[] = "a".$x."_".$y."_14";
                    if(self::check_limit($y-1,'y'))
                        $buffer[] = "a".$x."_".($y-1)."_43";
                    $buffer[] = "a".$x."_".$y."_1";
                    $buffer[] = "a".$x."_".$y."_3";
                    $buffer[] = "a".$x."_".$y."_4";
                    break;
                case 3:
                    if(self::check_limit($y+1,'y'))
                        $buffer[] = "a".$x."_".($y+1)."_21";
                    if(self::check_limit($x+1,'x'))
                        $buffer[] = "a".($x+1)."_".$y."_2";
                    if(self::check_limit($x+1,'x'))
                        $buffer[] = "a".($x+1)."_".$y."_14";
                    $buffer[] = "a".$x."_".$y."_21";
                    $buffer[] = "a".$x."_".$y."_32";
                    $buffer[] = "a".$x."_".$y."_1";
                    $buffer[] = "a".$x."_".$y."_2";
                    $buffer[] = "a".$x."_".$y."_4";
                    break;
                case 4:
                    if(self::check_limit($x-1,'x'))
                        $buffer[] = "a".($x-1)."_".$y."_32";
                    $buffer[] = "a".$x."_".$y."_43";
                    if(self::check_limit($y+1,'y'))
                        $buffer[] = "a".$x."_".($y+1)."_1";
                    if(self::check_limit($y+1,'y'))
                        $buffer[] = "a".$x."_".($y+1)."_21";
                    $buffer[] = "a".$x."_".$y."_32";
                    $buffer[] = "a".$x."_".$y."_1";
                    $buffer[] = "a".$x."_".$y."_2";
                    $buffer[] = "a".$x."_".$y."_3";
                    break;
                case 14:
                    if(self::check_limit($x-1,'x'))
                        $buffer[] = "a".($x-1)."_".$y."_21";
                    if(self::check_limit($x-1,'x') && self::check_limit($y-1,'y'))
                        $buffer[] = "a".($x-1)."_".($y-1)."_32";
                    if(self::check_limit($y-1,'y'))
                        $buffer[] = "a".$x."_".($y-1)."_43";
                    $buffer[] = "a".$x."_".$y."_32";
                    if(self::check_limit($x-1,'x'))
                        $buffer[] = "a".($x-1)."_".$y."_1";
                    if(self::check_limit($x-1,'x'))
                        $buffer[] = "a".($x-1)."_".$y."_3";
                    $buffer[] = "a".$x."_".$y."_1";
                    $buffer[] = "a".$x."_".$y."_2";
                    break;
                case 21:
                    if(self::check_limit($x+1,'x'))
                        $buffer[] = "a".($x+1)."_".$y."_14";
                    if(self::check_limit($x+1,'x') && self::check_limit($y-1,'y'))
                        $buffer[] = "a".($x+1)."_".($y-1)."_43";
                    if(self::check_limit($y-1,'y'))
                        $buffer[] = "a".$x."_".($y-1)."_43";
                    if(self::check_limit($y-1,'y'))
                        $buffer[] = "a".$x."_".($y-1)."_4";
                    if(self::check_limit($y-1,'y'))
                        $buffer[] = "a".$x."_".($y-1)."_3";
                    if(self::check_limit($y-1,'y'))
                        $buffer[] = "a".$x."_".($y-1)."_32";
                    $buffer[] = "a".$x."_".$y."_1";
                    $buffer[] = "a".$x."_".$y."_3";
                    break;
                case 32:
                    if(self::check_limit($y+1,'y'))
                        $buffer[] = "a".$x."_".($y+1)."_21";
                    if(self::check_limit($x+1,'x') && self::check_limit($y+1,'y'))
                        $buffer[] = "a".($x+1)."_".($y+1)."_14";
                    if(self::check_limit($x+1,'x'))
                        $buffer[] = "a".($x+1)."_".$y."_43";
                    $buffer[] = "a".$x."_".$y."_3";
                    $buffer[] = "a".$x."_".$y."_4";
                    if(self::check_limit($x+1,'x'))
                        $buffer[] = "a".($x+1)."_".$y."_4";
                    if(self::check_limit($x+1,'x'))
                        $buffer[] = "a".($x+1)."_".$y."_2";
                    if(self::check_limit($x+1,'x'))
                        $buffer[] = "a".($x+1)."_".$y."_14";
                    break;
                case 43:
                    if(self::check_limit($x-1,'x'))
                        $buffer[] = "a".($x-1)."_".$y."_32";
                    if(self::check_limit($y+1,'y'))
                        $buffer[] = "a".$x."_".($y+1)."_14";
                    if(self::check_limit($y+1,'y'))
                        $buffer[] = "a".$x."_".($y+1)."_2";
                    if(self::check_limit($y+1,'y'))
                        $buffer[] = "a".$x."_".($y+1)."_1";
                    if(self::check_limit($y+1,'y'))
                        $buffer[] = "a".$x."_".($y+1)."_21";
                    if(self::check_limit($x-1,'x') && self::check_limit($y+1,'y'))
                        $buffer[] = "a".($x-1)."_".($y+1)."_21";
                    $buffer[] = "a".$x."_".$y."_2";
                    $buffer[] = "a".$x."_".$y."_4";
                    break;
            }
        }
        return $buffer;
    }

    /**
     * 幸福值地块限制计算
     *
     * @param string $v
     * @param string $t
     */
    private static function check_limit($v, $t){
        if($t == 'x'){
            if($v >= 0 && $v <= 6)
                return true;
            else
                return false;
        }elseif($t == 'y'){
            if($v >= 0 && $v <= 12)
                return true;
            else
                return false;
        }else{
            return false;
        }
    }
}