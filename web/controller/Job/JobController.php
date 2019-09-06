<?php
namespace web\controller\Job;
/**
 * Created by PhpStorm.
 * User: root
 * Date: 18-1-18
 * Time: 下午3:21
 */

use web\controller\BaseController;
use web\model\JobModel;

class JobController extends BaseController
{
    const VERIFY_USER = true;

    /**
     * 取全服的打工列表
     * @param $server 服ID
     * @param $page [int] 第几页
     * @param $perpage [int] 每页数据数
     * @param $sort [string] asc|desc
     * @return json {"code":200,"data":{...},"totalrows":123,"msg":""}
     */
    public function getJobList()
    {
        //ini_set("display_errors",1);
        //TODO::服ID安全问题，防止修改跨服打工
        $server = post("server");
        $page = (int)post("page");
        $perPage = (int)post("perPage");
        $sort = post("sort");

        if(empty($server)){
            echo  jsonOut(returnArr(400,"","请求参数错误"));exit;
        }

        if(empty($sort) || ($sort != "asc" && $sort != "desc")){
            $sort = "desc";
        }

        $page = $page < 1 ? 1 : $page;
        $perPage = $perPage < 1 ? 10 : $perPage;

        $paramArr = array(
            "server" => $server,
            "page" => $page,
            "perPage" => $perPage,
            "sort" => $sort,
        );

        $result = JobModel::getServerWorkLists($paramArr);
        echo jsonOut($result);exit;

    }
    public function getJobListTest()
    {
        //ini_set("display_errors",1);
        $server = get("server");
        $page = (int)get("page");
        $perPage = (int)get("perPage");
        $sort = get("sort");

        if(empty($server)){
            echo  jsonOut(returnArr(400,"","请求参数错误"));exit;
        }

        if(empty($sort) || ($sort != "asc" && $sort != "desc")){
            $sort = "desc";
        }

        $page = $page < 1 ? 1 : $page;
        $perPage = $perPage < 1 ? 10 : $perPage;

        $paramArr = array(
            "server" => $server,
            "page" => $page,
            "perPage" => $perPage,
            "sort" => $sort,
        );

        $result = JobModel::getServerWorkLists($paramArr);
        echo jsonOut($result);exit;


    }

    /**
     * 获取本服某个用户的工厂的打工列表
     * @param server 服ID
     * @param redId 用户的红包ID
     * @param sort 排序(以工资排序) asc|desc  默认：desc
     * @return Json
     */
    public function getJobListByUser()
    {
        //ini_set("display_errors",1);
        $server = post("server");
        $redId = post("redId");
        $sort = post("sort");
        if(empty($server)){
            $server = get("server");
        }
        if(empty($redId)){
            $redId = get("redId");
        }
        if(empty($sort)){
            $sort = get("sort");
        }

        if(
            empty($server) ||
            empty($redId)
        ){
            echo jsonOut(returnArr(403,"","请求参数错误"));exit;
        }

        $redId = ucfirst($redId);
        $t = substr($redId,0,1);
        if($t != "A"){
            $redId = "A".$redId;
        }

        $paramArr = array(
            "server" => $server,
            "redId" => $redId,
            "sort" => $sort,
        );

        $rs = JobModel::getUsersWorkLists($paramArr);

        echo jsonOut($rs);exit;

    }

    //用户打工
    /**
     * @param tvmid  用户id
     * @param server 服ID
     * @param region 区
     * @param pId 地块ID
     * @param facId 工厂id
     * @param employerTvmid 工厂所有者id
     * @param token
     * @param sign 签名
     * @param adToken 打工前看视频的token
     * @param timestamp 秒
     */
    public function doWork()
    {
        $tvmId = post("tvmId");
        $server = post("server");
        $region = post("region");
        $pId = post("pId");
        $facId = post("facId");
        $employerTvmid = post("employerTvmid");
        $sign = post("sign");
        $token = post("token");
        $adToken = post("adToken");
        $timestamp = post("timestamp");
        $guid = post("guid");
        $vcode_t = post("t");
        $vcode_v = post("v");
        if(empty($timestamp)){
            $timestamp = 0;
        }

        $nTime = getMillisecond();

        if (
            empty($tvmId) ||
            empty($server) ||
            empty($region) ||
            empty($pId) ||
            empty($facId) ||
            empty($token) ||
            empty($adToken) ||
            empty($sign) ||
            empty($employerTvmid) ||
            empty($guid)
        ) {
            echo jsonOut(returnArr(400,"","请求参数错误"));exit;
        }


        $employerQid = getQid($employerTvmid);
        if($employerQid == false){
            echo jsonout(returnArr(403, '', '取用户ID失败002'));exit;
        }

        //签名验证
        $signArr = array(
            "employerQid" => $employerQid,
            "server" => $server,
            "region" => $region,
            "pId" => $pId,
            "facId" => $facId,
        );
        if($sign != makeSign($signArr)){
            echo jsonOut(returnArr(403,"","签名错误"));exit;
        }

        //广告签名验证
        $adtArr = array(
            "tvmId" => $tvmId,
            "timestamp" => $timestamp,
            "adToken" => $adToken,
        );
        $lockAdToken =  ckAdToken($adtArr);
        if(!$lockAdToken["result"]){
            mLogs("error","fDowork",$lockAdToken,"ad");
            echo jsonOut(returnArr(403,"","AD签名错误"));exit(1);
        }
//        if(!$lockAdToken || $adToken != $lockAdToken){
//            echo jsonOut(returnArr(403,"","AD签名错误"));exit(1);
//        }

        $headImg = "unknown";
        $nickName = "unknown";
        if(self::VERIFY_USER) {
            $userInfo = getUserSession($token, $tvmId);
            if (!$userInfo) {
                echo jsonout(returnArr(403, '', '用户验证失败'));exit(1);
            } else {
                $headImg = $userInfo['headimg'];
                $nickName = $userInfo['nickname'];
            }
        }

        //获取qid
        $qId = getQid($tvmId);
        if($qId == false){
            echo jsonout(returnArr(403, '', '取用户ID失败'));exit;
        }

        $paramArr = array(
            "qId" => $qId,
            "tvmId" => $tvmId,
            "server" => $server,
            "region" => $region,
            "pId" => $pId,
            "facId" => $facId,
            "employerQid" => $employerQid,
            "employerTvmid"=>$employerTvmid,
            "nickName" => $nickName,
            "headImg" => $headImg,
            "guid" => $guid,
            "vcode_t" => $vcode_t,
            "vcode_v" => $vcode_v,
        );
        $result = JobModel::fDowork($paramArr);
        echo jsonOut($result);exit;
    }

    public function test(){
        $a = "1518161318345";
        $nt = getMillisecond();
        $adtArr = array(
            "tvmId" => "sss",
            "timestamp" => $a
        );
        $sr = getAdToken($adtArr);
        if(!$sr){
            echo "expired";exit;
        }

        echo "ok";exit;

        //用户红包id
        $tvmid = "wxh58674c6058cafe18cc78c477";
        $qId = getQid($tvmid);

        //用户红包id
        $redId = getRedId($qId) ?: getUserRedId($tvmid);

        pre($redId);exit;
    }


    public function getWorkLogByTvmid(){
        $tvmid = get("tvmid");
        if(empty($tvmid)){
            echo jsonOut(returnArr(403,"","tvmid err"));exit;
        }

        $qid = getQid($tvmid);
        if($qid == false){
            echo jsonout(returnArr(403, '', '取用户ID失败'));exit;
        }

        $result = JobModel::fGetWorkerLog($qid);
        echo jsonOut($result);exit;

    }

    public function getUserFacByTvmid(){
        $tvmid = get("tvmid");
        if(empty($tvmid)){
            echo jsonOut(returnArr(403,"","tvmid err"));exit;
        }

        $qid = getQid($tvmid);
        if($qid == false){
            echo jsonout(returnArr(403, '', '取用户ID失败'));exit;
        }

        $result = JobModel::fGetUserFacs($qid);
        echo jsonOut($result);exit;
    }

    public function getFacWorkers(){
        $tvmid = get("tvmid");
        $facid = get("facid");
        if(empty($tvmid) || empty($facid)){
            echo jsonOut(returnArr(403,"","params err"));exit;
        }

        $qid = getQid($tvmid);
        if($qid == false){
            echo jsonout(returnArr(403, '', '取用户ID失败'));exit;
        }

        $paramsArr = array(
            "qid" => $qid,
            "facid" => $facid
        );
        $result = JobModel::fGetFacWorkers($paramsArr);
        echo jsonOut($result);exit;
    }

    public function tsCode(){
        $t = post("t");
        $v = post("v");
        if(!empty($t) && !empty($v))
        {
            $rs = ck_verify_code($t,$v);
            pre($rs);exit;

        }else{
            //JobModel::test();
            return $this->View->view('tsCode');
        }

    }
}