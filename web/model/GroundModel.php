<?php
namespace web\model;

use database\MySql;
use database\MyRedis;
use config\GroundConfig;
use config\ProduceConfig;

class GroundModel extends BaseModel {

    public static $r;

    public function __destruct()
    {
        self::$r->close();
    }

    /**
     * 获取游戏主页背景及地块数据
     *
     * @param string $service
     * @param string $region
     * @param string $tvmid
     * @param array $user
     */
    public static function allGround($service, $region, $tvmid, $user)
    {
        self::$r = MyRedis::getConnect();
        $qid = getQid($tvmid);
        if (!$qid) {
            return self::returnData(403,'','user system error');
        }

        if ($user == '') {
            $user = self::userinfonew($tvmid);
        }

        if (!self::$r->ping()) {
            return self::returnData(502,'','data connect timeout');
        }

        $groundKey = GroundConfig::GROUND_KEY_HEAD . $service . '_' . $region;
        $backKey = GroundConfig::BACKGROUND_KEY_HEAD . $service . '_' . $region;

        $ground = self::$r->hgetall($groundKey);
        $background = self::$r->hgetall($backKey);

        $return = array();

        if (is_null($ground) || !$ground) {
            $return['target'] = 'null';
            $return['house'] = 'null';
            return self::returnData(403,'','ground system error');
        }

        $target = array();
        $house = array();
        foreach ($background as $key => $value) {
            if ($value != '') {
                $b = json_decode($value,true);
                $house[] = $b;
            }
        }
        foreach ($ground as $kk => $vv) {
            $h = json_decode($vv,true);
            if (!isset($h['xI'])) {
                continue;
            }
            if (isset($h['qid'])) {
                $tvmid = getTvmId($h['qid']);
                if (is_string($tvmid)) {
                    $h['tvmid'] = $tvmid;
                } else {
                    $h['tvmid'] = '0';
                }
                $temp_user = self::userinfonew($tvmid);
                $h['nickname'] = isset($temp_user['nickname']) ? $temp_user['nickname'] : "";
                $h['headimg'] = isset($temp_user['headimg']) ? $temp_user['headimg'] : "";
                $userinfo = CenterModel::userEx($h['qid']);
                if (isset($userinfo['level'])) {
                    $h['userlevel'] = $userinfo['level'];
                } else {
                    $h['userlevel'] = 1;
                }
                if (isset($h['facid']) && isset($h['qid'])) {
                    $happy = HappinessModel::controlHappiness($service . "_" . $region . "_" . $kk, $h['qid'],'select');
                    if ($happy != '' && isset($happy[$service."_".$region."_".$kk])) {
                        $h['happy'] = $happy[$service . "_" . $region . "_" . $kk];
                    }
                    $h['happymax'] = GroundConfig::GROUND_HAPPY_INIT;
                    $h['work'] = self::getWorkstatus($h['qid'], $h['facid']);
                }
                unset($h['qid']);
            }
            $house[] = $h;
            $target[$kk] = 1;
        }

        $return['target'] = $target;
        $return['house'] = $house;
        $userinfo = array();
        $userinfo['headimg'] = isset($user['headimg']) ? $user['headimg'] : "";
        $userinfo['nickname'] = isset($user['nickname']) ? $user['nickname'] : "";
        $userinfo['userlevel'] = isset($user['level']) ? $user['level'] : "";
        $return['user'] = $userinfo;
        return $return;
    }

    /**
     * 批量获取游戏主页背景及地块数据
     *
     * @param string $service
     * @param string $region
     * @param string $tvmid
     * @param array $user
     * @param string $ground
     */
    public static function partGround($service, $region, $tvmid, $user, $ground)
    {
        $ground = str_replace("[","", $ground);
        $ground = str_replace("]","", $ground);
        $ground = str_replace("\"","", $ground);
        $ground = str_replace("\'","", $ground);
        $array_ground = explode(",", $ground);
        if (count($array_ground) < 1) {
            return self::returnData(404,'','地块加载失败');
        }

        self::$r = MyRedis::getConnectRead();
        $redis = MyRedis::getConnectRead();
        $happyredis = MyRedis::getConnectRead();
        $mysql = MySql::getConnect('quanming2');

        $qid = getQid($tvmid);
        if (!$qid) {
            $qid = getQid($tvmid);
            if (!$qid) {
                return self::returnData(403,'','用户匹配错误');
            }
        }

        if (!isset($user['headimg']) || !isset($user['nickname'])) {
            $user = self::userinfonew($tvmid);
        }

        if (!self::$r->ping() || !$redis->ping() || !$happyredis->ping()) {
            return self::returnData(502,'','服务器连接失败');
        }

        $house = array();
        $return = array();

        $groundKey = GroundConfig::GROUND_KEY_HEAD . $service . '_' . $region;
        $allground = self::$r->hgetall($groundKey);
        $redis->multi(2);
        $happyredis->multi(2);

        foreach ($array_ground as $index => $key) {
            if (isset($allground[$key])) {
                $json_h = $allground[$key];
            } else {
                continue;
            }
            if ($json_h == '') {
                continue;
            }
            $h = json_decode($json_h,true);
            if (!isset($h['xI'])) {
                continue;
            }
            if (isset($h['qid'])) {
                $tvmid = getTvmId($h['qid']);
                if (is_string($tvmid)) {
                    $h['tvmid'] = $tvmid;
                } else {
                    $h['tvmid'] = '0';
                }
                if (isset($h['facid']) && isset($h['qid'])) {
                    $happyredis->hget(GroundConfig::GROUND_HAPPY_KEY . $service . "_" . $region,$key);
                    $h['happymax'] = GroundConfig::GROUND_HAPPY_INIT;
                    $redis->exists(ProduceConfig::PRODUCE_PREFIX.$h['qid'] . ":" . $h['facid']);
                }
            }
            $sale_list = self::$r->hexists(GroundConfig::GROUND_SALE_LIST,$service . "_" . $region . "_" . $key);
            if ($sale_list == 1) {
                $sql = sprintf("select method from groundmarket 
                       where service = '%s' and region = '%s' and groundid = '%s' and status = 1", $service, $region, $key);

                $result = $mysql->query($sql,\PDO::FETCH_ASSOC);
                $market = $result->fetchAll();
                if (isset($market[0]['method'])) {
                    if ($market[0]['method'] == 1) {
                        $h['status'] = "sell";
                    } else {
                        $h['status'] = "auction";
                    }
                } else {
                    $h['status'] = "sell";
                }
            }

            $house[] = $h;
        }

        $works = $redis->exec();
        $happys = $happyredis->exec();
        $i = 0;
        $j = 0;
        $realhouse = array();
        foreach ($house as $k) {
            if (isset($k['facid']) && isset($k['qid'])) {
                if ($works[$i] == true) {
                    $k['work'] = 'working';
                } else {
                    $k['work'] = 'waiting';
                }
                if ($happys[$j] != '') {
                    $k['happy'] = $happys[$j];
                }
                $j += 1;
                $i += 1;
            }
            unset($k['qid']);
            $realhouse[] = $k;
        }

        $return['house'] = $realhouse;
        $userinfo = array();
        $userinfo['headimg'] = isset($user['headimg']) ? $user['headimg'] : "";
        $userinfo['nickname'] = isset($user['nickname']) ? $user['nickname'] : "";
        $mainuser = @CenterModel::userEx($qid);
        if (isset($mainuser['level'])) {
            $userinfo['userlevel'] = $mainuser['level'];
        } else {
            $userinfo['userlevel'] = 1;
        }
        $return['user'] = $userinfo;
        $redis->close();
        $happyredis->close();

        return self::returnData(0, $return,'');
    }

    public static function test($service, $region, $ground)
    {
        $ground = str_replace("[","", $ground);
        $ground = str_replace("]","", $ground);
        $ground = str_replace("\"","", $ground);
        $ground = str_replace("\'","", $ground);
        $array_ground = explode(",",$ground);
        if (count($array_ground) < 1) {
            return self::returnData(404,'','地块加载失败');
        }

        self::$r = MyRedis::getConnectRead();
        $redis = MyRedis::getConnectRead();
        $happyredis = MyRedis::getConnectRead();

        if (!self::$r->ping() || !$redis->ping() || !$happyredis->ping()) {
            return self::returnData(502,'','服务器连接失败');
        }

        $house = array();
        $return = array();

        $groundKey = GroundConfig::GROUND_KEY_HEAD . $service . '_' . $region;var_dump(self::$r->keys('*'));
        $allground = self::$r->hgetall($groundKey);var_dump($allground);
        $redis->multi(2);
        $happyredis->multi(2);

        foreach ($array_ground as $index => $key) {
            if (isset($allground[$key])) {
                $json_h = $allground[$key];
            } else {
                continue;
            }
            if ($json_h == '') {
                continue;
            }
            $h = json_decode($json_h,true);
            if (!isset($h['xI'])) {
                continue;
            }
            if (isset($h['qid'])) {
                $tvmid = getTvmId($h['qid']);
                if (is_string($tvmid)) {
                    $h['tvmid'] = $tvmid;
                } else {
                    $h['tvmid'] = '0';
                }

                if (isset($h['facid']) && isset($h['qid'])) {
                    $happyredis->hget(GroundConfig::GROUND_HAPPY_KEY . $service . "_" . $region, $key);
                    $h['happymax'] = GroundConfig::GROUND_HAPPY_INIT;
                    $redis->exists(ProduceConfig::PRODUCE_PREFIX.$h['qid'] . ":" . $h['facid']);
                }
            }
            $house[] = $h;
        }

        $works = $redis->exec();
        $happys = $happyredis->exec();
        $i = 0;
        $j = 0;
        $realhouse = array();
        foreach ($house as $k) {
            if (isset($k['facid']) && isset($k['qid'])) {
                if ($works[$i] == true) {
                    $k['work'] = 'working';
                } else {
                    $k['work'] = 'waiting';
                }
                if ($happys[$j] != '') {
                    $k['happy'] = $happys[$j];
                }
                $j += 1;
                $i += 1;
            }
            unset($k['qid']);
            $realhouse[] = $k;
        }

        $return['house'] = $realhouse;
        $userinfo = array();
        $userinfo['headimg'] = isset($user['headimg']) ? $user['headimg'] : "";
        $userinfo['nickname'] = isset($user['nickname']) ? $user['nickname'] : "";

        $redis->close();
        $happyredis->close();

        return self::returnData(0, $return, '');
    }

    /**
     * 创建或删除工厂的同时更新地块数据
     *
     * @param string $service
     * @param string $region
     * @param string $gid
     * @param string $qid
     * @param string $facid
     * @param string $src
     * @param string $type
     * @param string $factype
     * @param string $fid
     */
    public static function groundFactory($service, $region, $gid, $qid, $facid, $src, $type, $factype, $fid)
    {
        self::$r = MyRedis::getConnect();
        if ($service == '' || $region == '' || $gid == '' || $facid == '' || $src == '' || $qid == '' || $factype == '' || $fid == '') {
            return false;
        }

        $groundKey = GroundConfig::GROUND_KEY_HEAD . $service . '_' . $region;
        $log_info = $service . "-" . $region . "-" . $gid . "-" . $qid . "-" . $facid . "-" . $src;

        $ground = self::$r->hget($groundKey, $gid);

        if ($ground == '') {
            //mLogs("ground null failed","groundFactory",$log_info . "-ground error",'ground');
            return false;
        } else {
            if ($type == 'add') {
                $groundArray = json_decode($ground,true);
                if ($groundArray['qid'] == $qid) {
                    $groundArray['src'] = $src;
                    $groundArray['facid'] = $facid;
                    $groundArray['factype'] = $factype;
                    $groundArray['fid'] = $fid;
                    $groundArray['status'] = "normal";
                    if (self::$r->hset($groundKey, $gid, json_encode($groundArray)) == 0) {
                        HappinessModel::addHappiness($service, $region, $gid, "add", $facid, $qid);
                        if ($factype == GroundConfig::PUBLIC_BUILDING_TYPE) {
                            self::publicBuilding($service, $region, $gid, 'add');
                        }
                        //mLogs("add succ","groundFactory",$log_info,'ground');
                        return true;
                    } else {
                        mLogs("hset failed","groundFactory",$log_info . "-hset failed",'ground');
                        return false;
                    }
                } else {
                    mLogs("qid failed","groundFactory",$log_info . "-user denied",'ground');
                    return false;
                }
            } elseif ($type == 'del') {
                $groundArray = json_decode($ground,true);
                if ($groundArray['qid'] == $qid) {
                    unset($groundArray['facid']);
                    unset($groundArray['factype']);
                    unset($groundArray['fid']);
                    $groundArray['src'] = GroundConfig::GROUND_SELLED_PNG;
                    $groundArray['status'] = GroundConfig::GROUND_SELLED_STATUS;
                    if (self::$r->hset($groundKey, $gid, json_encode($groundArray)) == 0) {
                        HappinessModel::addHappiness($service, $region, $gid, "del", $facid, $qid);
                        if ($factype == GroundConfig::PUBLIC_BUILDING_TYPE) {
                            self::publicBuilding($service, $region, $gid, 'del');
                        }
                        //mLogs("del succ","groundFactory",$log_info,'ground');
                        return true;
                    } else {
                        mLogs("del hset failed","groundFactory",$log_info . "-hset failed",'ground');
                        return false;
                    }
                }
            }
        }
    }

    /**
     * 指定地块数据回滚
     *
     * @param string $service
     * @param string $region
     * @param string $gid
     */
    public static function groundRollback($service, $region, $gid)
    {
        self::$r = MyRedis::getConnect();
        $groundKey = GroundConfig::GROUND_KEY_HEAD . $service . '_' . $region;
        $ground = self::$r->hget($groundKey, $gid);
        if ($ground == '') {
            return self::returnData(410, array('status' => 3),'ground not exist');
        } else {
            $groundArray = json_decode($ground,true);
            if (isset($groundArray['qid'])) {
                unset($groundArray['qid']);
                unset($groundArray['fid']);
                unset($groundArray['nickname']);
                unset($groundArray['headimg']);
                unset($groundArray['facid']);
                $groundArray['status'] = GroundConfig::GROUND_FORSALE_STATUS;
                $groundArray['src'] = GroundConfig::GROUND_ROLLBACK_PNG;
                self::$r->hset($groundKey, $gid, json_encode($groundArray));
            } else {
                return self::returnData(410, array('status' => 3), 'ground status error');
            }
        }

        return self::returnData(200, array('status' => 0), 'rollback well');
    }

    /**
     * 购买地块
     *
     * @param string $service
     * @param string $region
     * @param string $gid
     * @param string $tvmid
     * @param string $token
     * @param string $nickname
     * @param string $headimg
     */
    public static function groundBuy($service, $region, $gid, $tvmid, $token, $nickname, $headimg)
    {
//        if(!self::bugCheck($tvmid)){
//            return self::returnData(505,'','服务器连接失败');
//        }

        self::$r = MyRedis::getConnect();
        if (!self::$r->ping()) {
            return self::returnData(502,  '',  '服务器连接失败');
        }
        $keylock = GroundConfig::GROUND_BUY_LOCK . $service . '_' . $region . '_' . $gid;
        $getLock = self::$r->set($keylock, 1, ["NX","EX" => 5]);

        if (!$getLock) {
            self::$r->close();
            return self::return(503, '', '交易处理繁忙');
        }

        $groundKey = GroundConfig::GROUND_KEY_HEAD . $service . '_' . $region; //地块ID集合
        $ground = self::$r->hget($groundKey, $gid); //查看地块ID是否合法
        if ($ground == '') {
            self::$r->del($keylock);
            return self::returnData(410, array('status' => 3), '地块不合法');
        } else {
            $groundArray = json_decode($ground, true);  //返回地块信息
            if (!isset($groundArray['qid'])) {   //检查地块是否被购买
                $qid = getQid($tvmid);
                if (!$qid) {
                    self::$r->del($keylock);
                    return self::returnData(470, array('status' => 5), '用户匹配错误');
                }
                if (substr($groundArray['src'], -7) == GroundConfig::GROUND_UNSELL_PNG) {
                    self::$r->del($keylock);
                    return self::returnData(471, array('status' => 7), '非出售区域');
                }
                $check = self::limitCheck($tvmid, $qid); //检查用户地块是否有5块地
                if ($check == 1) {  //表示可以购买操作
                    $userCoin = selectCoin($tvmid);
                    if (isset($userCoin['data']['main'])) {
                        if ($groundArray['price'] == 1 && substr($groundArray['src'],-5) == GroundConfig::GROUND_HIGHPRICE_PNG) {
                            //判断是否是高价地块
                            if ($userCoin['data']['main'] >= GroundConfig::GROUND_HIGH_PRICE) {
                                $coin = UserCoinCore('minus', $tvmid, GroundConfig::GROUND_HIGH_PRICE,'地块购买');
                            } else {
                                self::$r->del($keylock);
                                return self::returnData(472, array('status' => 40), '金币不足');
                            }
                        } else {
                            if ($userCoin['data']['main'] >= GroundConfig::GROUND_LOW_PRICE) {
                                $coin = UserCoinCore('minus', $tvmid, GroundConfig::GROUND_LOW_PRICE, '地块购买');
                            } else {
                                self::$r->del($keylock);
                                return self::returnData(473, array('status' => 40), '金币不足');
                            }
                        }
                        if (isset($coin['code']) && $coin['code'] == 200) {
                            $groundArray['qid'] = $qid;
                            $groundArray['status'] = "hold";
                            $groundArray['src'] = GroundConfig::GROUND_SELLED_PNG;//购买后的地块背景默认图
                            $groundArray['nickname'] = $nickname;
                            $groundArray['headimg'] = $headimg;
                            $now = getMillisecond();
                            self::$r->hset($groundKey, $gid, json_encode($groundArray));
                            self::$r->hset(GroundConfig::GROUND_KEY_HEAD . $qid,$service . '_' . $region . '_' . $gid, $now);
                            $tmp_str = $service . '_' . $region . '_' . $gid . '_' . $groundArray['price'];
                            self::$r->hset(GroundConfig::GROUND_LOGS_HEAD, $now . '_' . $qid, $tmp_str);

                            //推送日志
                            if ($groundArray['price'] == 1 && substr($groundArray['src'],-5) == GroundConfig::GROUND_HIGHPRICE_PNG) {
                                $log_coin = GroundConfig::GROUND_HIGH_PRICE;
                            } else {
                                $log_coin = GroundConfig::GROUND_LOW_PRICE;
                            }
                            $logString = "1" . "\t" . $service . "\t" . $region . "\t" . $gid . "\t" .
                                $qid . "\t" . "1" . "\t" . getMillisecond() . "\t" . "126" . "\t" . $log_coin . "\t" . "0";
                            self::writeRedislog($logString);  //推送日志
                            self::$r->del($keylock);
                            return self::returnData(200, array('status' => 0), '购买成功');
                        } else {
                            self::$r->del($keylock);
                            return self::returnData(474, array('status' => 6), 'coin deal error'); //金币操作失败
                        }
                    }
                } elseif($check == 2) {
                    self::$r->del($keylock);
                    return self::returnData(491, array('status' => 9), '很抱歉,在全民买楼1中拥有至少一栋房产的玩家才有购买权利');
                } elseif($check == 3) {
                    self::$r->del($keylock);
                    $msg = '每个用户限购'.GroundConfig::USER_BUY_LIMIT . '块黄金地块';
                    return self::returnData(492, array('status' => 10), $msg);
                }
            } else {
                self::$r->del($keylock);
                return self::returnData(475, array('status' => 4), '地块已出售');
            }
        }
    }

    /**
     * 地块预售
     *
     * @param string $service
     * @param string $region
     * @param string $price
     * @param string $amount
     * @param string $type
     * @param string $tvmid
     */
    public static function groundPresale($service, $region, $price, $amount, $type, $tvmid)
    {
        $timec = time();
        if ($timec < GroundConfig::GROUND_PRESALE_BEGIN || $timec > GroundConfig::GROUND_PRESALE_END) {
            return self::returnData(501, '', '不在抢购活动时间内');
        }

        $mysql = MySql::getConnect('quanming2');
        $redis = MyRedis::getConnect();
        if (!$redis->ping() || !$mysql) {
            return self::returnData(502, '', '服务器连接失败');
        }
        $lowprice = GroundConfig::GROUND_LOW_PRICE;
        $highprice = GroundConfig::GROUND_HIGH_PRICE;
        $coin = $price;

        if ($type == '1') {
            if ($amount > GroundConfig::GROUND_HIGH_AMOUNT) {
                $redis->close();
                return self::returnData(495, array('status' => 4), '很抱歉,申请总数超出高级地块上限');
            }
            if ($coin < $highprice) {
                $redis->close();
                return self::returnData(492, array('status' => 2), '价格应不低于' . (GroundConfig::GROUND_HIGH_PRICE) . '金币');
            }
        } elseif($type == '2') {
            if ($amount > GroundConfig::GROUND_LOW_AMOUNT) {
                $redis->close();
                return self::returnData(496, array('status' => 4), '很抱歉,申请总数超出普通地块上限');
            }
            if ($coin < $lowprice) {
                $redis->close();
                return self::returnData(492, array('status' => 3), '价格应不低于' . (GroundConfig::GROUND_LOW_PRICE) . '金币');
            }
        }

        $keylock = GroundConfig::GROUND_PRESALE_LOCK . $service . '_' . $region . '_' . $tvmid;
        $getLock = $redis->set($keylock, 1, ["NX","EX" => 5]);

        if (!$getLock) {
            $redis->close();
            return self::returnData(503, '', '交易处理繁忙');
        }

        $qid = getQid($tvmid);

        $sql = sprintf("select sum(amount) as amount from groundpresale where tvmid = '%s' and groundt = '%s'", $tvmid, $type);
        $res = $mysql->query($sql, \PDO::FETCH_ASSOC);
        $result_arr = $res->fetchAll();
        if (isset($result_arr[0]['amount'])) {
            if ($type == '1') {
                if ($result_arr[0]['amount'] >= GroundConfig::GROUND_HIGH_AMOUNT) {
                    $redis->del($keylock);
                    $redis->close();
                    return self::returnData(493, array('status' => 4), '很抱歉,申请总数超出上限');
                }
            } else {
                if ($result_arr[0]['amount'] >= GroundConfig::GROUND_LOW_AMOUNT) {
                    $redis->del($keylock);
                    $redis->close();
                    return self::returnData(493, array('status' => 5), '很抱歉,申请总数超出上限');
                }
            }
        }
        $now = getMillisecond();
        $r = md5($tvmid . $now);
        $strl = substr($r, 0, 8);
        $strr = substr($r, 16, 8);
        $orderid = $strl . $strr;
        $insert = sprintf("insert into groundpresale(createtime,groundt,tvmid,qid,price,amount,coin,orderid,ground) values 
                ('%s','%s','%s','%s','%s','%s','%s','%s','%s')",
                $now, $type, $tvmid, $qid, $price, $amount, $coin, $orderid, $service . "_" . $region);
        $exec = $mysql->prepare($insert);
        $mysql->beginTransaction();
        $state = $exec->execute();
        if ($state == true) {
            $request_log = $orderid . "\t" . $service . "\t" . $region . "\t" .
                    $qid . "\t" . $now . "\t" . $amount . "\t" . "126" . "\t" . $coin * $amount;
            $redis->hset(GROUND_PRESALE_REDIS . "_request", $orderid, $request_log);
            $func = self::presaleFunc($tvmid, $orderid, $coin * $amount);
            if (isset($func['success'])) {
                $logString = "28" . "\t" . $orderid . "\t" . $service . "\t" . $region . "\t" .
                    $qid . "\t" . $now . "\t" . $amount . "\t" . "126" . "\t" . $coin * $amount;
                self::writeRedislog($logString);
                $mysql->commit();
                $redis->hset(GROUND_PRESALE_REDIS, $orderid, $logString);
                $redis->del($keylock);
                $redis->close();
                if ($type == '1') {
                    $massge = sprintf("您在淘金开发区罗湖区-2竞拍%s块高级黄金地块投入%s金币", $amount, $coin * $amount);
                } else {
                    $massge = sprintf(GroundConfig::PRESALE_GROUND_MASSGE, $amount, $coin * $amount);
                }
                pushMsgDSHB($tvmid, $massge);
                return self::returnData(200, array('status' => 0), '成功');
            } else {
                if (isset($func['code']) && $func['code'] == 122) {
                    $msg = $func['msg'];
                } else {
                    $msg = "操作失败";
                }
                $mysql->rollBack();
                $redis->del($keylock);
                $redis->close();
                return self::returnData(494, array('status' => 6), $msg);
            }
        } else {
            $redis->del($keylock);
            $redis->close();
            return self::returnData(505, array('status' => 7), '数据处理异常');
        }
    }

    /**
     * 地块预售查询
     *
     * @param string $service
     * @param string $region
     * @param string $tvmid
     */
    public static function presaleSelect($service, $region, $tvmid)
    {
        $mysql = MySql::getConnect('quanming2');

        if (!$mysql) {
            return self::returnData(502, '', '服务器连接失败');
        }
        $sql = sprintf("select price,amount,groundt,createtime from groundpresale 
               where ground = '%s' and tvmid = '%s'", $service . "_" . $region, $tvmid);
        $res = $mysql->query($sql, \PDO::FETCH_ASSOC);
        $data = array();
        $temp = array();
        foreach ($res->fetchAll() as $row) {
            $temp['type'] = $row['groundt'];
            $temp['price'] = $row['price'];
            $temp['amount'] = $row['amount'];
            $temp['total'] = $row['amount'] * $row['price'];
            $temp['datetime'] = date('Y-m-d H:i:s', substr($row['createtime'], 0, strlen($row['createtime']) - 3));
            $data[] = $temp;
        }
        return self::returnData(200, $data , 'ok');
    }

    public static function groundBuy_old($service, $region, $gid, $tvmid, $token, $nickname, $headimg)
    {
        if (time() < GroundConfig::GROUND_PRESALE_BEGIN || time() > GroundConfig::GROUND_PRESALE_END) {
            return self::returnData(501, '', '不在抢购活动时间内');
        }
        $lock_file = self::getLock();
        $fp = fopen($lock_file, "r+");
        $file_result = flock($fp, LOCK_EX); //给文件上锁
        if (!$file_result) {
            return self::returnData(502,'', '交易处理错误');
        }
        self::$r = MyRedis::getConnect();
        if (!self::$r->ping()) {
            flock($fp, LOCK_UN);// 给文件解锁
            fclose($fp);
            return self::returnData(502, '', '服务器连接失败');
        }
        $groundKey = GroundConfig::GROUND_KEY_HEAD . $service . '_' . $region; //地块ID集合
        $ground = self::$r->hget($groundKey, $gid); //查看地块ID是否合法
        if ($ground == '') {
            flock($fp, LOCK_UN);
            fclose($fp);
            return self::returnData(410, array('status' => 3), '地块不合法');
        } else {
            $groundArray = json_decode($ground, true);  //返回地块信息
            if (!isset($groundArray['qid'])) {   //检查地块是否被购买
                $qid = getQid($tvmid);
                if (!$qid) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return self::returnData(470, array('status' => 5), '用户匹配错误');
                }
                if (substr($groundArray['src'],-7) == 'tyc.png') {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return self::returnData(471, array('status' => 7), '非出售区域');
                }
                $check = self::limitCheck($tvmid, $qid); //检查用户地块是否有5块地
                if ($check == 1) {  //表示可以购买操作
                    $userCoin = selectCoin($tvmid);
                    if (isset($userCoin['data']['main'])) {
                        if ($groundArray['price'] == 1 && substr($groundArray['src'],-5) == GroundConfig::GROUND_HIGHPRICE_PNG) {
                            //判断是否是高价地块
                            if ($userCoin['data']['main'] >= GroundConfig::GROUND_HIGH_PRICE) {
                                $coin = UserCoinCore('minus', $tvmid, GroundConfig::GROUND_HIGH_PRICE, '地块购买');
                            } else {
                                flock($fp, LOCK_UN);
                                fclose($fp);
                                return self::returnData(472, array('status' => 40), '金币不足');
                            }
                        } else {
                            if ($userCoin['data']['main'] >= GroundConfig::GROUND_LOW_PRICE) {
                                $coin = UserCoinCore('minus', $tvmid, GroundConfig::GROUND_LOW_PRICE, '地块购买');
                            } else {
                                flock($fp, LOCK_UN);
                                fclose($fp);
                                return self::returnData(473, array('status' => 40), '金币不足');
                            }
                        }
                        if (isset($coin['code']) && $coin['code'] == 200) {
                            $groundArray['qid'] = $qid;
                            $groundArray['status'] = "hold";
                            $groundArray['src'] = GroundConfig::GROUND_SELLED_PNG;//购买后的地块背景默认图
                            $groundArray['nickname'] = $nickname;
                            $groundArray['headimg'] = $headimg;
                            $now = getMillisecond();
                            self::$r->hset($groundKey, $gid, json_encode($groundArray));
                            self::$r->hset(GroundConfig::GROUND_KEY_HEAD . $qid,$service . '_' . $region . '_' . $gid, $now);
                            $tmp_str = $qid . '_' . $service . '_' . $region . '_' . $gid . '_' . $groundArray['price'];
                            self::$r->hset(GroundConfig::GROUND_LOGS_HEAD,$now . '_' . $qid, $tmp_str);

                            //推送日志
                            if ($groundArray['price'] == 1 && substr($groundArray['src'], -5) == GroundConfig::GROUND_HIGHPRICE_PNG) {
                                $log_coin = GroundConfig::GROUND_HIGH_PRICE;
                            } else {
                                $log_coin = GroundConfig::GROUND_LOW_PRICE;
                            }
                            $logString = "1" . "\t" . $service . "\t" . $region . "\t" . $gid . "\t" .
                                $qid . "\t" . "1" . "\t" . getMillisecond() . "\t" . "126" . "\t" . $log_coin . "\t" . "0";
                            self::writeRedislog($logString);  //推送日志
                            flock($fp, LOCK_UN);
                            fclose($fp);
                            return self::returnData(200, array('status' => 0), '购买成功');
                        } else {
                            flock($fp, LOCK_UN);
                            fclose($fp);
                            return self::returnData(474, array('status' => 6), 'coin deal error'); //金币操作失败
                        }
                    }
                } elseif($check == 2) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return self::returnData(491, array('status' => 9), '很抱歉,在全民买楼1中拥有至少一栋房产的玩家才有购买权利');
                } elseif($check == 3) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    $msg = '每个用户限购' . GroundConfig::USER_BUY_LIMIT . '块黄金地块';
                    return self::returnData(492, array('status' => 10), $msg);
                }
            } else {
                flock($fp, LOCK_UN);
                fclose($fp);
                return self::returnData(475, array('status' => 4), '地块已出售');
            }
        }
    }

    /**
     * 数据返回
     *
     * @param string $status
     * @param array $data
     * @param string $msg
     */
    public static function returnData($status, $data, $msg)
    {
        $result = array(
            'code' => $status,
            'data' => $data,
            'msg' => $msg
        );
        return $result;
    }


    /**
     * 写redis日志
     *
     * @param string $string
     */
    public static function writeRedislog($string)
    {
        $logredis = MyRedis::getLogConnect();
        $logredis->rpush('opt_log', $string);
        $logredis->close();
    }

    /**
     * 服务器及区域查询
     *
     * @param string $service
     */
    public static function selectGround($service)
    {
        self::$r = MyRedis::getConnectRead();
        if ($service == '') {
            $return = self::$r->keys(GroundConfig::GROUND_KEY_HEAD . "[0-999]*");
            if ($return != '') {
                $server = array();
                foreach ($return as $keys) {
                    $str = explode('_', $keys);
                    if (isset($str[1]) && (int)$str[1] > 0) {
                        $server[] = $str[1];
                    }
                }
                if (count($server) > 0) {
                    return self::returnData(200, array('service' => $server), 'ok');
                } else {
                    return self::returnData(410, array('status' => 2), 'data null');
                }
            } else {
                return self::returnData(410, array('status' => 3), 'data null');
            }
        } else {
            $return = self::$r->keys(GroundConfig::GROUND_KEY_HEAD . $service . "_*");
            if ($return != '') {
                $region = array();
                foreach ($return as $keys) {
                    $str = explode('_', $keys);
                    if (isset($str[2]) && (int)$str[2] > 0) {
                        $region[] = $str[2];
                    }
                }
                if (count($region) > 0) {
                    return self::returnData(200, array('service' => $service, 'region' => $region), 'ok');
                } else {
                    return self::returnData(410, array('status' => 4), 'data null');
                }
            } else {
                return self::returnData(410, array('status' => 5), 'data null');
            }
        }
    }

    /**
     * 用户购买记录
     *
     * @param string $qid
     * @param string $gidString
     * @param string $type
     */
    public static function buyRecord($qid,$gidString,$type)
    {
        try {
            $mysql = Mysql::getConnect('quanming2');
            $sql = sprintf("insert into grounddeal values(null,%s,%s,'%s','%s')", time(), $type, $gidString, $qid);
            $excute = $mysql->prepare($sql);
            $excute->execute();
            return true;
        } catch(\Exception $e) {
            return false;
        }
    }

    /**
     * 地块预售金币
     *
     * @param string $tvmid
     * @param string $orderid
     * @param integer $coin
     */
    private static function presaleFunc($tvmid, $orderid, $coin)
    {
        $url = GroundConfig::GROUND_PRESALE_GOLD_FUND . '?userTvmid=' . $tvmid . '&orderid=' . $orderid . '&coin=' . $coin;
        $result = file_get_contents($url);
        $fund = json_decode($result,true);
        return $fund;
    }

    /**
     * 获得文件锁
     */
    private static function getLock()
    {
        $filename = APP_NAME . "public/shell" . DIRECTORY_SEPARATOR . GroundConfig::GROUND_LOCKFILE;
        $lockPath = $filename;
        if (!is_dir(dirname($lockPath))) {
            mkdir(dirname($lockPath), 0777);
        }
        if (!file_exists($lockPath)) {
            touch($lockPath, 0777);
        }
        return $lockPath;
    }

    /**
     * 日志记录备份
     *
     * @param string $content
     */
    private static function logRecord($content = '')
    {
        $path = GroundConfig::GROUND_LOGDOC . date('Y-m', time()) . "/";

        if (is_dir($path)) {
            if (substr($path,-1) != '/') {
                $path = $path . '/';
            }
        } else {
            mkdir($path, 0777);
        }

        $file = $path.date('Y-m-d', time());

        $f = fopen($file, "a+");
        $content = $content . "\r\n";
        fwrite($f, $content);
        fclose($f);
    }

    /**
     * 添减公共建筑时更新buffer范围数据
     *
     * @param string $service
     * @param string $region
     * @param string $gid
     * @param string $type
     */
    private static function publicBuilding($service, $region, $gid, $type)
    {
        $groundKey = GroundConfig::GROUND_KEY_HEAD . $service . "_" . $region;
        $bufferKey = GroundConfig::GROUND_BUFFER_REGION . $service . "_" . $region;
        self::$r = MyRedis::getConnect();
        if ($type == 'add') {
            $influence = self::getBufferground($groundKey, $gid);
            if ($influence != '') {
                $status = self::$r->hset($bufferKey, $gid, json_encode($influence, true));
                return $status;
            } else {
                //mLogs("add buffer region failed","addHappiness",$groundKey."-".$gid,'happiness');
                return false;
            }
        } elseif ($type == 'del') {
            $status = self::$r->hdel($bufferKey, $gid);
            return $status;
        } else {
            //mLogs("del buffer region failed","addHappiness",$groundKey."-".$gid,'happiness');
            return false;
        }
    }

    /**
     * 获得buffer影响范围地块
     *
     * @param string $groundKey
     * @param string $gid
     */
    private static function getBufferground($groundKey, $gid)
    {
        $ground = HappinessModel::groundNearby($gid);
        if ($ground != '') {
            $return = array();
            self::$r = MyRedis::getConnect();
            foreach ($ground as $k => $v) {
                $temp = self::$r->hget($groundKey, $v);
                if ($temp != '') {
                    $return[] = $v;
                }
            }
            return $return;
        } else {
            return '';
        }
    }

    /**
     * 获得用户数据
     *
     * @param string $tvmid
     */
    private static function userinfonew($tvmid)
    {
        $return = array();

        try {
            $redis = MyRedis::getConnect();
            $redis_user = $redis->hget(GroundConfig::USER_INFO_KEY, $tvmid);
            $array_user = json_decode($redis_user, true);
            if (isset($array_user['nickname']) && isset($array_user['headimg'])) {
                $return['nickname'] = $array_user['nickname'];
                $return['headimg'] = $array_user['headimg'];
                $redis->close();
                return $return;
            } else {
                $url = GroundConfig::USER_INFO_URL . $tvmid;
                $user = file_get_contents($url);
                $userinfo = json_decode(json_encode($user), TRUE);
                $userinfo = (array)json_decode($userinfo);
                if (!isset($userinfo['data'])) {
                    $return['nickname'] = '';
                    $return['headimg'] = '';
                    return $return;
                }
                $data = (array)$userinfo['data'];
                if (isset($data['nickname']) && isset($data['head_img'])) {
                    $return['nickname'] = $data['nickname'];
                    $return['headimg'] = $data['head_img'];
                    $redis->hset(GroundConfig::USER_INFO_KEY, $tvmid, json_encode($return, true));
                    $redis->close();
                    return $return;
                } else {
                    return false;
                }
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获得工厂工作状态
     *
     * @param string $qid
     * @param string $facid
     */
    private static function getWorkstatus($qid, $facid)
    {
        $workid = ProduceConfig::PRODUCE_PREFIX . $qid . ":" . $facid;
        self::$r = MyRedis::getConnect();
        $return = self::$r->hgetall($workid);

        if (count($return) <= 0) {
            return 'waiting';
        } else {
            return 'working';
        }
    }

    /**
     * 检查用户购买地块的数量限制
     *
     * @param string $tvmid
     * @param string $qid
     */
    private static function limitCheck($tvmid, $qid)
    {
        $redis_building = new \Redis();
        $redis_building->connect(GroundConfig::BUILDING_REDIS_HOST,GroundConfig::BUILDING_REDIS_PORT);
        $redis_building->auth(GroundConfig::BUILDING_REDIS_PWD);

        $building_key = GroundConfig::USER_BUILDING_HEAD . $tvmid;
        $building = $redis_building->zCard($building_key);
        $redis_building->close();
        if ($building <= 0) {
            return 2;
        } else {
            return 1;
        }
        /*
        $redis = MyRedis::getConnect();
        $key = GroundConfig::GROUND_KEY_HEAD . $qid;
        $count = $redis->hlen($key);
        $redis->close();
        if ($count < GroundConfig::USER_BUY_LIMIT) {
            return 1;
        } else {
            return 3;  //大于地块数
        }
        */
    }

    private static function bugCheck($tvmid)
    {
        $arr = array(
            'wxh5996b3e127f5c9071fa09273',
            'wxh5864c82aa5f1a863c75da60e',
            'wxh58674c6058cafe18cc78c477',
            'sjh585b552776350143854346a2',
            'wxh5a1d166ae4982d54a9af22f6',
            'wxh5866f4b98751751b704457d9',
            'wxh5867de2a1ddd6c06e2de12e8',
            'wxh584b93b9a1d9223d37463758',
            'wxh5848bcc2af119c3522ed1fbb',
            'wxh586711f3d85f6817d70e90c4',
            'wxh5868f7078e2c1c4dcd8445b7',
            'wxh5848c20f603f7b352a442096',
            'wxh58ca9514f629d57eedf3501e',
            'wxh5848d5f4af119c3522ed1fce',
            'wxh585777c79eef301ffd577901',
            'wxh5848f271af119c3522ed1fd4',
            'wxh58df5ceb7f3e4d7e7f248007',
            'sjh591bc3e6d98ec65a3bc95bb7',
            'wxh5864c82aa5f1a863c75da60e',
            'wxh586712909726581b8fef0c10',
            'wxh586a5b0c47d0ee63d0fb0b26',
            'wxh58670937669f1b1ac1dc08bf',
            'wxh584d05d3e1c4bb38385c6c6f',
            'wxh586ddd6ad4785558a98324c4',
        );

        if (count($arr) <= 0) {
            return true;
        }
        if (in_array($tvmid, $arr)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 查询用户购买的地块数量
     *
     * @param string $tvmid
     */
    public static function selectUserGround($tvmid)
    {
        $r = MyRedis::getConnectRead();
        $qid = getQid($tvmid);
        $db = GroundConfig::GROUND_KEY_HEAD . $qid;
        $data = $r->hGetAll($db);
        if ($data) {
            $num = count($data);
        } else {
            $num = 0;
        }
        $r -> close();
        return returnArr(200, array('count'=>$num), 'success');
    }

    /**
     * 用户地块相关信息查询
     *
     * @param string $qid
     */
    public static function userGround($qid)
    {
        $redis = MyRedis::getConnectRead();
        $key = GroundConfig::GROUND_KEY_HEAD . $qid;

        $user = $redis->hgetall($key);
        $return = array();

        if (count($user) <= 0) {
            return returnArr(400, '', 'success');
        } else {
            foreach ($user as $key => $value) {
                $str = explode("_", $key);
                if (count($str) == 5) {
                    $temp = array();
                    $happykey = GroundConfig::GROUND_HAPPY_KEY . $str[0] . "_" . $str[1];
                    $groundkey = GroundConfig::GROUND_KEY_HEAD . $str[0] . "_" . $str[1];
                    $ground = $redis->hget($groundkey, $str[2] . "_" . $str[3] . "_" . $str[4]);
                    if ($ground != '') {
                        $groundArr = json_decode($ground, true);
                        if (isset($groundArr['x']) && isset($groundArr['y'])) {
                            $temp['service'] = $str[0];
                            $temp['serverId'] = $str[0];
                            $temp['regionId'] = $str[1];
                            $temp['pId'] = $str[2] . "_" . $str[3] . "_" . $str[4];
                            $temp['coordX'] = $groundArr['x'];
                            $temp['coordY'] = $groundArr['y'];
                            if ($groundArr['price'] == '1') {
                                $temp['groundlevel'] = '1';
                            } else {
                                $temp['groundlevel'] = '2';
                            }
                            $happy = $redis->hget($happykey, $str[2] . "_" . $str[3] . "_" . $str[4]);
                            if ($happy != '') {
                                $temp['isfactory'] = 1;
                                $temp['happiness'] = $happy;
                            } else {
                                $temp['isfactory'] = 2;
                                $temp['happiness'] = '';
                            }
                            $return[] = $temp;
                        } else {
                            continue;
                        }
                    } else {
                        continue;
                    }
                } else {
                    continue;
                }
            }
        }
        $redis->close();
        if (count($return) == 0) {
            return returnArr(400, '', 'success');
        } else {
            return returnArr(200, $return, 'success');
        }
    }

    /**
     * 地块通知信息
     */
    public static function groundNotice()
    {
        $redis = MyRedis::getConnectRead();
        $record = $redis->lrange(GroundConfig::GROUND_NOTICE_HISTORY, 0, 5);
        $history = array();
        $return = array();
        $i = 0;
        foreach ($record as $r) {
            $temp = json_decode($r, true);
            $temp['id'] = $i;
            $temp['date'] = date('Y-m-d', ($temp['time'] / 1000));
            $history[] = $temp;
            $i += 1;
        }

        $notice = $redis->hGetAll(GroundConfig::GROUND_NOTICE);
        if (isset($notice['state']) && $notice['state'] == '1') {
            $return['code'] = 200;
            $return['title'] = $notice['title'];
            $return['content'] = $notice['content'];
            $return['version'] = isset($result['version']) ? $result['version'] : '';
        }
        $return['history'] = $history;
        $redis->close();
        return returnArr(200, $return, 'success');
    }
}