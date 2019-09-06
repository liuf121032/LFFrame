<?php
/**
 * @description 个人中心相关服务
 * @author 张鹏 zhangpeng@tvmining.com
 * @version 1.0
 * @date 2018-01-23
 * @copyright Copyright (c) 2008 Tvmining (http://www.tvmining.com)
 */

namespace web\model;

use config\Tab;
use database\MyRedis;
use database\MySql;
use config\FactoryConfig;
use config\ProduceConfig;

class CenterModel extends BaseModel
{
    /**
     * 增加经验
     * @param  [string] qid
     * @param  [int] num 增加的数值
     * @param  [int] type 经验类型 1.个人等级 2.打工 3.经营
     * @return [bool] 
     */
    public static function addEx($qid, $num, $type)
    {
        if((int)$num == 0){
            return false;
        }
        $con = MyRedis::getConnect();
        $key = Tab::USER_EXPERIENCE.$qid;
        $stime = getMillisecond();

        switch ($type) {
            case '1':
                $hkey = 'user';
                $optLog = "15\t".$qid."\t".$num."\t";
                break;
            case '2':
                $hkey = 'job';
                $optLog = "16\t".$qid."\t".$num."\t";
                break;
            case '3':
                $hkey = 'business';
                $optLog = "17\t".$qid."\t".$num."\t";
                break;
            default:
                return false;
                break;
        }
        $add = $con -> hincrby($key, $hkey, $num);
        $optLog .= $add."\t".$stime;

        if (!$add) {
            // mLogs('addEx','func addEx',$qid.'-'.$hkey.'-'.$num);
            return false;
        }
        opt_log_for_produce($optLog); // 推日志
        self::title($qid, $add,$type);
        $con -> close();
        return true;
    }

    /**
     * 等级称号
     * @param  [string] qid
     * @param  [int] num 经验值(等级或称号)
     * @param  [int] type 经验值类型
     * @return [bool] 
     */
    private static function title($qid, $num,$type)
    {
        $con = MyRedis::getConnect();
        $key = Tab::USER_TITLE.$qid;
        $stime = getMillisecond();
        switch ($type) {
            case '1':
                $hkey = 'user';
                $new = self::experience($num);
                $optLog = "12\t";
                break;
            case '2':
                $hkey = "job";
                $new = self::jobTitle($num);
                $optLog = "13\t";
                break;
            case '3':
                $hkey = 'business';
                $new = self::businessTitle($num);
                $optLog = "14\t";
                break;
            default:
                return false;
                break;
        }
        $check = $con -> hget($key, $hkey);
        if (!$check) $check = '';
        if ($new != $check) {
            $check = $con -> hset($key, $hkey, $new);
            $optLog .= $qid."\t".$new."\t".$num."\t".$stime;
            opt_log_for_produce($optLog); // 推日志
        }
        $con -> close();
        return true;
    }

    /**
     * 个人中心信息
     * @param  [string] qid
     * @return [array] 
     */
    public static function userEx($qid)
    {
        $con = MyRedis::getConnectRead();
        $key = Tab::USER_EXPERIENCE.$qid;
        $keyJob = Tab::USER_TITLE.$qid;
        $exNum = $con -> hget($key, 'user');
        $exNum = isset($exNum) ? $exNum : 0;
        $jobNum = $con -> hget($keyJob, 'job');
        $jobNum = isset($jobNum) ? $jobNum : 0;
        $businessNum = $con -> hget($keyJob, 'business');
        $businessNum = isset($businessNum) ? $businessNum : 0;
        if (!$exNum || $exNum == '') $exNum = 0;
        $level = self::experience($exNum); // 等级
        $exNext = self::getEx($level); // 下级所需经验
        // $jobTitle = self::jobTitle($jobNum); // 工作称号
        // $businessTitle = self::businessTitle($businessNum); // 经营称号
        $userCdTime = JobModel::getUserCdTime($qid);
        if (!$userCdTime || $userCdTime['code'] != 200) {
            $status = 0;
        } else {
            $status = $userCdTime['data'];
        }

        //黄金时间和种子
        // $tvmid = getTvmid($qid);
        // $shiJian = self::hjZhongZi($tvmid);
        // $zhongZi = self::hjShiJian($tvmid);

        $getExBegin = self::getExBegin($level); // 本级初始经验
        $ex = $exNum - $getExBegin;

        // 称号暂时全部为0
        $jobNum = 0;
        $businessNum = 0;
        //
        $res = array(
                'status' => $status,
                'level' => $level,
                'experience' => $ex >= 0 ? $ex : 0,
                'next_experience' => $exNext,
                'job_title' => $jobNum,
                // 'gold_seed' => $zhongZi,
                // 'gold_time' => $shiJian,
                'business_title' => $businessNum
            );
        $con -> close();
        return $res;
    }

    /**
     * 个人经验换算等级
     * @param  [int] num 增加的数值
     * @return [int] 级别
     */
    public static function experience($num)
    {
        for ($i=1; $i < 50; $i++) { 
            $ex = self::getExBegin($i + 1);
            if ($ex > $num) break;
        }
        $i = $i > 50 ? 50 : $i;
        return $i;


        $first = 0;
        if ($num > 100) {
            $num = substr($num, 0, -2).'00';
            $first = 700;
        }
        @$tmpEx = $num - $first;
        if ($tmpEx > 0) {
            $level = ($tmpEx / 300) + 1;
        } else {
            return 1;
        }
        
        if ($level <= 1) return 1;
        if ($level > 50) return 50;
        return floor($level);
    }

    /**
     * 根据等级获得比上一级多的经验
     * @param  [int] level 等级
     * @return [int] 经验
     */
    private static function lastEx($level)
    {
        if ($level == 0) return 0;
        $first = 700;
        $ex = ($level * 300) + $first;
        return $ex;
    }

    /**
     * 获得等级初始经验
     * @param  [int] level 等级
     * @return [int] 等级初始经验
     */
    private static function getExBegin($level)
    {
        $ex = 0;
        for ($i=0; $i < $level; $i++) {
            $ex += self::lastEx($i);
        }
        return $ex;
    }

    /**
     * 根据等级换算下级经验
     * @param  [int] num 等级
     * @return [int] 经验
     */
    public static function getEx($num)
    {
        $first = 700;
        $ex = ($num * 300) + $first;
        if ($ex > 15400) return 999999;
        return $ex;
    }

    /**
     * 打工称号
     * @param  [int] num 经验值
     * @return [string] 称号
     */
    public static function jobTitle($num)
    {
        $arr = array(
            '0' => '0',
            '1000' => '1',
            '2000' => '2',
            '3000' => '3',
            '4000' => '4'
            );
        $title = '';
        foreach ($arr as $key => $value) {
            if ($num >= $key) $title = $value;
        }
        return $title;
    }

    /**
     * 经营称号
     * @param  [int] num 经验值
     * @return [string] 称号
     */
    public static function businessTitle($num)
    {
        $arr = array(
            '0' => '0',
            '1000' => '1',
            '2000' => '2',
            '3000' => '3',
            '4000' => '4'
            );
        $title = '';
        foreach ($arr as $key => $value) {
            if ($num >= $key) $title = $value;
        }
        return $title;
    }

    /**
     * 级别对应称号
     * @param  [string] levelNum 级别
     * @param  [int] 类型 1.打工  2.经营
     * @return [int] 级别
     */
    public static function getLevelName($levelNum, $type)
    {
        switch ($type) {
            case '1':
                $arr = array(
                '0' => '无',
                '1' => '学徒',
                '2' => '匠人',
                '3' => '精英',
                '4' => '大师'
                );
                break;
            
            default:
                $arr = array(
                '0' => '无',
                '1' => '地主',
                '2' => '总裁',
                '3' => '董事',
                '4' => '大亨'
                );
                break;
        }
        $level = isset($arr[$levelNum]) ? $arr[$levelNum] : '0';
        return $level;
    }

    /**
     * 工作日志
     * @param  [string] qid
                 * @param  [int] type 查询类型 1:月  2:周  3:总
     * @param  [int] offset 分页值
     * @return [array] 工作日志
     */
    public static function workData($qid, $offset, $count)
    {
        $con = MyRedis::getConnectRead();
        $key = ProduceConfig::WORKER_DOWORK_LOG.$qid;
        $num = ($offset + $count) - 1;
        $data = $con -> ZREVRANGE($key, $offset, $num);
        $con -> close();
        $list = array();
        foreach ($data as $k => $val) {
            $list[$k] = json_decode($val, true);
        }
        $res = array('data' => array(), 'offset' => $num + 1);
        $res['data'] = $list;
        return $res;

        
        // print_r(self::getDate('m'));exit;
        $con = MyRedis::getConnect();
        $today = date('Ymd', time());
        switch ($type) {
            case '1':
                $key = Tab::USER_WORK_DAY.$qid;
                $oneDate = self::getDate('m');
                $arr = array();
                for ($i=$oneDate; $i <= $today; $i++) {
                    $arr[] = date('Y-m-d', strtotime($i)); // redis key
                }
                $data = $con -> hmGet($key, $arr);
                break;

            case '2':
                $key = Tab::USER_WORK_DAY.$qid;
                $oneDate = self::getDate('w');
                $arr = array();
                for ($i=$oneDate; $i <= $today; $i++) {
                    $arr[] = date('Y-m-d', strtotime($i)); // redis key
                }
                $data = $con -> hmGet($key, $arr);
                break;
            
            default:
                $key = Tab::USER_WORK_ALL.$qid;
                $cardData = $con -> hgetall($key);
                $data = array();
                if (!empty($cardData)) {
                    $cardList = FactoryConfig::propArr();
                    foreach ($cardData as $key => $value) {
                        $data[$key]['name'] = isset($cardList[$key]['name']) ? $cardList[$key]['name'] : '';
                        $data[$key]['num'] = $value;
                        $data[$key]['cid'] = $key;
                    }
                }
                break;
        }
        $con -> close();
        unset($key, $value);
        $res = array();
        if ($type == 1 || $type == 2) { 
            foreach ($data as $key => $value) {
                if ($value == false) $value = '0';
                $res[] = array(
                        'num' => $value,
                        'time' => $key
                        );
            }
        } else {
            foreach ($data as $key => $value) {
                $res[] = $value;
            }
        }
        return $res;
    }

    /**
     *日期计算
     * @param  [int] type
     * @return [string]日期
     */
    private static function getDate($type)
    {
        if ($type == 'w') {
            $date = date('Ymd', (time() - ((date('w') == 0 ? 7 : date('w')) - 1) * 24 * 3600)); // 本周一日期
        } else if ($type == 'm') {
            $date = date('Ymd', strtotime(date('Y-m', time()) . '-01 00:00:00')); //本月一日日期
        }
        return $date;
    }

    /**
     * 黄金种子数量
     * @param  [int] tvmid
     * @return [int] 数量
     */
    private static function hjZhongZi($tvmid)
    {
        $host = C('HUANGJING_ZHONGZI');
        $id = C('HUANGJIN_TIME_ID');
        $url = $host."/open/user/seed?openId=".$tvmid."&yyyappid=".$id;
        $data = curlUrl($url);
        if (!$data) return 0;
        $data = json_decode($data, true);
        if ($data['status'] != 'success') return 0;
        if (!isset($data['data']['seed'])) return 0;
        return $data['data']['seed'];
    }

    /**
     * 黄金时间数量
     * @param  [int] tvmid
     * @return [int] 数量
     */
    private static function hjShiJian($tvmid)
    {
        $host = C('HUANGJIN_TIME');
        $url = $host."/fastcall/dktimeext/getholdnumber?tvmid=".$tvmid."&code=HJSJ";
        $data = curlUrl($url);
        if (!$data) return 0;
        $data = json_decode($data, true);
        if ($data['status'] != 1) return 0;
        if (!isset($data['data']['hold_number'])) return 0;
        return $data['data']['hold_number'];
    }
}