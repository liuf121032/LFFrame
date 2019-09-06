<?php
/**
 * @description 仓库相关控制器
 * @author 张鹏 zhangpeng@tvmining.com
 * @version 1.0
 * @date 2018-01-18
 * @copyright Copyright (c) 2008 Tvmining (http://www.tvmining.com)
 */
namespace web\controller\Warehouse;

use Kafka\Exception;
use web\controller\BaseController;
use web\model\WarehouseModel;

class WarehouseController extends BaseController
{
    public function storage()
    {
        $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
        $cid = isset($_GET["cid"]) ? $_GET["cid"] : '';
        $num = isset($_GET["num"]) ? $_GET["num"] : '';
        $pwd = isset($_GET["pwd"]) ? $_GET["pwd"] : '';
        if ($pwd != 'zhang') return false;
        $qid = getQid($tvmid);

        $a = WarehouseModel::storage(array('qid' => $qid, 'cid' => $cid, 'amount' => $num, 'service' => 01));
        // $a = WarehouseModel::digUse($qid, 9301, 2);
        var_dump($a);exit;
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
     * 根据tvmid获取仓库
     * @param  [string] tvmid
     * @param  [int] type 材料类型 1.基础材料  2.矿机
     * @return [array] 拼装好url的array
     */
    public function  wareHouseList()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $type = isset($_GET["type"]) ? $_GET["type"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';

            if ($tvmid == '' || $type == '' || $token == '') throw new \Exception('参数错误', 400);
            if (!is_numeric($type)) throw new \Exception('参数type错误', 400);
            if (!in_array($type, array(1,2))) throw new \Exception('参数type错误', 400);

            $qid = getQid($tvmid);
            if($qid == false) throw new \Exception('取用户ID失败002', 403);

            $data = WarehouseModel::wareHouseList($qid, $type);

            echo jsonOut(returnArr(200, $data, 'success'));
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /**
     * 使用挖矿机
     * @param  [string] tvmid
     * @param  [string] cid
     * @param  [int] num 挖矿机数量
     * @param  [string] token
     * @param  [string] nickname
     * @param  [string] headimg
     * @return [json] 
     */
    public function  digUse()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $cid = isset($_GET["cid"]) ? $_GET["cid"] : '';
            $num = isset($_GET["num"]) ? $_GET["num"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';

            if ($tvmid == '' || $cid == '' || $num == '' || $token == '') throw new \Exception('参数错误', 400);
            if (!is_numeric($num)) throw new \Exception('参数num错误', 400);
            if ($num <= 0) throw new \Exception('参数num错误', 400);

            $qid = getQid($tvmid);
            if($qid == false) throw new \Exception('取用户ID失败002', 403);

            $data = WarehouseModel::digUse($qid, $cid, $num);
            if(!$data){
                 throw new \Exception('使用数量超限', 401);
            }else{
                echo jsonOut(returnArr(200, '使用成功', 'success'));
            }
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /**
     * 根据tvmid获取使用中挖矿机信息
     * @param  [string] tvmid
     * @param  [string] token
     * @return [json] 
     */
    public function  digData()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';

            if ($tvmid == '' || $token == '') throw new \Exception('参数错误', 400);

            $qid = getQid($tvmid);
            if($qid == false) throw new \Exception('取用户ID失败002', 403);

            $data = WarehouseModel::digData($qid);

            echo jsonOut(returnArr(200, $data, 'success'));
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /**
     * 挖矿机list
     * @param  [string] tvmid
     * @param  [string] token
     * @return [json] 
     */
    public function  digList()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';

            if ($tvmid == '' || $token == '') throw new \Exception('参数错误', 400);

            $qid = getQid($tvmid);
            if($qid == false) throw new \Exception('取用户ID失败002', 403);

            $data = WarehouseModel::digList($qid);

            echo jsonOut(returnArr(200, $data, 'success'));
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /**
     * 挖矿机数量
     * @param  [string] tvmid
     * @param  [string] token
     * @return [json] 
     */
    public function  digNum()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';
            $token = isset($_GET["token"]) ? $_GET["token"] : '';

            if ($tvmid == '' || $token == '') throw new \Exception('参数错误', 400);
            
            $qid = getQid($tvmid);
            if($qid == false) throw new \Exception('取用户ID失败002', 403);

            $data = WarehouseModel::digNum($qid);
            if (!$data) $data = 0;

            echo jsonOut(returnArr(200, $data, 'success'));
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }
}
