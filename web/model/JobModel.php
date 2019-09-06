<?php
namespace web\model;
/**
 * Created by PhpStorm.
 * User: root
 * Date: 18-1-18
 * Time: 下午4:04
 */

use config\FactoryConfig;
use config\GroundConfig;
use config\Tab;
use config\ProduceConfig;
use database\MyRedis;

class JobModel extends BaseModel
{

    /**
     * 取当前服的打工列表
     */
    public static function getServerWorkLists($paramArr)
    {
        try
        {
            //$r=MyRedis::getConnect();
            $r = MyRedis::getConnectRead();
            if(!$r->ping()){
                return returnArr(502,"","访问超时，请重试");
            }

            $resKey = ProduceConfig::SERVER_PRODUCE_LIST_PREFIX.$paramArr["server"];
            $totalRows = $r->zCard($resKey);
            if($totalRows == 0){
                $r->close();
                return returnArr(404,"","没有招工信息");
            }

            $page = $paramArr["page"];
            $perPage = $paramArr["perPage"];
            $start = ($page - 1) * $perPage;
            $end = ($start + $perPage) - 1;
            $sort = empty($paramArr["sort"]) ? "desc" : $paramArr["sort"];

            //新排序规则为 (100-salery).time() 所以redis查询时相反
            if($sort == "asc"){
                $idList = $r->zRevRange($resKey,$start,$end);
            }else{
                $idList = $r->zRange($resKey,$start,$end);
            }
            if(empty($idList)){
                $r->close();
                return returnArr(404,"","没有更多");
            }

            $res = array(
                "totalRows" => $totalRows,
                "totalPage" => ceil($totalRows/$perPage),
            );

            $r->multi(\Redis::PIPELINE);
            foreach($idList as $v){
                $r->hGetAll($v);
            }
            $lists = $r->exec();
            $r->close();

            if(empty($lists)){
                return returnArr(404,"","没有招工信息");
            }

            $pidArr = array();
            foreach ($lists as $k=>&$v){
                if(!empty($v)) {
                    $tmpsg = array(
                        "employerQid" => @$v["qId"],
                        "server" => @$v["server"],
                        "region" => @$v["region"],
                        "pId" => @$v["pId"],
                        "facId" => @$v["facId"],
                    );
                    $v["sign"] = makeSign($tmpsg);
                    $serverName = GroundConfig::serviceName(@$v["server"]);
                    if (empty($serverName)) {
                        $serverName = "";
                    }
                    $regionName = GroundConfig::regionName($v["server"],$v["region"]);
                    $v["serverName"] = $serverName;
                    $v["regionName"] = $regionName;

                    //用户红包ID
                    if(empty($v["redId"])){
                        $redId = getRedId($v["qId"]) ?: getUserRedId($v["tvmId"]);
                        if(!$redId){
                            $redId = "";
                        }
                        $v["redId"] = $redId;
                    }

                    $tmpPid = $v["server"] . "_" . $v["region"] . "_" . $v["pId"];
                    array_push($pidArr, $tmpPid);

                    unset($v["token"]);
                    unset($v["action"]);
                    unset($v["guid"]);
                    unset($v["qId"]);
                }
            }

            //取工厂信息
            $facInfo = FactoryModel::produceSelFactory($pidArr);
            if(!empty($facInfo)){
                foreach ($lists as &$lv){
                    if(!empty($lv)) {
                        $tmpPid = $lv["server"] . "_" . $lv["region"] . "_" . $lv["pId"];
                        $tmpInfo = array(
                            "name" => @$facInfo[$tmpPid]["name"],
                            "grade" => @$facInfo[$tmpPid]["grade"],
                            "facImg" => @$facInfo[$tmpPid]["imageName"],
                            "propImg" => @$facInfo[$tmpPid]["propId"]["img"],
                            "latitude" => @$facInfo[$tmpPid]["coordX"],
                            "longitude" => @$facInfo[$tmpPid]["coordY"],
                        );
                        $lv["facInfo"] = $tmpInfo;
                    }
                }
            }

            $res["lists"] = $lists;

            return returnArr(200,$res,"success");


        }catch (\Exception $e){
            return returnArr(403,"","获取列表失败001");
        }
    }

    /**
     * 取某个用户的工厂的招工信息
     */
    public static function getUsersWorkLists($paramArr=array())
    {
        if(empty($paramArr["server"]) || empty($paramArr["redId"])){
            return returnArr(403,"","参数错误");
        }

        //获取用户的tvmid，大仙儿接口
        $tvmId = getTvmidByRedid($paramArr["redId"]);

        if($tvmId){
            $qId = getQid($tvmId);

            $redId = ltrim($paramArr["redId"],"A");

            //查询用户的工厂
            $facLists = FactoryModel::userSelFacCenter($qId,1);
            if(empty($facLists)){
                return returnArr(404,"","该用户没有工厂");
            }

            //取在打工状态的工厂
            $activeFac = array();
            $produceIds = array();
            foreach ($facLists as $fac){
                if(!empty($fac["produceOrder"]) && $fac["serverId"] == $paramArr["server"]){
                    $activeFac[$fac["facId"]] = $fac;
                    $productId = ProduceConfig::PRODUCE_PREFIX.$qId.":".$fac["facId"];
                    $produceIds[] = $productId;
                }
            }
            if(empty($activeFac)){
                return returnArr(404,"","用户没有发布招工");
            }

            //取工厂的生产信息
            try{
                //$r = MyRedis::getConnect();
                $r = MyRedis::getConnectRead();
                if(!$r->ping()){
                    return returnArr(502,"","请求超时,请重试");
                }

                $r->multi(\Redis::PIPELINE);
                foreach($produceIds as $v){
                    $r->hGetAll($v);
                }
                $lists = $r->exec();
                $r->close();

                if(empty($lists)){
                    return returnArr(404,"","用户没有发布招工(0024)");
                }

                foreach ($lists as &$lv){
                    if(!empty($lv)) {
                        $tmpsg = array(
                            "employerQid" => @$lv["qId"],
                            "server" => @$lv["server"],
                            "region" => @$lv["region"],
                            "pId" => @$lv["pId"],
                            "facId" => @$lv["facId"],
                        );
                        $lv["sign"] = makeSign($tmpsg);
                        $serverName = GroundConfig::serviceName(@$lv["server"]);
                        if (empty($serverName)) {
                            $serverName = "";
                        }
                        $regionName = GroundConfig::regionName($lv["server"],$lv["region"]);
                        $lv["serverName"] = $serverName;
                        $lv["regionName"] = $regionName;

                        if(empty($lv["redId"])){
                            $lv["redId"] = $redId;
                        }

                        $tmpInfo = array(
                            "name" => @$activeFac[$lv["facId"]]["name"],
                            "grade" => @$activeFac[$lv["facId"]]["grade"],
                            "facImg" => @$activeFac[$lv["facId"]]["imageName"],
                            "propImg" => @$activeFac[$lv["facId"]]["propId"]["img"],
                            "latitude" => @$activeFac[$lv["facId"]]["coordX"],
                            "longitude" => @$activeFac[$lv["facId"]]["coordY"],
                        );
                        $lv["facInfo"] = $tmpInfo;

                        unset($lv["token"]);
                        unset($lv["action"]);
                        unset($lv["guid"]);
                        unset($lv["qId"]);
                    }
                }

                //按工资排序
                $saleryArr = array();
                foreach($lists as $tv){
                    $saleryArr[] = $tv["salery"];
                }
                if(!empty($paramArr["sort"]) && $paramArr["sort"] == "asc"){
                    array_multisort($saleryArr,SORT_ASC,$lists);
                }else{
                    array_multisort($saleryArr,SORT_DESC,$lists);
                }

                $res = array(
                    "lists" => $lists,
                );
                return returnArr(200,$res,"success");

            }catch (\Exception $e){

                return returnArr(502,"","请求超时,请重试(0025)");
            }
        }else{
           $msg = "无效的红包ID";
            return returnArr(404, "", $msg);
        }
    }


    /**
     * 用户打工
     * @param $paramArr
     * @return array
     */
    public static function fDowork($paramArr)
    {

        try{
            $r=MyRedis::getConnect();
            if(!$r->ping()){
                return returnArr(502,"","访问超时，请重试");
            }

            //黑名单检查
            $isBUser = $r->hExists("workerBlackList",$paramArr["qId"]);
            if($isBUser){
                $r->close();
                return returnArr("403","","账号异常");
            }

            //检查用户自己的工作冷却时间
            $userWorkCdKey = ProduceConfig::WORKER_CD_STATUS . $paramArr["qId"];
            if($r->exists($userWorkCdKey)){
                $r->close();
                return returnArr(403,"","休息一会儿再工作吧");
            }

            //检查用户的打工次数
            $todayStr = date("Y-m-d",time());
            $workTimes = $r->hGet(ProduceConfig::WORKER_DOWORK_DAY.$todayStr,$paramArr["qId"]);
            if($workTimes >= ProduceConfig::WORK_LIMIT_NUMS){
                $r->close();
                return returnArr(403,"","今日打工次数己到上限");
            }

            //检查是否需要校验验证码
            $doworkVerifyCodeKey = ProduceConfig::WORKER_VERIFY_CODE.$paramArr["qId"];
            $useVerifyCode = $r->exists($doworkVerifyCodeKey);
            if(!$useVerifyCode){
                if(empty($paramArr["vcode_t"]) || empty($paramArr["vcode_v"])){
                    $r->close();
                    return returnArr(405,"","验证码校验失败(1)");
                }

                $vfCodeRs = ck_verify_code($paramArr["vcode_t"],$paramArr["vcode_v"]);
                if(!empty($vfCodeRs["code"]) && $vfCodeRs["code"] == 200){
                    //校验成功,1小时内不需要重复校验
                    $r->set($doworkVerifyCodeKey,1,["NX","EX"=>3600]);
                }else{
                    $r->close();
                    return returnArr(405,"","验证码校验失败(2)");
                }
            }

            //获取锁
            $lockKey = "lock_".$paramArr["employerQid"] . ":" . $paramArr["facId"];
            $isGetLock = false;
            for($i = 0; $i < ProduceConfig::RETRY_TIMES; $i++){
                $isGetLock = $r->set($lockKey,1,["NX","PX"=>600]);
                if($isGetLock){
                    break;
                }
                usleep(ProduceConfig::RETRY_CD);
            }
            if(!$isGetLock){
                $r->close();
                return returnArr(502,"","没有竞争到工位，请重试");
            }

            //判断工厂是否在撤单中。。
            $lockCancelProduceKey = "lock_cancel_produce_".$paramArr["employerQid"] . ":" . $paramArr["facId"];
            $cancelIng = $r->exists($lockCancelProduceKey);
            if($cancelIng){
                return returnArr(403,"","工厂撤单中");
            }

            //检查此工厂的生产状态
            $rdsKey = ProduceConfig::PRODUCE_PREFIX.$paramArr["employerQid"] . ":" . $paramArr["facId"];

            $produceInfo = $r->hGetAll($rdsKey);

            if($produceInfo && $produceInfo["complete"] == 0){   //complete表示工厂是否招工完成

                //订单有效性判断
                if($paramArr["guid"] != $produceInfo["guid"]){
                    $r->del($lockKey);
                    $r->close();
                    return returnArr(402,"","生产订单已失效");
                }

                $workersKey = ProduceConfig::WORKERS_PREFIX.$produceInfo["guid"] . ":" . $paramArr["facId"];
                $uInfo = array(
                    "tvmId" => $paramArr["tvmId"],
                    "nickName" => $paramArr["nickName"],
                    "headImg" => $paramArr["headImg"],
                    "salery" => $produceInfo["salery"],
                    "createTime" => time(),
                );
                $paramArr['action']="userDoWork";
                $paramArr["guid"] = $produceInfo["guid"];

                $r->multi(\Redis::PIPELINE);

                //记录来工作的人员
                $adduser = $r->hSet($workersKey,keyGen(),json_encode($uInfo,JSON_UNESCAPED_UNICODE));
                //增加来工作的人数
                $addEpleeNum = $r->hIncrBy($rdsKey,"eplee",1);
                //redis队列 用于入库
                //$setToQueue = $r->lPush(ProduceConfig::PRODUCE_QUEUE_KEY,json_encode($paramArr,JSON_UNESCAPED_UNICODE));
                if((int)$produceInfo["workerNums"] == (int)$produceInfo["eplee"] + 1 || (int)$produceInfo["eplee"] >= (int)$produceInfo["workerNums"]){
                    //人数已满 工厂生产任务完成
                    $workDone = $r->hSet($rdsKey,"complete",1);
                }

                $rs = $r->exec();

                if($rs[0] != 1){
                    //释放锁
                    $r->del($lockKey);
                    $r->close();
                    return returnArr(403,"","打工失败(0026)");//test
                }

                //最新工厂生产信息
                $workInfo = $r->hGetAll($rdsKey);

                ////////////////////////////////////
                ///// 生产的原材料分批次完成 strart ///
                ///////////////////////////////////

                $personForOne = ceil((int)$produceInfo["workerNums"] / $produceInfo["productNums"]);

                if(((int)$workInfo["eplee"] % $personForOne) == 0 || (int)$workInfo["eplee"] == (int)$produceInfo["workerNums"]){

                    //给用户仓库仓库增加材料
                    //T调用张鹏的方法
                    $storageArr = array(
                        "qid" => $paramArr["employerQid"],
                        "cid" => $produceInfo["productId"],
                        "amount" => 1,
                        "service" => $paramArr["server"],
                    );

                    $storageRs = WarehouseModel::storage($storageArr);
                    if(!$storageRs){
                        $errInfo = array(
                            "errMsg" => "addToStoreErr",
                            "Tvmid" => $paramArr["employerTvmid"],
                            "productId" => $produceInfo["productId"],
                            "productNums" => 1,
                        );
                        mLogs("error","fDowork",$errInfo,ProduceConfig::PRODUCE_LOG_FILE);
                    }else{
                    }


                    //生产完成日志
                    $leftNum = $r->hGet(TAB::userCard.$paramArr["employerQid"],$produceInfo["productId"]);
                    if(empty($leftNum)){
                        $leftNum = 0;
                    }

                    $ntime = getMillisecond();
                    $queueStr = "20\t".$produceInfo["guid"] . "\t" .
                        $paramArr["employerQid"] . "\t" .
                        ltrim($produceInfo["productId"],"c") . "\t" .
                        1 . "\t" .
                        $leftNum . "\t" .
                        $ntime;
                    $logRs = opt_log_for_produce($queueStr);

                    //行为日志
                    $lStr = "1\t".$paramArr["employerQid"] . "\t" .
                        $produceInfo["facId"] . "\t" .
                        ltrim($produceInfo["productId"],"c") . "\t" .
                        1 . "\t" .
                        $leftNum . "\t" .
                        $produceInfo["guid"] . "\t" .
                        $ntime;
                    $logRs = optFlowLog($lStr);

                }
                /////////////// end ///////////////

                //判断是否是给自己打工
                $logSalery = $produceInfo["salery"];
                if($paramArr["qId"] != $paramArr["employerQid"]) {
                    //用户打工完成后给用户加金币
                    //$plusRs = UserCoinCore("plus", $paramArr["tvmId"], $produceInfo["salery"], "用户打工工资");
                    $plusRs = tradeMainToMain($paramArr["tvmId"],$produceInfo["salery"],"用户打工工资","plus");
                    if ($plusRs["code"] != 200) {
                        $plusRs["errMsg"] = "plusUserCoinErr";
                        $plusRs["tvmId"] = $paramArr["tvmId"];
                        $plusRs["amount"] = $produceInfo["salery"];
                        mLogs("error", "fDowork", $plusRs, ProduceConfig::PRODUCE_LOG_FILE);
                    }

                }else{
                    $logSalery = 0;
                }

                //给用户记录打工的工资记录
                $fKey = date("Y-m-d",time());
                //$r->hIncrBy(ProduceConfig::WORKER_SALERY_LOG_BY_DAY.$paramArr["qId"],$fKey,$produceInfo["salery"]);
                //$r->hIncrBy(ProduceConfig::WORKER_SALERY_LOG_BY_PRODUCT.$paramArr["qId"],$produceInfo["productId"],$produceInfo["salery"]);
                $tmpPid = $produceInfo["server"]."_".$produceInfo["region"]."_".$produceInfo["pId"];
                $tmpFacInfo = FactoryModel::userSelFactory($tmpPid,$paramArr["employerQid"]);
                $facName = "";
                if(!empty($tmpFacInfo)){
                    $facName = @$tmpFacInfo["name"];
                }
                $workLog = array(
                    "employerName" => $produceInfo["nickName"],
                    "facName" => $facName,
                    "time" => time(),
                    "salery" => $logSalery,
                );
                $millTime = getMillisecond();
                $logJson = json_encode($workLog,JSON_UNESCAPED_UNICODE);
                $r->zAdd(ProduceConfig::WORKER_DOWORK_LOG.$paramArr["qId"],$millTime,$logJson);

                //用户的打工记录，只记录三天的
                $doWorkLogNums = $r->zCard(ProduceConfig::WORKER_DOWORK_LOG.$paramArr["qId"]);
                //用户每天打工上限是500  3天就是1500条数据， 超过数量将之前的数据删除
                $logLimit = ProduceConfig::WORK_LIMIT_NUMS * 3;
                if($doWorkLogNums > $logLimit){
                    $a = time() - (3600 * 24 * 3);
                    $b = date("Y-m-d",$a);
                    $b = $b." 23:59:59";
                    $c = strtotime($b)."999";
                    $r->zRemRangeByScore(ProduceConfig::WORKER_DOWORK_LOG.$paramArr["qId"],0,(int)$c);
                }

                //给用户加经验
                //加个人经验
                $perex = FactoryConfig::userExLable(2);
                $personalEx = CenterModel::addEx($paramArr["qId"],$perex,1);
                //加打工经验
                $proex = FactoryConfig::workExLable();
                $operateEx = CenterModel::addEx($paramArr["qId"],$proex,2);

                //重置用户的打工CD时间
                $r->set($userWorkCdKey,1,["NX","EX"=>ProduceConfig::WORK_CD_TIME]);

                //加用户打工次数
                $r->hIncrBy(ProduceConfig::WORKER_DOWORK_DAY.$todayStr,$paramArr["qId"],1);

                //用户打工成功后写打工日志
                $ntime = getMillisecond();
                $queueStr = "18\t".$produceInfo["guid"] . "\t" .
                    $paramArr["qId"] . "\t" .
                    $ntime . "\t" .
                    "126" . "\t" .
                    $logSalery;
                $logRs = opt_log_for_produce($queueStr);

                //行为日志
                $behaviorStr = "201\t".$produceInfo["guid"] . "\t" .
                    $paramArr["qId"] . "\t" .
                    $ntime . "\t" .
                    "126" . "\t" .
                    $logSalery . "\t" .
                    ltrim($produceInfo["productId"],"c");
                $bLogRs = optFlowLog($behaviorStr);


                //判断工厂是否满足完成生产的条件
                if($workInfo["complete"] == 1){

                    $r->multi(\Redis::PIPELINE);
                    //删除工厂生产信息
                    $r->del($rdsKey);
                    //删除工厂的工人打工信息
                    $r->del($workersKey);
                    //从招工中心列表中删除
                    $r->zRem(ProduceConfig::SERVER_PRODUCE_LIST_PREFIX.$paramArr["server"],$rdsKey);
                   $remRs = $r->exec();
                    if($remRs[0] != 1){
                        mLogs("error","fDowork",array("工厂生产完成 删除生产信息失败redisKey:".$rdsKey),ProduceConfig::PRODUCE_LOG_FILE);
                    }

                    //TODO:订单完成给用户提送消息
                    $cardName = FactoryConfig::propArr($produceInfo["productId"])['name'];
                    $cardTvmId = getTvmId($paramArr["employerQid"]); //订单所有者的tvmID
//                    太棒啦！您在原木厂（经..纬…）时间（10:22）生产的10个木材，现在全部生产完成啦！快去看看吧！

                    $message = '太棒啦！您在'.$tmpFacInfo['name'].'（经'.$tmpFacInfo['coordY'].'纬'.$tmpFacInfo['coordX'].'）时间（'.date('H:i:s',$produceInfo['createTime']).'）生产的'.
                        $produceInfo["productNums"].'个'.$cardName.'，现在全部生产完成啦！<a href="https://a-h5.mtq.tvm.cn/game/storehouse.html">快去看看吧！</a>';

                    $Msgdata = pushMsgDSHB($cardTvmId,$message);


                    /*
                    //生产完成日志
                    $leftNum = $r->hGet(TAB::userCard.$paramArr["employerQid"],$produceInfo["productId"]);
                    if(empty($leftNum)){
                        $leftNum = 0;
                    }
                    $leftNum = ($leftNum + $produceInfo["productNums"]);

                    $ntime = getMillisecond();
                    $queueStr = "20\t".$produceInfo["guid"] . "\t" .
                        $paramArr["employerQid"] . "\t" .
                        ltrim($produceInfo["productId"],"c") . "\t" .
                        $produceInfo["productNums"] . "\t" .
                        $leftNum . "\t" .
                        $ntime;
                    $logRs = opt_log_for_produce($queueStr);

                    //给用户仓库仓库增加材料 增加经验
                    //T调用张鹏的方法
                    $storageArr = array(
                        "qid" => $paramArr["employerQid"],
                        "cid" => $produceInfo["productId"],
                        "amount" => $produceInfo["productNums"],
                        "service" => $paramArr["server"],
                    );
                    $storageRs = WarehouseModel::storage($storageArr);
                    if(!$storageRs){
                        $errInfo = array(
                            "errMsg" => "addToStoreErr",
                            "Tvmid" => $paramArr["employerTvmid"],
                            "productId" => $produceInfo["productId"],
                            "productNums" => $produceInfo["productNums"],
                        );
                        mLogs("error","fDowork",$errInfo,ProduceConfig::PRODUCE_LOG_FILE);
                    }
                    */
                }

                //释放锁
                $r->del($lockKey);
                $r->close();

                return returnArr(200,"","success");

            }else{
                $r->del($lockKey);
                $r->close();
                //在没有打工信息将用户信息返回，用于前端两个人竞争最后一个工位，竞争失败方的显示
                $uData = array(
                    "nickName" => $paramArr["nickName"],
                    "headImg" => $paramArr["headImg"],
                );
                return returnArr(404,$uData,"没有招工信息");
            }
        }catch (\Exception $e){
            return returnArr(403,"","打工失败");
        }

    }

    /**
     * 获取用户的打工的冷却时间
     * @param string qid  用户ID
     * @return array  array("code"=>200,"data"=>123,"msg"=>"success")
     *                                  data的值为冷却剩余时间 单位：秒
     *                                  如果data为0 说明用户冷却时间已过 可以开始新的打工
     */
    public static function getUserCdTime($qid = null)
    {
        if(empty($qid)){
            return returnArr(404,"","qid is null");
        }
        try{
            //$r = MyRedis::getConnect();
            $r = MyRedis::getConnectRead();
            if(!$r->ping()){
                return returnArr(502,"","connect to redis err: timeout");
            }

            //检查用户自己的工作冷却时间
            $userWorkCdKey = ProduceConfig::WORKER_CD_STATUS . $qid;
            $exTime = $r->ttl($userWorkCdKey);
            $exTime = $exTime < 0 ? 0 : $exTime;

            $r->close();
            return returnArr(200,$exTime,"success");

        }catch (\Exception $e) {
            return returnArr(403,"",$e->getMessage());
        }
    }

    /**
     * 获取用户当天的打工剩余次数
     * @param string qId  用户ID
     * @return array   array("code"=>200,"data"=>array("limit"=>1000,"done"=>23),"msg"=>"success)
     *                 limit: 打工上限   done: 己打工次数
     */
    public static  function getUserDoworkTimes($qId = null){
        if(empty($qId)){
            return returnArr(404,"","qid is null");
        }
        try{
            //$r = MyRedis::getConnect();
            $r = MyRedis::getConnectRead();
            if(!$r->ping()){
                return returnArr(502,"","connect to redis err: timeout");
            }

            //检查用户的打工次数
            $todayStr = date("Y-m-d",time());
            $workTimes = $r->hGet(ProduceConfig::WORKER_DOWORK_DAY.$todayStr,$qId);
            if(!$workTimes){
                $workTimes = 0;
            }
            $r->close();

            $workStat = array(
                "limit" => ProduceConfig::WORK_LIMIT_NUMS,
                "done" => $workTimes,
            );

            return returnArr(200,$workStat,"success");

        }catch (\Exception $e) {
            return returnArr(403,"",$e->getMessage());
        }
    }


    public static function fGetWorkerLog($qid){
        if(empty($qid)){
            return returnArr(404,"","qid is null");
        }
        try{
            //$r = MyRedis::getConnect();
            $r = MyRedis::getConnectRead();
            if(!$r->ping()){
                return returnArr(502,"","connect to redis err: timeout");
            }

            $rdsKey = ProduceConfig::WORKER_DOWORK_LOG.$qid;
            $data = $r->zRevRange($rdsKey,0,-1);
            if(!empty($data)){
                foreach ($data as &$v){
                    $tmp = json_decode($v,true);
                    $tmp["time"] = date("Y-m-d H:i:s",@$tmp["time"]);
                    $v = json_encode($tmp,JSON_UNESCAPED_UNICODE);
                }
            }

            $r->close();

            return returnArr(200,$data,"success");

        }catch (\Exception $e) {
            return returnArr(403,"",$e->getMessage());
        }
    }

    public static function fGetUserFacs($qid){
        if(empty($qid)){
            return returnArr(404,"","qid is null");
        }
        try{
            //$r = MyRedis::getConnect();
            $r = MyRedis::getConnectRead();
            if(!$r->ping()){
                return returnArr(502,"","connect to redis err: timeout");
            }

            $tmpList = FactoryModel::userSelFacCenter($qid,1);

            $r->close();

            return returnArr(200,$tmpList,"success");

        }catch (\Exception $e) {
            return returnArr(403,"",$e->getMessage());
        }
    }

    public static function fGetFacWorkers($paramArr = array()){
        try{
            //$r = MyRedis::getConnect();
            $r = MyRedis::getConnectRead();
            if(!$r->ping()){
                return returnArr(502,"","connect to redis err: timeout");
            }

            $rdsKey = ProduceConfig::PRODUCE_PREFIX.$paramArr["qid"].":".$paramArr["facid"];
            $facInfo = $r->hGetAll($rdsKey);
            if(empty($facInfo)){
                return returnArr(404,"","工厂没有生产信息");
            }

            $guid = $facInfo["guid"];

            $workersKey = ProduceConfig::WORKERS_PREFIX.$guid . ":" . $paramArr["facid"];

            $rs = $r->hGetAll($workersKey);
            if(!empty($rs)){
                foreach ($rs as &$v){
                    $tmp = json_decode($v,true);
                    $tmp["createTime"] = date("Y-m-d H:i:s",@$tmp["createTime"]);
                    $v = json_encode($tmp,JSON_UNESCAPED_UNICODE);
                }
            }

            $r->close();

            return returnArr(200,$rs,"success");

        }catch (\Exception $e) {
            return returnArr(403,"",$e->getMessage());
        }
    }

    public static function test(){
        $qid = "aaa";
        $r = MyRedis::getConnect();
        $key = ProduceConfig::WORKER_VERIFY_CODE.$qid;

        $rs = $r->exists($key);
        if($rs){
            echo "ok";
        }else{
            $r->set($key,1,["NX","EX"=>3600]);
        }
        exit;
    }




}