<?php
namespace web\controller\Produce;
/**
 * Created by PhpStorm.
 * User: root
 * Date: 18-1-4
 * Time: 下午3:58
 */

use web\controller\BaseController;

use web\model\JobModel;
use web\model\ProduceModel;

class ProduceController extends BaseController
{
    const VERIFY_USER = true;
    const debugMod = false;

    /**
     * 工厂生产
     * @param tvmId 用户id
     * @param server 服ID
     * @param region 区域ID
     * @param pId 地块id
     * @param facId 工厂id
     * @param productId 生产的材料id
     * @param productNums 生产的数量
     * @param workerNums 需要的工人数量
     * @param salery 每人工资
     * @param token
     * @param sign 签名
     * @param timestamp 时间戳（毫秒）
     * @return json {"code":200,"msg":"success"}
     */
    public function factoryProduce()
    {
        //ini_set("display_errors",1);

        $tvmId = post("tvmId");
        $server = post("server");
        $region = post("region");
        $pId = post("pId");
        $facId = post("facId");
        $sign = post("sign");
        $productId = post("productId");
        $productNums = (int)post("productNums");
        $workerNums = (int)post("workerNums");
        $salery = (int)post("salery");
        $token = post("token");
        $timestamp = post("timestamp");
        if(empty($timestamp)){
            $timestamp = 0;
        }

        if (
            empty($tvmId) ||
            empty($server) ||
            empty($region) ||
            empty($pId) ||
            empty($facId) ||
            empty($sign) ||
            empty($productId) ||
            empty($token) ||
            empty($productNums) ||
            empty($workerNums) ||
            empty($salery)
        ) {
            echo jsonOut(returnArr(400,"","请求参数错误"));exit;
        }

        //获取qid
        $qId = getQid($tvmId);
        if($qId == false){
            echo jsonout(returnArr(403, '', '取用户ID失败'));exit;
        }

        //签名验证
        $signArr = array(
            "qId" => $qId,
            "serverId" => $server,
            "region" => $region,
            "pId" => $pId,
            "facId" => $facId,
        );

        if(self::debugMod) {
            echo makeSign($signArr);
        }
        if($sign != makeSign($signArr)){
            echo jsonOut(returnArr(403,"","签名错误"));exit;
        }

        //验证用户
        $headImg = "unknown";
        $nickName = "unknown";
        if(self::VERIFY_USER) {
            $userInfo = getUserSession($token, $tvmId);
            if (!$userInfo) {
                echo jsonout(returnArr(403, '', '用户验证失败'));
                exit(1);
            } else {
                $headImg = $userInfo['headimg'];
                $nickName = $userInfo['nickname'];
            }
        }

        //用户红包id
        $redId = getRedId($qId) ?: getUserRedId($tvmId);
        if(!$redId){
            $redId = "";
        }

        $paramArr = array(
            "qId" => $qId,
            "redId" => $redId,
            "tvmId" => $tvmId,
            "server" => $server,
            "region" => $region,
            "pId" => $pId,
            "facId" => $facId,
            "productId" => $productId,
            "productNums" =>$productNums,
            "workerNums" =>$workerNums,
            "salery" => $salery,
            "nickName" => $nickName,
            "headImg" => $headImg,

        );

        $result = ProduceModel::fProduce($paramArr);

        echo jsonOut($result);exit;

    }

    /**
     * 获取工厂的生产信息
     * @param tvmId 工厂所有者的tvmid
     * @param server 服ID
     * @param region 区域ID
     * @param pId 地块id
     * @param facId 工厂id，唯一id
     * @param sign 签名
     * @param token
     * @param pTvmid 当前用户tvmid
     * @return json {"code":0,"data":{...},"msg":""}
     */
    public function getProduceInfo()
    {
        $tvmId = post("tvmId");
        $server = post("server");
        $region = post("region");
        $pId = post("pId");
        $facId = post("facId");
        $sign = post("sign");
        $token = post("token");
        $pTvmid = post("pTvmid");

        if (
            empty($tvmId) ||
            empty($server) ||
            empty($region) ||
            empty($pId) ||
            empty($facId) ||
            empty($sign) ||
            empty($token) ||
            empty($pTvmid)
        ) {
            echo jsonOut(returnArr(400,"","请求参数错误"));exit;
        }

        //获取qid
        $qId = getQid($tvmId);
        if($qId == false){
            echo jsonout(returnArr(403, '', '取用户ID失败'));exit;
        }

        //签名验证
        $signArr = array(
            "qId" => $qId,
            "serverId" => $server,
            "region" => $region,
            "pId" => $pId,
            "facId" => $facId,
        );

        if(self::debugMod) {
            echo makeSign($signArr);
        }
        if($sign != makeSign($signArr)){
            echo jsonOut(returnArr(403,"","签名错误"));exit;
        }

        //验证用户
        $headImg = "unknown";
        $nickName = "unknown";
        if(self::VERIFY_USER) {
            $userInfo = getUserSession($token, $pTvmid);
            if (!$userInfo) {
                echo jsonout(returnArr(403, '', '用户验证失败'));exit(1);
            } else {
                $headImg = $userInfo['headimg'];
                $nickName = $userInfo['nickname'];
            }
        }

        $paramArr = array(
            "qId" => $qId,
            "tvmId" => $tvmId,
            "pId" => $pId,
            "facId" => $facId,
            "pTvmid" => $pTvmid,
        );

        $result = ProduceModel::modelProduceInfo($paramArr);

        echo jsonOut($result);exit;
    }

    /**
     * 产品合成
     * @param tvmId 用户id
     * @param server 服ID
     * @param region 区
     * @param pId 地块id
     * @param facId 工厂id
     * @param productId 合成产品的ID
     * @param productNums 合成数量 默认为1
     * @param sign 签名
     * @param token
     * @return json {"code":0,"data":{...},"msg":""}
     */
    public function compose()
    {
        $tvmId = post("tvmId");
        $server = post("server");
        $region = post("region");
        $pId = post("pId");
        $facId = post("facId");
        $productId = post("productId");
        $productNums = (int)post("productNums");
        $sign = post("sign");
        $token = post("token");

        if (
            empty($tvmId) ||
            empty($server) ||
            empty($region) ||
            empty($pId) ||
            empty($facId) ||
            empty($productId) ||
            empty($sign) ||
            empty($token)
        ) {
            echo jsonOut(returnArr(400,"","请求参数错误"));exit;
        }

        $productNums = $productNums < 1 ? 1 : $productNums;

        //获取qid
        $qId = getQid($tvmId);
        if($qId == false){
            echo jsonout(returnArr(403, '', '取用户ID失败'));exit;
        }

        //签名验证
        $signArr = array(
            "qId" => $qId,
            "serverId" => $server,
            "region" => $region,
            "pId" => $pId,
            "facId" => $facId,
        );

        if(self::debugMod) {
            echo makeSign($signArr);
        }

        if($sign != makeSign($signArr)){
            echo jsonOut(returnArr(403,"","签名错误"));exit;
        }

        //验证用户
        $headImg = "unknown";
        $nickName = "unknown";
        if(self::VERIFY_USER) {
            $userInfo = getUserSession($token, $tvmId);
            if (!$userInfo) {
                echo jsonout(returnArr(403, '', '用户验证失败'));
                exit(1);
            } else {
                $headImg = $userInfo['headimg'];
                $nickName = $userInfo['nickname'];
            }
        }



        $paramArr = array(
            "qId" => $qId,
            "tvmId" => $tvmId,
            "server" => $server,
            "region" => $region,
            "pId" => $pId,
            "facId" => $facId,
            "productId" => $productId,
            "productNums" => $productNums,
            "nickName" => $nickName,
            "headImg" => $headImg,
        );

        $result = ProduceModel::productCompose($paramArr);

        echo jsonOut($result);exit;
    }

    /**
     * 撤单
     * @param tvmId 用户id
     * @param server 服ID
     * @param region 区
     * @param pId 地块id
     * @param facId 工厂id
     * @param sign 签名
     * @param token
     * @return json {"code":0,"data":{...},"msg":""}
     */
    public function cancelProduce(){
        $tvmId = post("tvmId");
        $server = post("server");
        $region = post("region");
        $pId = post("pId");
        $facId = post("facId");
        $sign = post("sign");
        $token = post("token");

        if (
            empty($tvmId) ||
            empty($server) ||
            empty($region) ||
            empty($pId) ||
            empty($facId) ||
            empty($sign) ||
            empty($token)
        ) {
            echo jsonOut(returnArr(400,"","请求参数错误"));exit;
        }

        //获取qid
        $qId = getQid($tvmId);
        if($qId == false){
            echo jsonout(returnArr(403, '', '取用户ID失败'));exit;
        }

        //签名验证
        $signArr = array(
            "qId" => $qId,
            "serverId" => $server,
            "region" => $region,
            "pId" => $pId,
            "facId" => $facId,
        );

        if(self::debugMod) {
            echo makeSign($signArr);
        }
        if($sign != makeSign($signArr)){
            echo jsonOut(returnArr(403,"","签名错误"));exit;
        }

        //验证用户
        $headImg = "unknown";
        $nickName = "unknown";
        if(self::VERIFY_USER) {
            $userInfo = getUserSession($token, $tvmId);
            if (!$userInfo) {
                echo jsonout(returnArr(403, '', '用户验证失败'));
                exit(1);
            } else {
                $headImg = $userInfo['headimg'];
                $nickName = $userInfo['nickname'];
            }
        }

        $paramArr = array(
            "qId" => $qId,
            "tvmId" => $tvmId,
            "server" => $server,
            "region" => $region,
            "pId" => $pId,
            "facId" => $facId,
            "nickName" => $nickName,
            "headImg" => $headImg,
        );

        $result = ProduceModel::produceCancel($paramArr);

        echo jsonOut($result);exit;

    }

    public function ts(){
        $tvmid = post("tvmid");
        if($tvmid){
            $testArr = array(
            );
            if(!in_array($tvmid,$testArr)){
                echo "failed";exit;
            }

            $salery = (int)post("salery");
            $salery = $salery < 1 ? 1 : $salery;
            $nickName = post("nickName");
            $headImg = post("headImg");

            $qId = getQid($tvmid);

            $paramArr = array(
                "qId" => $qId,
                "tvmId" => $tvmid,
                "salery" => $salery,
                "nickName" => $nickName,
                "headImg" => $headImg,
            );

            $result = ProduceModel::dot($paramArr);
            echo jsonOut($result);

        }else{
            return $this->View->view('index');
        }
    }

    public function test()
    {
        $rs = JobModel::getUserCdTime("aaa");
//        $paramArr = array("qid6ebe7a65f1eca00b8bc69cc:7SnFjt","qid6ebe7a65f1eca00b8bc69cc:5BrYjv");
//        $rs = ProduceModel::BatchGetProduceInfo($paramArr);
        echo "<pre>";
        print_r($rs);exit;
    }


}