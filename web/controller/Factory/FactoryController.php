<?php
namespace web\controller\Factory;

use web\controller\BaseController;
use web\model\FactoryModel;
use web\model\GrounddealModel;
use config\FactoryConfig;

/**
 * Created by PhpStorm.
 * User: liufeng
 * Date: 2017/12/27
 * Time: 下午6:32
 */

class FactoryController extends BaseController
{
    /*
    * 建筑科技列表*/
    public function facList()
    {
        try{
            $type = post('type');
            $act = post('act');
            if($type !='1' && $type !='2' && $type !='3' && $type !='4' || $act!='99d52f03dc5a4a88a6d2f70d2deba807'){
                echo jsonOut(returnArr('400', '', 'error'));exit;
            }
            $facList=FactoryConfig::factoryList($type);
            echo jsonOut(returnArr(200, $facList, 'success'));exit;
        }catch(\Exception $e){
            $str=$e->getMessage();
            echo $str;
        }
    }

    /*
     * 添加科技建筑*/
    public function addFactory()
    {
        try {
            $act = post("act");
            $serverId = post('serverId');//服ID
            $regionId = post('regionId');//区ID
            $pId = post('pId');  //地块ID
            $fId = post('fId'); //工厂ID
            $type = post('type');//建筑类型
            $coordX = post('coordX');//
            $coordY = post('coordY');//
            $tvmId = post('tvmId');
            $token = post('token');


            $userInfo = getUserSession($token,$tvmId);
            if(!$userInfo){
                echo jsonout(returnArr('409','','非法请求参数tvmid/token'));exit;
            }else{
                $headimg = $userInfo['headimg'];
                $nickname = $userInfo['nickname'];
            }
            if ($act != '205139edf9a0f59798fd799562cc740a') { //md5('addFactory')
                echo jsonOut(returnArr(401, '', 'act error!'));exit(1);
            }
            if (empty($pId) || empty($fId) || empty($serverId) || empty($regionId) || empty($type) || empty($coordX) || empty($coordY)) {
                echo jsonOut(returnArr(402, '', '参数不正确'));exit(1);
            }
            $Qid = getQid($tvmId);
            if(!$Qid){
                echo jsonOut(returnArr(401,'','tvmid is error'));exit(1);
            }
            $pId_key=$serverId.'_'.$regionId.'_'.$pId;//组合工厂的地区ID号
            $result = FactoryModel::selectFactoryInfo($pId_key);
            if ($result) {
                echo jsonOut(returnArr(403, '', '该地块已被建设'));exit(1);
            }
            $resultData = FactoryModel::addFactory($pId, $fId, $Qid, $type, $serverId, $regionId, $coordX, $coordY);
            echo jsonOut($resultData);exit(1);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            echo jsonOut(returnArr('410', '', $msg));
            exit;
        }
    }

    /*
     * 变卖科技建筑*/
    public function delFactory()
    {
        try {
            $act = post("act");
            $pId = post('pId');  //大楼ID
            $fId = post('fId'); //工厂ID
            $facId = post('facId'); //工厂ID
            $serverId = post('serverId');
            $regionId = post('regionId');
            $tvmId = post('tvmId');
            $token = post('token');

            $userInfo = getUserSession($token,$tvmId);
            if(!$userInfo){
                echo jsonout(returnArr('409','','非法请求参数tvmid/token'));exit;
            }else{
                $headimg = $userInfo['headimg'];
                $nickname = $userInfo['nickname'];
            }

            if ($act != '76a355dd838dd3335564b5303db34eb2') {
                echo jsonOut(returnArr(401, '', 'act error!'));exit(1);
            }
            if (empty($pId) || empty($fId) || empty($facId) || empty($serverId) || empty($regionId)) {
                echo jsonOut(returnArr(402, '', '参数不正确'));exit(1);
            }
            $Qid = getQid($tvmId);
            if(!$Qid){
                echo jsonOut(returnArr(401,'','tvmid is error'));exit(1);
            }
            $resultData = FactoryModel::delFactory($Qid, $pId, $fId, $facId, $serverId, $regionId);
            echo jsonOut($resultData);
            exit;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            echo jsonOut(returnArr('410', '', $msg));
            exit;
        }
    }

    /*
     * 查看地块建筑信息*/
    public function selFactory()
    {

        try {
            $act = post('act');
            $serverId = post('serverId');
            $regionId = post('regionId');
            $pId = post('pId');
            $tvmId = post('tvmId'); //访问用户的tvmId
            $token = post('token');
            $pTvmId = post('pTvmId');//地块用户的tvmId

            $userInfo = getUserSession($token,$tvmId);

            if(!$userInfo){
                echo jsonout(returnArr('409','','非法请求参数tvmid/token'));exit;
            }else{
                $headimg = $userInfo['headimg'];
                $nickname = $userInfo['nickname'];
            }

            if ($act != 'ec1443bb5162fc62134529fe4f4b73cd') {
                echo jsonOut(returnArr(401, '', 'act error!'));
                exit(1);
            }
            if (empty($pId) || empty($serverId)|| empty($regionId) || empty($pTvmId)) {
                echo jsonOut(returnArr(402, '', '参数不正确'));
                exit(1);
            }
            $pId_key=$serverId.'_'.$regionId.'_'.$pId;//组合工厂的地区ID号
            $Qid = getQid($tvmId);
            if (!$Qid) {
                return returnArr(412, '', '用户不存在');
            }
            $pQid = getQid($pTvmId); //地块用户的Qid
            if (!$pQid) {
                return returnArr(413, '', '地块用户不存在');
            }
//            var_dump($Qid,$pId_key);
            $uPlaceData = FactoryModel::userPlace($Qid, $pId_key, 'is_sel');
//            var_dump($uPlaceData);exit;
            if ($uPlaceData) {
                $type = 1; //用户查看自己地块信息
            }else{
                $type = 2; //用户查看别人的地块信息
            }
            $result = FactoryModel::SelFactoryNew($pId_key, $Qid, $type, $pQid);
            $gdataTmp = GrounddealModel::whetherSale($serverId,$regionId,$pId,$pTvmId);
            if($gdataTmp['code']== 200){
                $result['data']['is_method'] = $gdataTmp['data']['method']; //1出售,2拍卖中。
//                $result['data']['is_sale'] = 1; //出售中
//                $result['data']['auction'] = 1; //拍卖中
                $result['data']['is_sale_coin'] = $gdataTmp['data']['coin']; //出售价格
                $result['data']['is_sale_orderID'] = $gdataTmp['data']['orderId']; //出售订单号
            }else{
                $result['data']['is_sale'] = 2; //未出售
            }
            echo jsonOut($result);
            exit(1);
        } catch (\Exception $e) {
            $eData = array('status'=>$e->getCode(),'msg'=>$e->getMessage());
//            mLogs('Factory','selFactory',$eData);
            echo jsonOut(returnArr(400, '', 'info error!'));
        }
    }

    /*
     * 科技升级*/
    public function upFactory()
    {
        try {
            $act = post("act");
            $serverId = post('serverId');
            $regionId = post('regionId');
            $pId = post('pId');      //大楼ID
            $fId = post('fId');      //工厂ID
            $type = post('type');     //工厂类型
            $grade = post('grade');  //工厂等级
            $facId = post('facId'); //工厂facId
            $tvmId = post('tvmId');
            $token = post('token');

            $userInfo = getUserSession($token,$tvmId);
            if(!$userInfo){
                echo jsonout(returnArr('409','','非法请求参数tvmid/token'));exit;
            }else{
                $headimg = $userInfo['headimg'];
                $nickname = $userInfo['nickname'];
            }

            if ($act != 'd8145a271292f91b0b01fb1aeed45a54') {
                echo jsonOut(returnArr(401, '', 'act error!'));
                exit(1);
            }
            if (empty($pId) || empty($fId) || empty($type) || empty($grade) || empty($facId) || empty($serverId) || empty($regionId)) {
                echo jsonOut(returnArr(402, '', '参数不正确'));
                exit(1);

            }
            if(empty($pId)||empty($fId) || empty($type) || empty($grade) || empty($facId)) {
                echo jsonOut(returnArr(402,'','参数不正确'));exit(1);
            }
            $Qid = getQid($tvmId);
            if(!$Qid){
                echo jsonOut(returnArr(401,'','tvmid is error'));exit(1);
            }
            $resultData = FactoryModel::upFactory($Qid, $pId, $fId, $type, $grade, $facId ,$serverId, $regionId);
            echo jsonOut($resultData);
            exit;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            echo jsonOut(returnArr(410, '', $msg));
            exit;
        }
    }


    public function test()
    {
        FactoryModel::userSelFacCenter('qidda35a202aa198989a6c0a36',4);
    }

}
