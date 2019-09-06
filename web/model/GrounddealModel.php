<?php
namespace web\model;

use database\MySql;
use database\MyRedis;
use config\GroundConfig;
use config\FactoryConfig;

class GrounddealModel
{
    protected static $redis;
    protected static $mysql;
    protected static $logredis;

    /**
     * 初始化部分连接句柄
     */
    public function init()
    {
        self::$redis = MyRedis::getConnect();
        self::$mysql = MySql::getConnect('quanming2');
        self::$logredis = MyRedis::getLogConnect();

        if (!self::$redis->ping() || !self::$logredis->ping() || !self::$mysql) {
            return GroundModel::returnData(502, '', '服务器连接失败');
        }
    }

    /**
     * 消毁部分连接句柄
     */
    public function destory()
    {
        self::$redis->close();
        self::$logredis->close();
    }

    /**
     * 用户销售块地挂单
     *
     * @param string  $tvmid
     * @param string  $service
     * @param string  $region
     * @param string  $groundid
     * @param integer $coin
     * @param integer $method 1挂单销售 2拍卖销售
     */
    public static function createOrder($tvmid, $service, $region, $groundid, $coin, $method = 1)
    {
        if (!self::orderBlack($tvmid)) {
            return GroundModel::returnData(504, '', '亲～不能挂单了');
        }
        self::init();
        $keylock = $tvmid . '_' . $service . '_' . $region . '_' . $groundid;
        $getLock = self::$redis->set($keylock,1, ["NX","EX" => 5]);

        if (!$getLock) {
            self::destory();
            return GroundModel::returnData(503, '', '交易处理繁忙');
        }

        self::baseRequest("createorder",$tvmid . "_" . $service . "_" . $region . "_" . $groundid . "_" . $coin);

        $qid = getQid($tvmid);
        $pricearr = self::priceChack($service, $region, $groundid, $coin, $qid);
        if (is_array($pricearr)) {
            self::destory();
            return $pricearr;
        }
        $select = sprintf("select count(*) as acount from groundmarket 
                  where tvmid = '%s' and service = '%s' and region = '%s' and groundid = '%s'
                  and status = 1", $tvmid, $service, $region, $groundid);
        $res = self::$mysql->query($select,\PDO::FETCH_ASSOC);
        $result = $res->fetchAll();
        if ($result[0]['acount'] != 0) {
            self::destory();
            return GroundModel::returnData(492, array('status' => 2),'重复操作');
        }

        $now = time();
        $r = md5($tvmid.$now);
        //生成订单号
        $strl = substr($r,0,16);
        $strr = substr($r,16,16);
        $orderid = $strr . $strl;

        $servercoin = self::serverCoin($tvmid, $service, $region, $groundid, $orderid);
        if (!isset($servercoin['success'])) {
            self::destory();
            return GroundModel::returnData(497, array('status' => 7),'挂单服务费失败');
        }
$coin = 10;
        $insert = sprintf("insert into groundmarket(tvmid,qid,orderid,createtime,service,region,groundid,coin,groundtype,method)values(
          '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$tvmid,$qid,$orderid,$now,$service,$region,$groundid,$coin,$pricearr,$method);
        $exec = self::$mysql->prepare($insert);
        $state = $exec->execute();
        if ($state === true) {
            self::$redis->hset(GroundConfig::GROUND_SALE_LIST,$service . "_" . $region . "_" . $groundid,$now);
            $logstring = "3" . "\t" . $service . "\t" . $region . "\t" . $groundid . "\t" .
                $qid . "\t" . "1" . "\t" . getMillisecond() . "\t" . $orderid . "\t" . "126" . "\t" . $coin;
            GroundModel::writeRedislog($logstring);  //推送日志
            self::destory();
            $massge = "您的地块销售挂单成功";
            pushMsgDSHB($tvmid, $massge);
            return GroundModel::returnData(200, array('status' => 1),'挂单成功');
        } else {
            self::destory();
            return GroundModel::returnData(493, array('status' => 3),'挂单失败');
        }
    }

    /**
     * 用户销售块地撤单
     *
     * @param string $tvmid
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param string $orderid
     */
    public static function cancelOrder($tvmid, $service, $region, $groundid, $orderid)
    {
        self::init();
        $keylock = GroundConfig::GROUND_SALE_LOCK . '_' . $service . '_' . $region . '_' . $groundid;
        $getLock = self::$redis->set($keylock, 1, ["NX", "EX" => 5]);

        if (!$getLock) {
            self::destory();
            return GroundModel::returnData(503, '', '交易处理繁忙');
        }
        $qid = getQid($tvmid);
        $sql = sprintf("select id,coin from groundmarket 
               where tvmid = '%s' and service = '%s' and region = '%s' and groundid = '%s'
               and status = 1 and orderid = '%s'", $tvmid, $service, $region, $groundid, $orderid);
        $res = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $result = $res->fetchAll();
        if (count($result) == 1) {
            $sql = sprintf("update groundmarket set status = 2 where id = '%s'", $result[0]['id']);
            self::$mysql->beginTransaction();
            $update = self::$mysql->prepare($sql);
            $update->execute();
            self::$mysql->commit();
            $logstring = "3" . "\t" . $service . "\t" . $region . "\t" . $groundid . "\t" .
                $qid . "\t" . "3" . "\t" . getMillisecond() . "\t" . $orderid . "\t" . "126" . "\t" . $result[0]['coin'];
            GroundModel::writeRedislog($logstring);  //推送日志
            self::$redis->hdel(GroundConfig::GROUND_SALE_LIST, $service . "_" . $region . "_" . $groundid);
            self::destory();
            $massge = "您的地块销售撤单成功";
            pushMsgDSHB($tvmid, $massge);
            return GroundModel::returnData(200,array('status' => 1),'取消成功');
        } else {
            self::destory();
            return GroundModel::returnData(492,array('status' => 2),'数据异常');
        }
    }

    /**
     * 地块销售处理
     *
     * @param string $buy
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param string $orderid
     * @param string $nickname
     * @param string $headimg
     */
    public static function dealOrder($buy, $service, $region, $groundid, $orderid, $nickname, $headimg)
    {
        self::init();
        $keylock = GroundConfig::GROUND_SALE_LOCK . '_' . $service . '_' . $region . '_' . $groundid;
        $getLock = self::$redis->set($keylock, 1, ["NX", "EX" => 5]);

        if (!$getLock) {
            self::destory();
            return GroundModel::returnData(503, '', '交易处理繁忙');
        }
        $sql = sprintf("select * from groundmarket 
               where orderid = '%s' and service = '%s' and region = '%s' and groundid = '%s'
               and status = 1", $orderid, $service, $region, $groundid);
        $select = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $res = $select->fetchAll();

        if (count($res) == 1 && isset($res[0]['tvmid'])) {
            $buyqid = getQid($buy);
            $saleqid = getQid($res[0]['tvmid']);
            if ($buyqid == '' || $saleqid == '') {
                self::destory();
                return GroundModel::returnData(493, array('status' => 3), '购买方用户数据异常');
            }

            $coinstate = self::coinChange($res[0]['tvmid'], $buy, $res[0]['coin'], $orderid, $service, $region, $groundid); //金币增减
            if (is_array($coinstate)) {
                return $coinstate;
            }
            $userstate = self::userChange($service, $region, $groundid, $saleqid, $buyqid, $nickname, $headimg); //地块交接
            if (is_array($userstate)) {
                return $userstate;
            }

            $factorydata = FactoryModel::userPlace($saleqid,$service . "_" . $region . "_" . $groundid, 'is_sel');
            if ($factorydata) {
                $factory = FactoryModel::changeFactoryUser($saleqid, $buyqid, $service, $region, $groundid);
                self::baseRequest('changeFactoryUser', $factory);
                if ($factory == false) {
                    return GroundModel::returnData(499, array('status' => 9), '地产交接异常，请联系客服！');
                }
            }

            self::$mysql->beginTransaction();
            $sql = sprintf("update groundmarket set status = 3 where id = '%s'", $res[0]['id']);
            $update = self::$mysql->prepare($sql);
            $sql1 = sprintf("insert into groundsale(buyertvmid,buyerqid,marketid,createtime)values('%s','%s','%s','%s')",
                $buy, $buyqid, $res[0]['id'], time());
            $insert = self::$mysql->prepare($sql1);

            $update->execute();
            $insert->execute();
            self::$mysql->commit();
            $logstring = "4" . "\t" . $orderid . "\t" . $service . "\t" . $region . "\t" .
                $groundid . "\t" . $saleqid . "\t" . $buyqid . "\t" . getMillisecond() . "\t" . "126" . "\t" . $res[0]['coin']
                . "\t" . "126" . "\t" . intval( $res[0]['coin'] * GroundConfig::GROUND_SALE_SERVERCOIN);
            GroundModel::writeRedislog($logstring);  //推送日志 挂单成交

            $logstring = "2" . "\t" . $service . "\t" . $region . "\t" .
                $groundid . "\t" . $saleqid . "\t" . "2" . "\t" . getMillisecond() . "\t" . "126" . "\t" . $res[0]['coin'] . "\t" . "10000";
            GroundModel::writeRedislog($logstring);  //推送日志 失去地块

            $logstring = "1" . "\t" . $service . "\t" . $region . "\t" .
                $groundid . "\t" . $buyqid . "\t" . "2" . "\t" . getMillisecond() . "\t" . "126" . "\t" . $res[0]['coin'] . "\t" . "10000";
            GroundModel::writeRedislog($logstring);  //推送日志 购得地块
            $massge = "您成功购得一块土地";
            pushMsgDSHB($buy, $massge);
            $massge = "您成功售出一块土地";
            pushMsgDSHB($res[0]['tvmid'], $massge);
            self::$redis->hdel(GroundConfig::GROUND_SALE_LIST, $service . "_" . $region . "_" . $groundid);
            self::destory();
            return GroundModel::returnData(200, array('status' => 1), '购买成功');
        } else {
            self::destory();
            return GroundModel::returnData(492, array('status' => 2), '数据异常');
        }
    }

    /**
     * 查询地块销售挂单状态，true挂单 false未挂单
     *
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param string $tvmid
     */
    public static function whetherSale($service, $region, $groundid, $tvmid = null)
    {
        self::init();
        if ($tvmid) {
            if(self::$redis->hget(GroundConfig::GROUND_SALE_LIST, $service . "_" . $region . "_" . $groundid) != ''){
                $sql = sprintf("select coin,orderid,method from groundmarket where
                       tvmid = '%s' and service = '%s' and region = '%s' and groundid = '%s' and status = 1",
                       $tvmid, $service, $region, $groundid);
                $select = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
                $result = $select->fetchAll();
                if (count($result) == 1) {
                    self::destory();
                    return returnArr(200, array('coin' => $result[0]['coin'],'orderId' => $result[0]['orderid'],'method' => $result[0]['method']), '');
                } else {
                    self::destory();
                    return returnArr(400, array(), '');
                }
            } else {
                self::destory();
                return returnArr(400, array(), '');
            }
        } else {
            if (self::$redis->hget(GroundConfig::GROUND_SALE_LIST, $service . "_" . $region . "_" . $groundid) != '') {
                self::destory();
                return returnArr(200, '', '');
            } else {
                self::destory();
                return returnArr(400, '', '');
            }
        }
    }

    /**
     * 同一用户同一地块下单次数次统计
     *
     * @param string $tvmid
     * @param string $service
     * @param string $region
     * @param string $groundid
     */
    public static function dealCount($tvmid, $service, $region, $groundid)
    {
        self::init();
        $sql = sprintf("select count(id) as count from groundmarket 
               where tvmid = '%s' and service = '%s' and region = '%s' and groundid = '%s'",
               $tvmid, $service, $region, $groundid);
        $select = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $result = $select->fetchAll();
        $return = array(
            'count' => $result[0]['count']
        );
        self::destory();
        return GroundModel::returnData(200, $return, '');
    }

    /**
     * 交易挂单列表查询
     *
     * @param string $tvmid
     * @param string $service
     * @param string $region
     * @param string $page
     * @param string $limit
     * @param string $type
     * @param string $groundtype
     * @param string $method
     */
    public static function listOrder($tvmid, $service, $region, $page = 0, $limit = 7, $type = 1, $groundtype = 1, $method = 1)
    {
        self::init();
        if ($page != 1) {
            $page = ($page - 1) * $limit;
        } else {
            $page = 0;
        }
        switch ($type) {
            case 1:
                $sql = sprintf("select * from groundmarket 
                       where status = 1 and groundtype = '%s' and service = '%s' 
                       and region = '%s' and method = '%s' order by createtime desc limit " . $page . "," . $limit,
                       $groundtype, $service, $region, $method);
                break;
            case 2:
                $sql = sprintf("select * from groundmarket 
                       where status = 1 and groundtype = '%s' and service = '%s' 
                       and region = '%s' and method = '%s' order by coin limit " . $page . "," . $limit,
                       $groundtype, $service, $region, $method);
                break;
            case 3:
                $sql = sprintf("select * from groundmarket 
                       where status = 1 and groundtype = '%s' and service = '%s' 
                       and region = '%s' and method = '%s' order by coin desc limit " . $page . "," . $limit,
                       $groundtype, $service, $region, $method);
                break;
            default:
                $sql = sprintf("select * from groundmarket 
                       where status = 1 and groundtype = '%s' and method = '%s' limit " . $page . "," . $limit,
                       $groundtype, $method);
                break;
        }
        $res = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $return = array();
        foreach ($res->fetchAll() as $row) {
            $grounddata = self::groundInfo($row,$tvmid,$method);
            if (!$grounddata) {
                continue;
            }
            $return[] = $grounddata;
        }
        return GroundModel::returnData(200, $return, '');
    }

    /**
     * 用户个人交易中心查询
     *
     * @param string $tvmid
     * @param string $title
     * @param string $groundtype
     * @param integer $page
     * @param integer $limit
     * @param integer $method
     */
    public static function userRecord($tvmid, $title, $groundtype, $page, $limit, $method = 1)
    {
        self::init();
        if ($page > 1) {
            $page = ($page - 1) * $limit;
        } else {
            $page = 0;
        }
        switch ($title) {
            case 1:
                return self::userOrder($tvmid, $page, $limit, $groundtype, $method);
                break;
            case 2:
                return self::userSale($tvmid, $groundtype, $page, $limit, $method);
                break;
            case 3:
                return self::userBuy($tvmid, $groundtype, $page, $limit, $method);
                break;
            default:
                return GroundModel::returnData(200, array(), '');
                break;
        }
    }

    /**
     * 用户记录显示删除
     *
     * @param string $tvmid
     * @param string $orderid
     * @param string $title
     */
    public static function delRecord($tvmid, $orderid, $title)
    {
        self::init();
        $sql = sprintf("select id from groundmarket where orderid = '%s'", $orderid);
        $select = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $result = $select->fetchAll();
        if (count($result) == 1 && isset($result[0]['id'])) {
            if ($title == '1') {
                $sql1 = sprintf("update groundmarket set visaul = 2 where id = '%s'", $result[0]['id']);
                $sql2 = sprintf("update groundsale set visaul = 2 where marketid = '%s'", $result[0]['id']);
                $update1 = self::$mysql->prepare($sql1);
                $update2 = self::$mysql->prepare($sql2);
                $update1->execute();
                $update2->execute();
                self::destory();
                return GroundModel::returnData(200, '', '删除成功');
            } elseif ($title == '2') {
                $sql = sprintf("update groundmarket set visaul = 2 where id = '%s'", $result[0]['id']);
            } elseif($title == '3') {
                $sql = sprintf("update groundsale set visaul = 2 where marketid = '%s'", $result[0]['id']);
            } else {
                return GroundModel::returnData(492, array('status' => 2), '数据类型异常');
            }
            $update = self::$mysql->prepare($sql);
            $update->execute();
            self::destory();
            return GroundModel::returnData(200,'', '删除成功');
        } else {
            self::destory();
            return GroundModel::returnData(491, array('status' => 1), '数据异常');
        }
    }

    /**
     * 查询土地订单号及价格
     *
     * @param string $tvmid
     * @param string $service
     * @param string $region
     * @param string $groundid
     */
    public static function groundPrice($tvmid, $service, $region, $groundid)
    {
        $groundprice = self::whetherSale($service, $region, $groundid, $tvmid);
        if (isset($groundprice['code']) && $groundprice['code'] == 200) {
            return GroundModel::returnData(200,
                array('coin' => $groundprice['data']['coin'], 'orderid' => $groundprice['data']['orderId']), '');
        } else {
            return GroundModel::returnData(400, '', '数据异常，请前向交易中心购买！');
        }
    }

    /**
     * 拍卖出价
     *
     * @param string $tvmid
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param string $orderid
     * @param integer $coin
     */
    public static function auctionOffer($tvmid, $service, $region, $groundid, $orderid, $coin)
    {
        self::init();
        $keylock = GroundConfig::GROUND_SALE_LOCK . '_' . $service . '_' . $region . '_' . $groundid;
        $getLock = self::$redis->set($keylock, 1, ["NX", "EX" => 3]);
        if (!$getLock) {
            self::destory();
            return GroundModel::returnData(491, '', '交易处理繁忙');
        }
        $createtime = time();
        $qid = getQid($tvmid);
        $sql = sprintf("select * from groundmarket where orderid = '%s' and method = 2 and status = 1", $orderid);
        $result = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $order = $result->fetchAll();
        if (count($order) != 1) {
            self::destory();
            return GroundModel::returnData(492, '', '拍卖订单错误');
        } else {
            //不能自己挂单自己拍
            if ($order[0]['tvmid'] == $tvmid) {
                self::destory();
                return GroundModel::returnData(493, '', '竞拍用户冲突');
            }

            //判断是否为最新最高出价
            $freshoffer = self::freshOffer($tvmid, $coin, $order[0]['id'], $order[0]['coin']);
            if ($freshoffer !== true) {
                return $freshoffer;
            }
            //第一次参与本轮拍卖需交基础地价的10%作为保证金
            $promisecheck = self::promiseCoin($tvmid, $qid, $order[0]['groundtype'], $order[0]['id'], $service, $region, $groundid, $createtime, $order[0]['orderid']);
            if (is_array($promisecheck)) {
                return $promisecheck;
            } else {
                $sql = sprintf("insert into groundoffer(offertvmid,offerqid,coin,createtime,marketid,offerorderid) 
                       values ('%s','%s','%s','%s','%s','%s') ",
                       $tvmid, $qid, $coin, $createtime, $order[0]['id'], $promisecheck);
                $insert = self::$mysql->prepare($sql);
                $status = $insert->execute();
                if ($status == true) {
                    $logstring = "32" . "\t" . $order[0]['orderid'] . "\t" . $promisecheck . "\t" . getMillisecond() . "\t" . $qid . "\t"
                        . "126" . "\t" . $coin;
                    GroundModel::writeRedislog($logstring);  //推送日志
                    $massge = "感谢您参与竞拍地块";
                    pushMsgDSHB($tvmid, $massge);
                    self::destory();
                    return GroundModel::returnData(200, '', '拍卖出价成功');
                } else {
                    //如出现重复收取保证金，需联系客服处理。
                    self::destory();
                    return GroundModel::returnData(494, '', '拍卖出价失败');
                }
            }
        }
    }

    /**
     * 判断是否为最新最高出价
     *
     * @param string $tvmid
     * @param integer $coin
     * @param string $marketid
     * @param integer $marketcoin
     */
    private static function freshOffer($tvmid, $coin, $marketid, $marketcoin)
    {
        $sql = sprintf("select * from groundoffer where marketid = '%s' order by coin desc limit 1", $marketid);
        $result = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $fresh = $result->fetchAll();
        if (count($fresh) == 0) {
            if ($coin >= $marketcoin) {
                return true;
            } else {
                return GroundModel::returnData(498, '', '拍卖出价不得低于地块底价');
            }
        } else {
            if ($fresh[0]['offertvmid'] == $tvmid) {
                return GroundModel::returnData(496, '', '您已获得竞拍最高出价');
            }
            if ($fresh[0]['coin'] >= $coin) {
                return GroundModel::returnData(497, array('currentcoin' => $fresh[0]['coin']), '最高出价已更新,请提高加价金额');
            }
            return true;
        }
    }

    /**
     * 第一次参与本轮拍卖需交基础地价的10%作为保证金
     *
     * @param string $tvmid
     * @param string $qid
     * @param string $groundtype
     * @param integer $marketid
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param string $createtime
     * @param string $marketorder
     */
    private static function promiseCoin($tvmid, $qid, $groundtype, $marketid, $service, $region, $groundid, $createtime, $marketorder)
    {
        $sql = sprintf("select offerorderid from groundoffer 
               where marketid = '%s' and offertvmid = '%s'", $marketid, $tvmid);
        $promise = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $promiserow = $promise->fetchAll();
        if (count($promiserow) == 0) {
            $r = md5($tvmid . $service . $region . $groundid . $createtime);
            //生成订单号
            $strl = substr($r, 0, 16);
            $strr = substr($r, 16, 16);
            $offerorderid = $strr . $strl;
            if ($groundtype == 1) {
                $groundcoin = GroundConfig::GROUND_HIGH_PRICE;
            } else {
                $groundcoin = GroundConfig::GROUND_LOW_PRICE;
            }
            $promisecoin = 1;//intval($groundcoin * GroundConfig::GROUND_SALE_SERVERCOIN);
            $minurl = GroundConfig::GROUND_SALE_GOLD_MIN . '?userTvmid=' . $tvmid . '&orderid=' . $offerorderid . '&coin=' . $promisecoin;
            $result = file_get_contents($minurl);
            $fund = json_decode($result, true);
            if (isset($fund['success'])) {
                $logstring = "33" . "\t" . $marketorder . "\t" . $offerorderid . "\t" . getMillisecond() . "\t" . $qid . "\t"
                    . "126" . "\t" . $promisecoin . "\t" . "1";
                GroundModel::writeRedislog($logstring);  //推送日志
                $massge = "感谢您参与竞拍地块,扣竞拍保证金:" . $promisecoin . "金币";
                pushMsgDSHB($tvmid, $massge);
                return $offerorderid;
            } else {
                if (isset($fund['code']) && $fund['code'] == 122) {
                    return GroundModel::returnData(495, '', '扣竞拍保证金,账户余额不足!');
                } else {
                    return GroundModel::returnData(496, '', '扣竞拍保证金失败');
                }
            }
        } else {
            return $promiserow[0]['offerorderid'];
        }
    }

    /**
     * 拍卖撤单
     *
     * @param string $tvmid
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param string $orderid
     */
    public static function cancelAuction($tvmid, $service, $region, $groundid, $orderid)
    {
        self::init();
        $keylock = GroundConfig::GROUND_SALE_LOCK . '_' . $service . '_' . $region . '_' . $groundid;
        $getLock = self::$redis->set($keylock, 1, ["NX", "EX" => 5]);

        if (!$getLock) {
            self::destory();
            return GroundModel::returnData(503, '', '交易处理繁忙');
        }
        $qid = getQid($tvmid);
        $sql = sprintf("select id,coin from groundmarket 
               where tvmid = '%s' and service = '%s' and region = '%s' and groundid = '%s' 
               and status = 1 and orderid = '%s'", $tvmid, $service, $region, $groundid, $orderid);
        $res = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $result = $res->fetchAll();
        if (count($result) == 1) {
            //拍卖撤单时，如已有人出价，则不允许撤单
            $sql = sprintf("select count(*) as offercount from groundoffer where marketid = '%s'", $result[0]['id']);
            $offer = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
            $offercount = $offer->fetchAll();
            if ($offercount[0]['offercount'] > 0) {
                self::destory();
                return GroundModel::returnData(493, '', '已有人出价，不允许撤单!');
            }

            $sql = sprintf("update groundmarket set status = 2 where id = '%s'", $result[0]['id']);
            self::$mysql->beginTransaction();
            $update = self::$mysql->prepare($sql);
            $update->execute();
            self::$mysql->commit();
            $logstring = "31" . "\t" . $service . "\t" . $region . "\t" . $groundid . "\t" .
                $qid . "\t" . "3" . "\t" . getMillisecond() . "\t" . $orderid . "\t" . "126" . "\t" . $result[0]['coin'] . "\t" . "0";
            GroundModel::writeRedislog($logstring);  //推送日志
            self::$redis->hdel(GroundConfig::GROUND_SALE_LIST, $service . "_" . $region . "_" . $groundid);
            self::destory();
            $massge = "您的地块拍卖撤单成功";
            pushMsgDSHB($tvmid, $massge);
            return GroundModel::returnData(200, array('status' => 1), '取消成功');
        } else {
            self::destory();
            return GroundModel::returnData(492, array('status' => 2), '数据异常');
        }
    }

    /**
     * 拍卖结算
     *
     * @param string $tvmid
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param string $orderid
     */
    public static function dealAuction($tvmid, $service, $region, $groundid, $orderid)
    {
        self::init();
        $keylock = GroundConfig::GROUND_SALE_LOCK . '_' . $service . '_' . $region . '_' . $groundid;
        $getLock = self::$redis->set($keylock, 1, ["NX", "EX" => 5]);

        if (!$getLock) {
            self::destory();
            return GroundModel::returnData(503, '', '交易处理繁忙');
        }

        $sql = sprintf("select groundtype,id from groundmarket where orderid = '%s' and status = 1", $orderid);
        $result = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $groundtype = $result->fetchAll();
        if (count($groundtype) == 0) {
            self::destory();
            return GroundModel::returnData(491, '', '拍卖已结束');
        }

        $sql = sprintf("select * from groundoffer 
               where marketid = '%s'
               order by coin desc limit 1", $groundtype[0]['id']);
        $result = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $fresh = $result->fetchAll();
        if (count($fresh) == 0) {
            self::destory();
            return GroundModel::returnData(493, '', '当前无人出价竞拍，可选择撤单或继续等待出价！');
        } else {
            $amount = self::amountAuction($tvmid, $service, $region, $groundid, $orderid, $fresh[0]['offertvmid'], $fresh[0]['offerqid'], $fresh[0]['coin'], $fresh[0]['offerorderid']);
            if (is_array($amount) && isset($amount['success'])) {
                self::refundAuction($fresh[0]['offertvmid'], $orderid, $groundtype[0]['groundtype'],2);
                self::destory();
                return GroundModel::returnData(200, array('tvmid' => $amount['tvmid'], 'nickname' => $amount['nickname'], 'headimg' => $amount['headimg'], 'status' => 1), '拍卖结算成功');
            } else {
                self::destory();
                return $amount;
            }
        }
    }

    /**
     * 拍卖出价列表
     *
     * @param string $tvmid
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param string $orderid
     * @param integer $page
     * @param integer $limit
     */
    public static function auctionList($tvmid, $service, $region, $groundid, $orderid, $page, $limit)
    {
        self::init();
        if ($page != 1) {
            $page = ($page - 1) * $limit;
        } else {
            $page = 0;
        }
        $sql = sprintf("select max(o.coin) as offercoin,o.offerqid,o.offertvmid,m.coin as initcoin,o.marketid from groundmarket as m 
               left join groundoffer as o on m.id = o.marketid 
               where m.orderid = '%s' and m.service = '%s' and m.region = '%s' and m.groundid = '%s' and status = 1 
               group by o.offertvmid  
               order by offercoin desc limit ".$page.",".$limit,
               $orderid, $service, $region, $groundid);

        $result = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $auctionlist = array();
        $return = array();
        $temp = array();
        foreach ($result->fetchALL() as $row) {
            //$user = getUserInfo($row['offertvmid']);
            $user = self::$redis->hget('user_info', $row['offertvmid']);
            $userarr = json_decode($user,true);
            $temp['nickname'] = isset($userarr['nickname']) ? $userarr['nickname'] : '';
            $temp['headimg'] = isset($userarr['headimg']) ? $userarr['headimg'] : '';
            $temp['coin'] = $row['offercoin'];
            $return['initcoin'] = $row['initcoin'];
            if ($temp['coin'] != '') {
                $auctionlist[] = $temp;
            }
        }
        $return['list'] = $auctionlist;
        self::destory();
        if (count($return['list']) > 0) {
            return GroundModel::returnData(200, $return, '');
        } else {
            return GroundModel::returnData(300, $return, '拍卖已结束');
        }
    }

    /**
     * 用户出价记录
     *
     * @param string $tvmid
     * @param string $groundtype
     * @param integer $page
     * @param integer $limit
     */
    public static function userOffer($tvmid, $groundtype, $page, $limit)
    {
        self::init();
        if ($page != 1) {
            $page = ($page - 1) * $limit;
        } else {
            $page = 0;
        }
        $sql = sprintf("select o.marketid,o.offertvmid,max(o.coin) as offercoin,m.orderid,m.service,m.region,m.groundid,
               max(o.createtime) as createtime,m.coin as initcoin 
               from groundmarket as m 
               left join groundoffer as o on m.id = o.marketid 
               where o.offertvmid = '%s' and m.groundtype = '%s' and m.status = 1 and m.method = 2
               group by o.marketid limit ".$page.",".$limit, $tvmid, $groundtype);

        $result = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $return = array();
        $temp = array();
        foreach ($result->fetchALL() as $row) {
            $groundinfo = self::$redis->hget(GroundConfig::GROUND_KEY_HEAD . $row['service'] . "_" . $row['region'], $row['groundid']);
            $groundarr = json_decode($groundinfo,true);
            if (isset($groundarr['factype']) && isset($groundarr['fid'])) {
                $factory = FactoryConfig::FactoryList($groundarr['factype']);
                $factoryresult = $factory[$groundarr['fid']];
                $temp['groundname'] = $groundarr['nickname'] . "的" . $factoryresult['name'];
            } else {
                $temp['groundname'] = $groundarr['nickname'] . "的地块";
            }
            $temp['src'] = $groundarr['src'];
            $temp['nickname'] = $groundarr['nickname'];
            $temp['headimg'] = $groundarr['headimg'];
            $temp['x'] = $groundarr['x'];
            $temp['y'] = $groundarr['y'];
            $temp['servicename'] = GroundConfig::regionName($row['service'], $row['region']);
            $temp['orderid'] = $row['orderid'];
            $temp['service'] = $row['service'];
            $temp['region'] = $row['region'];
            $temp['groundid'] = $row['groundid'];
            $sql = sprintf("select max(coin) as coin from groundoffer where marketid = '%s'", $row['marketid']);

            $except = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
            $exceptcoin = $except->fetchAll();
            $temp['topcoin'] = $exceptcoin[0]['coin'];
            $temp['mycoin'] = $row['offercoin'];
            $return[] = $temp;
        }
        self::destory();
        return GroundModel::returnData(200, $return, '');
    }

    /**
     * 拍卖结算
     *
     * @param string $tvmid
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param string $orderid
     * @param string $offertvmid
     * @param string $offerqid
     * @param integer $offercoin
     * @param string $offerorder
     */
    private static function amountAuction($tvmid, $service, $region, $groundid, $orderid, $offertvmid, $offerqid, $offercoin, $offerorder)
    {
        $sql = sprintf("select * from groundmarket where orderid = '%s'", $orderid);
        $result = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $marketorder = $result->fetchAll();
        if (count($marketorder) == 1 && $marketorder[0]['status'] == 1 && $marketorder[0]['method'] == 2) {
            if ($marketorder[0]['tvmid'] != $tvmid) {
                return GroundModel::returnData(499, array('status' => 9), '非拍卖挂单用户，请联系客服！');
            }
            $coinstate = self::coinChange($marketorder[0]['tvmid'], $offertvmid, $offercoin, $orderid, $service, $region, $groundid); //金币增减
            if (is_array($coinstate)) {
                //如中标人余额不足以支付，则不退还保证金
                if (isset($coinstate['code']) && $coinstate['code'] == 494) {
                    $refund = self::refundAuction($offertvmid, $orderid, $marketorder[0]['groundtype'],1);
                    $breach = self::breachAuction($service, $region, $groundid, $marketorder[0]['tvmid'], $offertvmid, $orderid, $offerorder,
                        $marketorder[0]['groundtype'], $marketorder[0]['qid'], $offerqid, $marketorder[0]['coin']);
                    return $breach;
                }
                return $coinstate;
            }
            $userinfo = self::$redis->hget("user_info", $offertvmid);
            $userarr = json_decode($userinfo, true);
            $nickname = isset($userarr['nickname']) ? $userarr['nickname'] : '';
            $headimg = isset($userarr['headimg']) ? $userarr['headimg'] : '';
            $userstate = self::userChange($service, $region, $groundid, $marketorder[0]['qid'], $offerqid, $nickname, $headimg); //地块交接
            if (is_array($userstate)) {
                return $userstate;
            }
            $factorydata = FactoryModel::userPlace($marketorder[0]['qid'], $service . "_" . $region . "_" . $groundid, 'is_sel');
            if ($factorydata) {
                $factory = FactoryModel::changeFactoryUser($marketorder[0]['qid'], $offerqid, $service, $region, $groundid);
                self::baseRequest('changeFactoryUser', $factory);
                if ($factory == false) {
                    return GroundModel::returnData(499, array('status' => 9), '地产交接异常，请联系客服！');
                }
            }

            self::$mysql->beginTransaction();
            $sql = sprintf("update groundmarket set status = 3 where id = '%s'", $marketorder[0]['id']);
            $update = self::$mysql->prepare($sql);
            $sql1 = sprintf("insert into groundsale(buyertvmid,buyerqid,marketid,createtime)values('%s','%s','%s','%s')",
                 $offertvmid, $offerqid, $marketorder[0]['id'], time());
            $insert = self::$mysql->prepare($sql1);

            $update->execute();
            $insert->execute();
            self::$mysql->commit();
            $logstring = "34" . "\t" . $orderid . "\t" . $service . "\t" . $region . "\t" .
                $groundid . "\t" . $offerqid . "\t" . $marketorder[0]['qid'] . "\t" . getMillisecond() . "\t" . "126" . "\t" . $offercoin
                . "\t" . "126" . "\t" . intval( $offercoin * GroundConfig::GROUND_SALE_SERVERCOIN) . "\t" . "2";
            GroundModel::writeRedislog($logstring);  //推送日志 拍卖成交

            $logstring = "2" . "\t" . $service . "\t" . $region . "\t" .
                $groundid . "\t" . $marketorder[0]['qid'] . "\t" . "2" . "\t" . getMillisecond() . "\t" . "126" . "\t" . $offercoin . "\t" . "10000";
            GroundModel::writeRedislog($logstring);  //推送日志 失去地块

            $logstring = "1" . "\t" . $service . "\t" . $region . "\t" .
                $groundid . "\t" . $offerqid . "\t" . "2" . "\t" . getMillisecond() . "\t" . "126" . "\t" . $offercoin . "\t" . "10000";
            GroundModel::writeRedislog($logstring);  //推送日志 购得地块
            $massge = "您成功购得一块土地";
            pushMsgDSHB($offertvmid, $massge);
            $massge = "您成功售出一块土地";
            pushMsgDSHB($marketorder[0]['tvmid'], $massge);
            self::$redis->hdel(GroundConfig::GROUND_SALE_LIST, $service . "_" . $region . "_" . $groundid);
            $success['tvmid'] = $offertvmid;
            $success['nickname'] = $nickname;
            $success['headimg'] = $headimg;
            $success['success'] = true;
            return $success;
        } else {
            return GroundModel::returnData(494, '', '拍卖订单数据异常，请联系客服！');
        }
    }

    /**
     * 拍卖成交后退保证金,如中标人余额不足以支付则不退还保证金
     *
     * @param string $offertvmid
     * @param string $orderid
     * @param string $groundtype
     * @param string $operation 1退保证金时不退得标人 2退保证金时退所有人
     */
    private static function refundAuction($offertvmid, $orderid, $groundtype, $operation)
    {
        if ($operation == 1) {
            $sql = sprintf("select * from groundoffer 
                   where marketid = (select id from groundmarket where orderid = '%s') and offertvmid != '%s' group by offertvmid",
                   $orderid, $offertvmid);
        } else {
            $sql = sprintf("select * from groundoffer 
                   where marketid = (select id from groundmarket where orderid = '%s') group by offertvmid ", $orderid);
        }
        $refund = self::$mysql->query($sql, \PDO::FETCH_ASSOC);

        if ($groundtype == 1) {
            $refundcoin = intval(GroundConfig::GROUND_HIGH_PRICE * GroundConfig::GROUND_SALE_SERVERCOIN);
        } else {
            $refundcoin = intval(GroundConfig::GROUND_LOW_PRICE * GroundConfig::GROUND_SALE_SERVERCOIN);
        }
        $refundcoin = 1;

        foreach ($refund->fetchAll() as $row) {
            $addurl = GroundConfig::GROUND_SALE_GOLD_ADD . '?userTvmid=' . $row['offertvmid'] . '&orderid=' . $row['offerorderid'] . '&coin=' . $refundcoin;
            $result = file_get_contents($addurl);
            $fundadd = json_decode($result,true);
            if (isset($fundadd['success'])) {
                $logstring = "33" . "\t" . $orderid . "\t" . $row['offerorderid'] . "\t" . getMillisecond() . "\t" . $row['offerqid'] . "\t"
                    . "126" . "\t" . $refundcoin . "\t" . "2";
                GroundModel::writeRedislog($logstring);  //推送日志 拍卖成交后退保证金
                $massge = "您参与的拍卖活动已结束，退还保证金:".$refundcoin."金币";
                pushMsgDSHB($row['offertvmid'], $massge);
            } else {
                self::baseRequest(GroundConfig::GROUND_SALE_GOLD_ADD, 'auction_refund_' . $offertvmid . '_' . $refundcoin . '_' . $orderid);
            }
        }
    }

    /**
     * 拍卖中标人违约处理
     *
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param string $sellertvmid
     * @param string $buyertvmid
     * @param string $orderid
     * @param string $offerorder
     * @param string $groundtype
     * @param string $sellerqid
     * @param string $offerqid
     * @param integer $marketcoin
     */
    private static function breachAuction($service, $region, $groundid, $sellertvmid, $buyertvmid, $orderid, $offerorder, $groundtype, $sellerqid, $offerqid, $marketcoin)
    {
        if ($groundtype == 1) {
            $refundcoin = GroundConfig::GROUND_HIGH_PRICE * GroundConfig::GROUND_SALE_SERVERCOIN;
        } else {
            $refundcoin = GroundConfig::GROUND_LOW_PRICE * GroundConfig::GROUND_SALE_SERVERCOIN;
        }
$refundcoin = 1;
        $tosellercoin = intval($refundcoin - ($refundcoin * GroundConfig::GROUND_SALE_SERVERCOIN));
        $addurl = GroundConfig::GROUND_SALE_GOLD_ADD . '?userTvmid=' . $sellertvmid . '&orderid=' . $offerorder . '&coin=' . $tosellercoin;
        $result = file_get_contents($addurl);
        $fundadd = json_decode($result,true);
        if (isset($fundadd['success'])) {
            $massge = "您的投拍地块活动已结束，中标用户无法支付所购地块余款，您获得保证金补偿：".$tosellercoin."金币";
            pushMsgDSHB($sellertvmid, $massge);
        } else {
            $massge = "您的投拍地块活动已结束，中标用户无法支付所购地块余款，您将获得保证金补偿：".$tosellercoin."金币，如3小时内未到账，请联系客服！";
            pushMsgDSHB($sellertvmid, $massge);
        }
        //非正常撤单
        $logstring = "35" . "\t" . $orderid . "\t" . getMillisecond() . "\t" . $sellerqid . "\t" . $offerqid . "\t"
            . "126" . "\t" . $refundcoin * GroundConfig::GROUND_SALE_SERVERCOIN . "\t" . $tosellercoin;
        GroundModel::writeRedislog($logstring);  //推送日志 拍卖成交后退保证金
        $massge = "您参与的拍卖活动已结束，金币账户余额不足，无法支付中标地块余款，保证金无法退还！";
        pushMsgDSHB($buyertvmid, $massge);

        $sql = sprintf("update groundmarket set status = 4 where orderid = '%s'", $orderid);
        $update = self::$mysql->prepare($sql);
        $update->execute();
        $logstring = "31" . "\t" . $service . "\t" . $region . "\t" . $groundid . "\t" .
            $sellerqid . "\t" . "4" . "\t" . getMillisecond() . "\t" . $orderid . "\t" . "126" . "\t" . $marketcoin . "\t" . "0";
        GroundModel::writeRedislog($logstring);  //推送日志
        self::$redis->hdel(GroundConfig::GROUND_SALE_LIST, $service . "_" . $region . "_" . $groundid);
        self::destory();
        $massge = "您的地块拍卖已撤单";
        pushMsgDSHB($sellertvmid, $massge);
        return GroundModel::returnData(492, array('status' => 1), '拍卖中标人违约处理');
    }

    /**
     * 用户个人挂单查询
     *
     * @param string $tvmid
     * @param integer $page
     * @param integer $limit
     * @param string $groundtype
     * @param integer $method
     */
    private static function userOrder($tvmid, $page, $limit, $groundtype, $method)
    {
        $sql = sprintf("select * from groundmarket as m 
               left join groundsale as s on m.id = s.marketid 
               where m.groundtype = '%s' and m.tvmid = '%s' and m.method = '%s' and (m.visaul = 1 or s.visaul = 1) 
               order by m.createtime desc limit ".$page.",".$limit, $groundtype, $tvmid, $method);

        $return = array();
        $select = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        foreach ($select->fetchAll() as $row) {
            $return[] = self::groundInfo($row, $tvmid, $method);
        }
        self::destory();
        return GroundModel::returnData(200, $return, '');
    }

    /**
     * 用户个人销售查询
     *
     * @param string $tvmid
     * @param string $groundtype
     * @param integer $page
     * @param integer $limit
     * @param integer $method
     */
    private static function userSale($tvmid, $groundtype, $page, $limit, $method)
    {
        $sql = sprintf("select * from groundsale as s 
               left join groundmarket as m on m.id = s.marketid 
               where m.tvmid = '%s' and m.visaul = 1 and m.groundtype = '%s' and m.method = '%s' 
               order by s.createtime desc limit ".$page.",".$limit, $tvmid, $groundtype, $method);

        $select = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $return = array();
        foreach ($select->fetchAll() as $row) {
            $return[] = self::groundInfo($row, $tvmid, $method);
        }
        self::destory();
        return GroundModel::returnData(200, $return, '');
    }

    /**
     * 用户个人购买查询
     *
     * @param string $tvmid
     * @param string $groundtype
     * @param integer $page
     * @param integer $limit
     * @param integer $method
     */
    private static function userBuy($tvmid, $groundtype, $page, $limit, $method)
    {
        $sql = sprintf("select * from groundsale as s  
               left join groundmarket as m on m.id = s.marketid 
               where s.buyertvmid = '%s' and s.visaul = 1 and m.groundtype = '%s' and m.method = '%s' 
               order by s.createtime desc 
               limit ".$page.",".$limit, $tvmid, $groundtype, $method);

        $select = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $return = array();
        foreach ($select->fetchAll() as $row) {
            $return[] = self::groundInfo($row, $tvmid, $method);
        }
        self::destory();
        return GroundModel::returnData(200, $return, '');
    }

    /**
     * 获取待售地块信息
     *
     * @param array $row
     * @param string $tvmid
     * @param string $method
     */
    private static function groundInfo($row, $tvmid, $method)
    {
        $ground = self::$redis->hget(GroundConfig::GROUND_KEY_HEAD . $row['service'] . "_" . $row['region'], $row['groundid']);
        $arr = json_decode($ground,true);
        if (count($arr) == 0) {
            return false;
        }
        if (isset($arr['factype']) && isset($arr['fid'])) {
            $factory = FactoryConfig::FactoryList($arr['factype']);
            if (!is_array($factory)) {
                return false;
            }
            $factoryresult = $factory[$arr['fid']];
            $return['groundname'] = $arr['nickname'] . "的" . $factoryresult['name'];
            $return['fid'] = $arr['fid'];
        } else {
            $return['groundname'] = $arr['nickname'] . "的地块";
            $return['fid'] = "";
        }
        if ($row['status'] == '1') {//销售状态
            $return['operation'] = 'sale';
        } elseif ($row['status'] == '2') {
            $return['operation'] = 'cancel';
        } elseif ($row['status'] == '3') {
            $return['operation'] = 'buy';
        }
        if (isset($row['tvmid']) && $row['tvmid'] == $tvmid) {
            $return['self'] = true;
        } else {
            $return['self'] = false;
        }
        $return['tvmid'] = getTvmId($arr['qid']);
        $return['nickname'] = $arr['nickname'];
        $return['headimg'] = $arr['headimg'];
        $return['x'] = $arr['x'];
        $return['y'] = $arr['y'];
        if ($method == 2) {
            $sql = sprintf("select max(coin) as coin from groundoffer where marketid = 
              (select id from groundmarket where orderid = '%s')", $row['orderid']);
            $except = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
            $exceptcoin = $except->fetchAll();
            if ($exceptcoin[0]['coin'] != '') {
                $return['coin'] = $exceptcoin[0]['coin'];
            } else {
                $return['coin'] = $row['coin'];
            }
        } else {
            $return['coin'] = $row['coin'];
        }
        $return['src'] = $arr['src'];
        $return['service'] = $row['service'];
        $return['region'] = $row['region'];
        $return['servicename'] = GroundConfig::regionName($row['service'], $row['region']);
        $return['regionname'] = $row['region'];
        $return['groundid'] = $row['groundid'];
        $return['orderid'] = $row['orderid'];
        return $return;
    }

    /**
     * 挂单3次后的服务费扣除
     *
     * @param string $tvmid
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param string $orderid
     */
    private static function serverCoin($tvmid, $service, $region, $groundid, $orderid)
    {
        $select = sprintf("select count(*) as acount from groundmarket 
                  where tvmid = '%s' and service = '%s' and region = '%s' and groundid = '%s'",
                  $tvmid, $service, $region, $groundid);
        $res = self::$mysql->query($select, \PDO::FETCH_ASSOC);
        $result = $res->fetchAll();
        if ($result[0]['acount'] >= 3) {
            $url = GroundConfig::GROUND_SALE_GOLD_MIN.'?userTvmid='.$tvmid.'&orderid='.$orderid.'&coin='.GroundConfig::GROUND_SERVER_COIN;
            $result = file_get_contents($url);
            $fund = json_decode($result, true);
            return $fund;
        } else {
            $fund['success'] = 0;
            return $fund;
        }
    }

    /**
     * 地块销售后的所属用户转换
     *
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param string $from
     * @param string $to
     * @param string $nickname
     * @param string $headimg
     */
    private static function userChange($service, $region, $groundid, $from, $to, $nickname, $headimg)
    {
        $key = GroundConfig::GROUND_KEY_HEAD . $service . "_" . $region;
        $ground = self::$redis->hget($key, $groundid);
        $arr = json_decode($ground,true);
        if (count($arr) == 0) {
            return GroundModel::returnData(499, array('status' => 9), '地产数据异常，请联系客服！');
        }
        if (isset($arr['qid'])) {
            if ($arr['qid'] == $from) {
                self::$redis->multi();
                $arr['qid'] = $to;
                $arr['nickname'] = $nickname;
                $arr['headimg'] = $headimg;
                self::$redis->hset(GroundConfig::GROUND_KEY_HEAD . $service . "_" . $region, $groundid,json_encode($arr));
                self::$redis->hdel(GroundConfig::GROUND_KEY_HEAD . $from, $service . '_' . $region . '_' . $groundid);
                self::$redis->hset(GroundConfig::GROUND_KEY_HEAD . $to, $service . '_' . $region . '_' . $groundid, getMillisecond());
                self::$redis->exec();
                return true;
            } else {
                return GroundModel::returnData(497, array('status' => 7), '地产交接异常，请联系客服！');
            }
        } else {
            return GroundModel::returnData(498, array('status' => 8), '用户地产数据异常，请联系客服！');
        }
    }

    /**
     * 地块销售后的金币处理
     * @param string $to
     * @param string $from
     * @param string $coin
     * @param string $orderid 为挂单时生成的订单id
     * @param string $service
     * @param string $region
     * @param string $groundid
     */
    private static function coinChange($to, $from, $coin, $orderid, $service, $region, $groundid)
    {
        $userCoin = selectCoin($from);
        if (isset($userCoin['data']['main'])) {
            if ($userCoin['data']['main'] < $coin) {
                return GroundModel::returnData(494, array('status' => 4), '金币账号余额不足！');
            }
        }
        $minurl = GroundConfig::GROUND_SALE_GOLD_MIN . '?userTvmid=' . $from . '&orderid=' . $orderid . '&coin=' . $coin;
        $result = file_get_contents($minurl);
        $fundmin = json_decode($result,true);

        if (isset($fundmin['success'])) {
            $salecoin = $coin - intval($coin * GroundConfig::GROUND_SALE_SERVERCOIN);  //扣交易服务费
            $presalecoin = self::presaleWhitelist($to, $service, $region, $groundid);
            $salecoin = $salecoin - $presalecoin;
            if ($salecoin <= 0) {
                $salecoin = 1;
            }
            $addurl = GroundConfig::GROUND_SALE_GOLD_ADD . '?userTvmid=' . $to . '&orderid=' . $orderid . '&coin=' . $salecoin;
            $result = file_get_contents($addurl);
            $fundadd = json_decode($result,true);
            if (isset($fundadd['success'])) {
                return true;
            } else {
                self::baseRequest(GroundConfig::GROUND_SALE_GOLD_ADD,$from . '_' . $to . '_' . $coin . '_' . $orderid);
                return GroundModel::returnData(495, array('status' => 5), '销售方增加金币异常，请联系客服！');
            }
        } else {
            self::baseRequest(GroundConfig::GROUND_SALE_GOLD_MIN,$from . '_' . $to . '_' . $coin . '_' . $orderid);
            return GroundModel::returnData(496, array('status' => 6), '购买方金币扣除异常，请联系客服！');
        }
    }

    /**
     * 地块销售挂单前的预处理
     *
     * @param string $service
     * @param string $region
     * @param string $groundid
     * @param integer $coin
     * @param string $qid
     */
    private static function priceChack($service, $region, $groundid, $coin, $qid)
    {
        $key = GroundConfig::GROUND_KEY_HEAD . $service . "_" . $region;
        $ground = self::$redis->hget($key, $groundid);
        $arr = json_decode($ground,true);
        if (!isset($arr['qid']) || $arr['qid'] != $qid) {
            return GroundModel::returnData(496, array('status' => 6), '用户数据异常');
        }
        $userFactoryData = FactoryModel::userSelFactory($service . "_" . $region . "_" . $groundid, $qid);//查询用户工厂信息
        $keyTmp = $qid . ":" . $userFactoryData['facId'];
        $ProData = ProduceModel::BatchGetProduceInfo(array($keyTmp));
        if ($ProData['code'] == 200 && !empty($ProData['data'][$keyTmp])) {
            return GroundModel::returnData(498, array('status' => 8), '工厂正在生产中,不能出售');
        }
        //地块出价不能低于原出售低价
        if (!isset($arr['price'])) {
            return GroundModel::returnData(495, array('status' => 5), '地块数据异常');
        } else {
            if ($arr['price'] == 1) {
                $price = GroundConfig::GROUND_HIGH_PRICE;
                if ($coin < $price) {
                    return GroundModel::returnData(494, array('status' => 4), '金币价格不应低于' . $price);
                } else {
                    return 1;
                }
            } else {
                $price = GroundConfig::GROUND_LOW_PRICE;
                if ($coin < $price) {
                    return GroundModel::returnData(494, array('status' => 4), '金币价格不应低于' . $price);
                } else {
                    return 2;
                }
            }
        }
    }

    /**
     * 基础请求日志
     *
     * @param string $action
     * @param string $content
     */
    public static function baseRequest($action, $content)
    {
        self::init();
        $sql = sprintf("insert into groundrequest(action,content,createtime)values('%s','%s','%s')", $action, $content, time());
        $exe = self::$mysql->prepare($sql);
        $exe->execute();
    }

    /**
     * 销售黑名单
     *
     * @param string $tvmid
     */
    private static function orderBlack($tvmid)
    {
        $list = GroundConfig::GROUND_SALE_BLACKLIST;
        if (in_array($tvmid, $list)) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * 销售白名单
     *
     * @param string $tvmid
     * @param string $service
     * @param string $region
     * @param string $groundid
     */
    private static function presaleWhitelist($tvmid, $service, $region, $groundid)
    {
        $ground = $service . "_" . $region . "_" . $groundid;
        $sql = sprintf("select * from groundcheck where groundid = '%s' and tvmid = '%s'", $ground, $tvmid);
        $result = self::$mysql->query($sql, \PDO::FETCH_ASSOC);
        $groundcheck = $result->fetchAll();
        if (count($groundcheck) == 1) {
            if ($groundcheck[0]['coin'] == 0) {
                if ($groundcheck[0]['groundt'] == 1) {
                    return 720000;
                } elseif ($groundcheck[0]['groundt'] == 2) {
                    return 450000;
                } else {
                    return 0;
                }
            }
        } else {
            return 0;
        }
    }
}