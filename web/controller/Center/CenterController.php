<?php
namespace web\controller\Center;

use web\controller\BaseController;
use web\model\CenterModel;
use web\model\HappinessModel;
use web\model\FactoryModel;

class CenterController extends BaseController
{
    public function  index()
    {
        $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
        $num = isset($_GET["num"]) ? $_GET["num"] : '';
        $qid = getQid($tvmid);

        $data = CenterModel::addEx($qid, $num, 1);
        var_dump($data);exit;
        return 1;
    }

    // 验证token
    public function __construct()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';
            $nickname = isset($_GET["nickname"]) ? $_GET["nickname"] : '';
            $headimg = isset($_GET["headimg"]) ? $_GET["headimg"] : '';
            if ($nickname == '' || $headimg == '') throw new \Exception('参数错误', 400);

            $checkToken = checkToken($tvmid, $token, $nickname, $headimg);
            if (!$checkToken) throw new \Exception('非法token', 403);
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
            exit;
        }
    }

    /**
     * 个人中心信息
     * @param  [string] tvmid
     * @return [json] 
     */
    public function userData()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';

            if ($tvmid == '' || $token == '') throw new \Exception('参数错误', 400);

            $qid = getQid($tvmid);
            if($qid == false) throw new \Exception('取用户ID失败002', 403);

            $data = CenterModel::userEx($qid);

            echo jsonOut(returnArr(200, $data, 'success'));
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /**
     * 个人中心我买到的
     * @param  [string] tvmid
     * @param  [int] page
     * @param  [int] count
     * @return [json] 
     */
    public function mybuy()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $page = isset($_GET["page"]) ? $_GET["page"] : 1;
            $count = isset($_GET["count"]) ? $_GET["count"] : 20;
            $token = isset($_GET["token"]) ? $_GET["token"] : '';

            if ($tvmid == '' || $token == '') throw new \Exception('参数错误', 400);
            $checkToken = getUserSession($token, $tvmid);
            if (!$checkToken) throw new \Exception('非法token', 403);
            $qid = getQid($tvmid);
            if($qid == false) throw new \Exception('取用户ID失败002', 403);
            $host = C('NEARBY_YAOTV');
            $url = $host.'/g/query_mybuy_trade_list?tvmid='.$tvmid.'&pageNo='.$page.'&pageCount='.$count;
            // $res = curlUrl($url);
            print_r($url);exit;
            
            $a = 1; // 调俊伟接口
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /**
     * 个人中心我卖出的
     * @param  [string] tvmid
     * @param  [int] page
     * @param  [int] count
     * @return [json] 
     */
    public function mysell()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $page = isset($_GET["page"]) ? $_GET["page"] : '';
            $count = isset($_GET["count"]) ? $_GET["count"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';

            if ($tvmid == '' || $token == '') throw new \Exception('参数错误', 400);
            $checkToken = getUserSession($token, $tvmid);
            if (!$checkToken) throw new \Exception('非法token', 403);
            // $qid = getQid($tvmid);
            $host = C('NEARBY_YAOTV');
            $url = $host.'/g/query_mysell_trade_list?tvmid='.$tvmid.'&pageNo='.$page.'&pageCount='.$count;
            print_r($url);exit;

        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /**
     * 个人中心我发布的交易
     * @param  [string] tvmid
     * @param  [int] page
     * @param  [int] count
     * @return [json] 
     */
    public function myTrade()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $page = isset($_GET["page"]) ? $_GET["page"] : '';
            $count = isset($_GET["count"]) ? $_GET["count"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';

            if ($tvmid == '' || $token == '') throw new \Exception('参数错误', 400);
            $checkToken = getUserSession($token, $tvmid);
            if (!$checkToken) throw new \Exception('非法token', 403);
            // $qid = getQid($tvmid);
            $host = C('NEARBY_YAOTV');
            $url = $host.'/g/query_myrel_trade_list?tvmid='.$tvmid.'&pageNo='.$page.'&pageCount='.$count;
            print_r($url);exit;

        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /**
     * 个人中心我发布的采购
     * @param  [string] tvmid
     * @param  [int] page
     * @param  [int] count
     * @return [json] 
     */
    public function myPurchase()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $page = isset($_GET["page"]) ? $_GET["page"] : '';
            $count = isset($_GET["count"]) ? $_GET["count"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';

            if ($tvmid == '' || $token == '') throw new \Exception('参数错误', 400);
            $checkToken = getUserSession($token, $tvmid);
            if (!$checkToken) throw new \Exception('非法token', 403);
            // $qid = getQid($tvmid);
            $host = C('NEARBY_YAOTV');
            $url = $host.'/g/query_myrel_purchase_list?tvmid='.$tvmid.'&pageNo='.$page.'&pageCount='.$count;
            print_r($url);exit;

        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /**
     * 个人中心--工作日志
     * @param  [string] tvmid
     * @return [json] 
     */
    public function workData()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';
            // $type = isset($_GET["type"]) ? $_GET["type"] : '';
            $offset = isset($_GET["offset"]) ? $_GET["offset"] : 0;
            $count = isset($_GET["count"]) ? $_GET["count"] : 20;

            if ($tvmid == '' || $token == '') throw new \Exception('参数错误', 400);

            $qid = getQid($tvmid);
            if($qid == false) throw new \Exception('取用户ID失败002', 403);

            $data = CenterModel::workData($qid, $offset, $count);
            if (!$data || empty($data)) {
                echo jsonOut(returnArr(200, [], 'success'));
                exit;
            }
            
            echo jsonOut(returnArr(200, $data, 'success'));
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /**
     * 个人中心工厂
     * @param  [string] tvmid
     * @param  [string] token
     * @param  [int] type
     * @return [json] 
     */
    public function factory()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';
            $type = isset($_GET["type"]) ? $_GET["type"] : '';

            if ($tvmid == '' || $token == '' || $type == '') throw new \Exception('参数错误', 400);

            $qid = getQid($tvmid);
            if($qid == false) throw new \Exception('取用户ID失败002', 403);
            
            $data = FactoryModel::userSelFacCenter($qid, $type);
            if (!$data || empty($data)) {
                echo jsonOut(returnArr(200, [], 'success'));
                exit;
            }
            $res = array();
            foreach ($data as $key => $value) {
                $res[] = $value;
            }

            echo jsonOut(returnArr(200, $res, 'success'));
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /**
     * 个人中心恢复幸福指数
     * @param  [string] tvmid
     * @return [json] 
     */
    public function buyHappiness()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $service = isset($_GET["service"]) ? $_GET["service"] : '';
            $region = isset($_GET["region"]) ? $_GET["region"] : '';
            $gid = isset($_GET["gid"]) ? $_GET["gid"] : '';
            $coin = isset($_GET["coin"]) ? $_GET["coin"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';

            if ($tvmid == '' || $token == '') throw new \Exception('参数错误', 400);

            $qid = getQid($tvmid);
            if($qid == false) throw new \Exception('取用户ID失败002', 403);
            
            $res = HappinessModel::buyHappiness($service, $region, $gid, $qid, $coin); // 服务器id,区域id,地块id,用户qid,金币数

            if (!$res) throw new \Exception('恢复失败,服务器内部错误', 500);

            echo jsonOut(returnArr(200, '恢复成功', 'success'));
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }
}
