<?php
namespace web\controller\Ground;

use web\controller\BaseController;
use web\model\GroundModel;
use config\GroundConfig;
use database\MyRedis;

class GroundController extends BaseController
{
    public function allGround()
    {
        try {
            $service = get('service') ? get('service') : '755';
            $region = get('region') ? get('region') : '1';
            $tvmid = get('tvmid') ? get('tvmid') : '';
            $token = get('token') ? get('token') : '';
            $nickname = get('nickname') ? get('nickname') : '';
            $headimg = get('headimg') ? get('headimg') : '';

            if ($tvmid == '' || $token == '' || $nickname == '' || $headimg == '') {
                echo jsonOut(returnArr('410', '', 'tvmid error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);
            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user denied'));
                exit;
            }

            $result = GroundModel::allGround($service, $region, $tvmid,"");
            if (!$result) {
                echo jsonOut(returnArr('410', '', 'qid error'));
                exit;
            }
            echo jsonOut(returnArr(0, $result, ''));
            exit;
        } catch (\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 购买地块
     */
    public function buyGround()
    {
        try {
            $tvmid = post('tvmid');
            $service = post('service');
            $region = post('region');
            $gid = post('gid');
            $token = post('token');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';

            if ($tvmid == '' || $service == '' || $region == '' || $gid == '' || $token == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }
/*
            $result = GroundModel::groundBuy($service,$region,$gid,$tvmid,$token,$nickname,$headimg);

            echo jsonOut($result);*/
        } catch (\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 获取部分地块信息
     */
    public function partGround()
    {
        try {
            $service = post('service') ? post('service') : '755';
            $region = post('region') ? post('region') : '1';
            $tvmid = post('tvmid') ? post('tvmid') : '';
            $token = post('token') ? post('token') : '';
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';
            $ground = post('ground') ? post('ground') : '';

            if ($tvmid == '' || $token == '' || $nickname == '' || $headimg == '' || $ground == '') {
                echo jsonOut(returnArr('410', '', 'paramter error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);
            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user denied'));
                exit;
            }

            $result = GroundModel::partGround($service, $region, $tvmid, $user, $ground);

            if (!$result) {
                echo jsonOut(returnArr('410', '', 'qid error'));
                exit;
            }
            echo jsonOut($result);
            exit;
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 服务器及区域查询
     */
    public function selectGround()
    {
        try {
            $service = get('service') ? get('service') : '';
            $tvmid = get('tvmid') ? get('tvmid') : '';
            $token = get('token') ? get('token') : '';
            $nickname = get('nickname') ? get('nickname') : '';
            $headimg = get('headimg') ? get('headimg') : '';

            if ($tvmid == '' || $token == '' || $nickname == '' || $headimg == '') {
                echo jsonOut(returnArr('410', '', 'paramter error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);
            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user denied'));
                exit;
            }

            $result = GroundModel::selectGround($service);

            if (!$result) {
                echo jsonOut(returnArr('410', '', 'qid error'));
                exit;
            }
            echo jsonOut(returnArr(0, $result, ''));
            exit;
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 地块通知信息
     */
    public function groundNotice()
    {
        try{
            $tvmid = get('tvmid') ? get('tvmid') : '';
            $token = get('token') ? get('token') : '';
            $nickname = get('nickname') ? get('nickname') : '';
            $headimg = get('headimg') ? get('headimg') : '';

            if ($tvmid == '' || $token == '' || $nickname == '' || $headimg == '') {
                echo jsonOut(returnArr('410', '', 'paramter error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);
            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user denied'));
                exit;
            }

            $result = GroundModel::groundNotice();

            if (!$result) {
                echo jsonOut(returnArr('410', '', 'qid error'));
                exit;
            }
            echo jsonOut($result);
            exit;
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 查询用户地块数量
     */
    public function selectUserGround()
    {
        $tvmid = get('tvmid');

        if (empty($tvmid)) {
            echo jsonOut(returnArr(402, '', '参数不正确'));exit;
        }

        $data = GroundModel::selectUserGround($tvmid);

        echo jsonOut($data);
        exit;
    }

    /**
     * 买楼主界面通知信息编辑页
     */
    public function noticeDeal()
    {
        if (get('code') && get('code') == GroundConfig::GROUND_NOTICE_CODE) {
            if (!post('content')) {
                return $this->View->view('greeting', self::notice_get());
            } else {
                $title = post('title');
                $content = post('content');
                $state = post('statec') ? post('statec') : '0';
                $break = post('break') ? post('break') : '0';
                return $this->View->view('greeting', self::notice_operation($title, $content, $state, $break));
            }
        }
    }

    /**
     * 获取当前通知信息
     */
    public function notice()
    {
        $result = self::notice_get();
        if (isset($result['state_v']) && $result['state_v'] == '1') {
            $return['code'] = 0;
            $return['title'] = isset($result['title']) ? $result['title'] : '';
            $return['content'] = isset($result['content']) ? $result['content'] : '';
            $return['break'] = isset($result['break_v']) ? $result['break_v'] : '';
            echo jsonOut(returnArr(0, $return, ''));
            exit;
        } else {
            echo jsonOut(returnArr(0, array('code'=> 1,'msg' => '无消息'), ''));
            exit;
        }
    }

    /**
     * 地块预售
     */
    public function presaleGround()
    {
        try {
            $tvmid = post('tvmid') ? post('tvmid') : '';
            $service = post('service') ? post('service') : '';
            $region = post('region') ? post('region') : '';
            $price = post('price') ? post('price') : '';
            $token = post('token') ? post('token') : '';
            $amount = post('amount') ? post('amount') : '';
            $type = post('type') ? post('type') : '';
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';

            if ($tvmid == '' || $service == '' || $region == '' || $price == '' || $token == '' || $amount == '' || $type == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }

            $result = GroundModel::groundPresale($service, $region, $price, $amount, $type, $tvmid);

            echo jsonOut($result);
            exit;
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 地块预售查询
     */
    public function presaleSelect()
    {
        try {
            $tvmid = post('tvmid') ? post('tvmid') : '';
            $service = post('service') ? post('service') : '';
            $region = post('region') ? post('region') : '';
            $token = post('token') ? post('token') : '';
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';

            if ($tvmid == '' || $service == '' || $region == '' || $token == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }

            $result = GroundModel::presaleSelect($service, $region, $tvmid);

            echo jsonOut($result);
            exit;
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    public function test()
    {

    }

    /**
     * 更新通知消息
     */
    private static function notice_operation($title, $content, $state, $break)
    {
        if ($state == '1' || $state == '0') {
            $notice['state'] = $state;
        } else {
            $notice['state'] = '0';
        }
        $r = MyRedis::getConnect();
        $notice['content'] = $content;
        $notice['title'] = $title;
        $notice['version'] = time();
        $notice['break'] = $break;
        $r->hmset(GroundConfig::GROUND_NOTICE, $notice);
        self::add_history($notice);
        $result = self::notice_get();
        $r->close();
        return $result;
    }

    /**
     * 消息读取
     */
    private static function notice_get()
    {
        $r = MyRedis::getConnect();
        $notice = $r->hGetAll(GroundConfig::GROUND_NOTICE);
        if (isset($notice['content']) && isset($notice['state'])) {
            $return['title'] = isset($notice['title']) ? $notice['title'] : '';
            $return['content'] = $notice['content'];
            if ($notice['state'] == '1') {
                $return['state'] = 'checked';
                $return['state_v'] = '1';
            } else {
                $return['state'] = '';
                $return['state_v'] = '0';
            }

            if ($notice['break'] == '1') {
                $return['break'] = 'checked';
                $return['break_v'] = 1;
            } else {
                $return['break'] = '';
                $return['break_v'] = 0;
            }
        } else {
            $return['content'] = '';
            $return['state'] = '';
            $return['state_v'] = '0';
            $return['title'] = '';
            $return['break'] = '';
            $return['break_v'] = '';
        }
        $r->close();
        return $return;
    }

    /**
     * 消息增加进历史队列
     */
    private static function add_history($notice)
    {
        $r = MyRedis::getConnect();
        $last = $r->lrange(GroundConfig::GROUND_NOTICE_HISTORY,0,0);

        if (count($last) == 0) {
            $notice['time'] = getMillisecond();
            $r->lpush(GroundConfig::GROUND_NOTICE_HISTORY, json_encode($notice));
        } else {
            $last = json_decode($last[0],true);
            if ($last['content'] != $notice['content']) {
                $time = getMillisecond();
                $notice['time'] = $time;
                $r->lpush(GroundConfig::GROUND_NOTICE_HISTORY, json_encode($notice));
            }
        }
        $r->close();
    }
}
