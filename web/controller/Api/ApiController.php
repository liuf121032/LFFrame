<?php
namespace web\controller\Api;

use config\FactoryConfig;
use web\controller\BaseController;
use web\model\WarehouseModel;
use web\model\ApiModel;

class ApiController extends BaseController
{
    /**
     * 查询矿机倍数
     * @param  [string] tvmid
     * @return [json] 
     */
    public function digTimes()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';

            if ($tvmid == '') throw new \Exception('参数错误', 400);

            $qid = getQid($tvmid);
            if($qid == false) throw new \Exception('取用户ID失败002', 403);

            $data = WarehouseModel::getDigTimes($qid);
            if (!$data) $data = 0;

            echo jsonOut(returnArr(200, $data, 'success'));
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

            if ($tvmid == '') throw new \Exception('参数错误', 400);
            
            $this->getPwd();

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

            if ($tvmid == '') throw new \Exception('参数错误', 400);

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


    /*
     * @param tvmid 用户tvmid
     * 查询用户Qid*/
    public function  selectId()
    {
        try{
            $id = isset($_GET["id"]) ? $_GET["id"] : '';

            $type = isset($_GET["type"]) ? $_GET["type"] : '';

            if ($id == '' || $type == '') throw new \Exception('参数错误', 400);
            if (!in_array($type, array('qid', 'tvmid'))) throw new \Exception('参数type错误', 400);

            $this->getPwd();

            if($type == 'qid'){
                $tid = getTvmId($id);
                if (!$tid) throw new \Exception('查询失败', 404);
                $res = array('tvmid' => $tid);
            }elseif($type == 'tvmid'){
                $tid = getQid($id);
                if (!$tid) throw new \Exception('查询失败', 404);
                $res = array('qid' => $tid);
            }else{
                throw new \Exception('type错误', 400);
            }

            echo jsonOut(returnArr(200, $res, 'success'));
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /*
    * @param tvmid 用户tvmid
    * 查询用户仓库*/
    public function  selectCard()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';

            if ($tvmid == '') throw new \Exception('参数错误', 400);

            $this->getPwd();

            $Qid = getQid($tvmid);

            $dataOne = $data_card = WarehouseModel::wareHouseList($Qid, 1);
            $dataTwo = $data_card = WarehouseModel::wareHouseList($Qid, 2);

            $data=array_merge($dataOne,$dataTwo);

            echo jsonOut(returnArr(200, $data, 'success'));
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /*
    * @param tvmid 用户tvmid
    * 修改用户仓库*/
    public function  editUserCard()
    {
        try{
            $tvmid = isset($_GET["tvmid"]) ? $_GET["tvmid"] : '';

            $num = isset($_GET["num"])?$_GET["num"]:'';

            $cid = isset($_GET["cid"])?$_GET["cid"]:'c911';

            if(!is_array(FactoryConfig::propArr($cid))){
                throw new \Exception('cid参数错误', 400);
            }

            $this->getPwd();

            if ($tvmid == '') throw new \Exception('参数错误', 400);

            $Qid = getQid($tvmid);

            $data = $data_card = ApiModel::editUserCard($Qid, $cid, $num);

            echo jsonOut(returnArr(200, $data, 'success'));
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }


    /*
     * @日志操作查询
     * @
     * */
    public function  conOptLog()
    {
        try{
            $command = isset($_GET["command"]) ? $_GET["command"] : 'LLEN';

            $start = isset($_GET["start"]) ? $_GET["start"] : '';

            $end = isset($_GET["end"]) ? $_GET["end"] : '';

            $this->getPwd();

            if ($command != 'LLEN' && ($start == '' || $end == '')) {
                throw new \Exception('start/end参数错误', 400);
            }

            $data  = ApiModel::aConOptLog($command, $start, $end);
            echo jsonOut(returnArr(200, $data, 'success'));

        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }

    /*
    *
    * opt_flow_log添加日志
    */
    public function setOptFlowLog()
    {
        try{
            $log = isset($_GET["log"]) ? $_GET["log"] : '';
            if ($log == '') throw new \Exception('日志不可为空', 400);

            $this->getPwd();

            $data  = ApiModel::setOptFlowLog($log);
            if (!$data) throw new \Exception('日志添加失败', 404);
            echo jsonOut(returnArr(200, '日志添加成功', 'success'));
        } catch (\Exception $e) {
            $msg = $e -> getMessage();
            $code = $e -> getCode();

            echo jsonOut(returnArr($code, '', $msg));
        }
        exit;
    }


    public function getPwd()
    {
        $pwd = isset($_GET["pwd"])?$_GET["pwd"]:'';
        $tvmid = isset($_GET["tvmid"])?$_GET["tvmid"]:'';
        if ($tvmid != '') {
            if (strlen($tvmid) < 25 || strlen($tvmid) >= 30) throw new \Exception('非法tvmid01');
            if (!preg_match("/^[a-z\d]*$/i", $tvmid)) throw new \Exception('非法tvmid02');
        }

        if($pwd != '1234qwer'){
            throw new \Exception('密码错误!', 400);
        }
    }


}
