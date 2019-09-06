<?php
namespace web\model;
/**
 * Created by PhpStorm.
 * User: root
 * Date: 18-1-4
 * Time: 下午5:06
 */

use config\Tab;
use database\MyRedis;
use config\FactoryConfig;
use config\ProduceConfig;

class ProduceModel extends BaseModel
{

    //工厂生产
    static public function fProduce($paramArr)
    {

        //验证请求数据的有效性
        $cRs = self::verifyProduceData($paramArr);
        if(!$cRs["result"]){
            return returnArr(403,"",$cRs["msg"]);
        }

        try {
            //用户的工厂生产信息
            $r=MyRedis::getConnect();
            if(!$r->ping()){
                return returnArr(502,"","访问超时，请重试");
            }

            //获取锁
            $lockKey = "lock_produce_". $paramArr["facId"];
            $isGetLock = false;
            for($i = 0; $i < ProduceConfig::RETRY_TIMES; $i++){
                $isGetLock = $r->set($lockKey,1,["NX","PX"=>300]);
                if($isGetLock){
                    break;
                }
                usleep(ProduceConfig::RETRY_CD);
            }
            if(!$isGetLock){
                $r->close();
                return returnArr(502,"","访问超时,请重试(0027)");
            }

            //检查地块是否在挂单交易中
            $isSale = GrounddealModel::whetherSale($paramArr["server"],$paramArr["region"],$paramArr["pId"],$paramArr["tvmId"]);
            if(isset($isSale["code"]) && $isSale["code"] == 200){
                $r->del($lockKey);
                $r->close();
                return returnArr(403,"","地块交易中,不能生产");
            }

            //应扣除的金币数 = (工人数 * 工资) + (生产材料数 * 材料单价)
            //成本
            $payCost = ((int)$paramArr["productNums"] * (int)$cRs["data"]["productPirce"]);
            //工资
            $paySalery = ((int)$paramArr["salery"] * (int)$paramArr["workerNums"]);

            //总需要金币数
            $totalCoin = $payCost +  $paySalery;

            //获取用户金币
            $data_coin = selectCoin($paramArr["tvmId"]); //查询用户金币
            if($data_coin['status'] == 'success'){
                $coin = $data_coin['data']['main'];
                if($coin < $totalCoin){
                    $r->del($lockKey);
                    $r->close();
                    return returnArr(401,"",'用户金币不足');
                }
            }else{
                $r->del($lockKey);
                $r->close();
                return returnArr(403,"",'查询用户金币异常');
            }

            $rdsKey = ProduceConfig::PRODUCE_PREFIX.$paramArr["qId"].":".$paramArr["facId"];
            $produceInfo = $r->exists($rdsKey);
            if($produceInfo){
                $r->del($lockKey);
                $r->close();
                return returnArr(403,"","工厂生产任务进行中");
            }

            $ntime = time();
            $guid = keyGen();
            $paramArr["complete"] = 0; //是否已完成生成
            $paramArr["createTime"] = $ntime;
            $paramArr["action"] = "factory_add_produce";//动作说明 用写入队列后，消费队列数据时业务区分
            $paramArr["guid"] = $guid;
            $paramArr["eplee"] = 0;//工作进度(已经来几个人工作过)

            //全服工厂招工列表
            $serverProduceKey = ProduceConfig::SERVER_PRODUCE_LIST_PREFIX.$paramArr["server"];

            //扣除用户生产材料的金币
            $productName = FactoryConfig::propArr($paramArr['productId'])['name'];
            $tmp_msg = '工厂生产'.$productName.'扣除金币';
            $minusRs = UserCoinCore("minus",$paramArr["tvmId"],$payCost,$tmp_msg);   //先扣除成本价
            if($minusRs["code"] != 200){
                $minusRs["errMsg"] = "deductUserCoinErr";
                $minusRs["tvmId"] = $paramArr["tvmId"];
                $minusRs["amount"] = $payCost;
                mLogs("error","fProduce",$minusRs,ProduceConfig::PRODUCE_LOG_FILE);

                $r->del($lockKey);
                $r->close();
                return returnArr(403,"","扣除金币失败");
            }

            //扣除用户生产需要支付的工资
            $minusSy = tradeMainToMain($paramArr["tvmId"],$paySalery,"工厂生产".$productName."给工人付工资","minus");
            if($minusSy["code"] != 200){
                $minusSy["errMsg"] = "deductUserSaleryCoinErr";
                $minusSy["tvmId"] = $paramArr["tvmId"];
                $minusSy["amount"] = $paySalery;
                mLogs("error","fProduce",$minusSy,ProduceConfig::PRODUCE_LOG_FILE);

                $r->del($lockKey);
                $r->close();

                //添加生产失败，将扣除的金币返还
                $plusRs = UserCoinCore("plus",$paramArr["tvmId"],$payCost,"工厂生产".$productName."失败返还金币"); //添加失败返回金币
                if($plusRs["code"] != 200){
                    $plusRs["errMsg"] = "returnUserCoinErr";
                    $plusRs["tvmId"] = $paramArr["tvmId"];
                    $plusRs["amount"] = $payCost;
                    mLogs("error","fProduce",$plusRs,ProduceConfig::PRODUCE_LOG_FILE);
                }

                return returnArr(403,"","扣除金币失败(0028)");
            }

            //招工列表排序key (100 - salery).time()
            $tmpS = 100 - (int)$paramArr["salery"];
            if($tmpS < 1){
                $tmpS = 1;
            }
            $sortKey = (int)($tmpS.time());
            $r->multi(\Redis::PIPELINE);
            //生产数据存储
            $setProduce = $r->hMset($rdsKey,$paramArr);
            //全服招工信息列表
            $setGlbProduce = $r->zAdd($serverProduceKey,$sortKey,$rdsKey);
            //redis队列 用于入库
            //$setToQueue = $r->lPush(ProduceConfig::PRODUCE_QUEUE_KEY,json_encode($paramArr,JSON_UNESCAPED_UNICODE));
            $rs = $r->exec();

            foreach($rs as $v){  //发布招工信息,如果发布失败,也返回人家金币
                if($v != 1){
                    $r->multi(\Redis::PIPELINE);
                    $r->del($rdsKey);
                    $r->zRem($serverProduceKey,$rdsKey);
                    $r->del($lockKey);
                    $r->exec();
                    $r->close();
                    //添加生产失败，将扣除的金币返还
                    $plusRs = UserCoinCore("plus",$paramArr["tvmId"],$payCost,"工厂生产".$productName."失败返还金币");
                    if($plusRs["code"] != 200){
                        $plusRs["errMsg"] = "returnUserCoinErr";
                        $plusRs["tvmId"] = $paramArr["tvmId"];
                        $plusRs["amount"] = $payCost;
                        mLogs("error","fProduce",$plusRs,ProduceConfig::PRODUCE_LOG_FILE);
                    }
                    //添加生产失败，将扣除的工资返还
                    $plusRs = tradeMainToMain($paramArr["tvmId"],$paySalery,"工厂生产".$productName."失败返还工资","plus");
                    if($plusRs["code"] != 200){
                        $plusRs["errMsg"] = "returnUserSaleryCoinErr";
                        $plusRs["tvmId"] = $paramArr["tvmId"];
                        $plusRs["amount"] = $paySalery;
                        mLogs("error","fProduce",$plusRs,ProduceConfig::PRODUCE_LOG_FILE);
                    }
                    return returnArr(403,"","发布生产信息失败001");
                }
            }

            //给用户加经验 张鹏的方法
            //加个人经验
            $pExperience = $paramArr["workerNums"] * FactoryConfig::userExLable(1);
            $personalEx = CenterModel::addEx($paramArr["qId"],$pExperience,1);
            //加经营经验
            $oExperience = $paramArr["workerNums"] * FactoryConfig::productExLable(1);
            $operateEx = CenterModel::addEx($paramArr["qId"],$oExperience,3);

            //扣除幸福值 需要的工人数 X 扣除的基数
            $reduceHpnum = $paramArr["workerNums"] * ProduceConfig::REDUCE_HAPPINESS_BASE_NUM;
            $reduceRs = HappinessModel::reduceHappiness($paramArr["server"],$paramArr["region"],$paramArr["pId"],$paramArr["qId"],$paramArr["facId"],$reduceHpnum);

            //日志队列 赵
            $extend = array(
                "126" => $totalCoin,
                "123" => $paramArr["workerNums"],
            );
            $extendStr = json_encode($extend);
            $millTime = getMillisecond();
            $queueStr = "19\t".$paramArr["facId"] . "\t" .
                        $paramArr["qId"] . "\t" .
                        $paramArr["guid"] . "\t" .
                        ltrim($paramArr["productId"],"c") . "\t" .
                        $paramArr["productNums"] . "\t" .
                        $millTime . "\t" .
                        $extendStr . "\t1";
            $logRs = opt_log_for_produce($queueStr);

            //行为日志
            $leftNum = $r->hGet(TAB::userCard.$paramArr["qId"],$paramArr["productId"]);
            if(empty($leftNum)){
                $leftNum = 0;
            }

            $hExtend = array(
                "1261" => $payCost,
                "1262" => $paySalery,
                "123" => $paramArr["workerNums"],
            );
            $bExtendStr = json_encode($hExtend);
            $behaviorStr = "6\t".$paramArr["qId"] . "\t"  .
                $paramArr["facId"] . "\t" .
                $paramArr["guid"] . "\t" .
                ltrim($paramArr["productId"],"c") . "\t" .
                $paramArr["productNums"] . "\t" .
                $leftNum . "\t" .
                $millTime . "\t" .
                $bExtendStr . "\t1";
            $bLogRs = optFlowLog($behaviorStr);

            $r->del($lockKey);
            $r->close();
            return returnArr(200,"","success");

        }catch (\Exception $e){
            $msg = $e->getMessage();
            mLogs("error","fProduce",array("提交生产信息失败002:".$msg),ProduceConfig::PRODUCE_LOG_FILE);
            return returnArr(403,"","发布生产信息失败002");
        }
    }


    /**
     * 产品合成
     */
    static public function productCompose($paramArr)
    {
        //验证请求数据的有效性
        $cRs = self::verifyComposeData($paramArr);
        if(!$cRs["result"]){
            return returnArr(403,"",$cRs["msg"]);
        }

        try{
            $r=MyRedis::getConnect();
            if(!$r->ping()){
                return returnArr(502,"","访问超时，请重试");
            }

            //获取锁,
            $lockKey = "lock_compose_".$paramArr["qId"] . ":" . $paramArr["facId"];
            $isGetLock = false;
            for($i = 0; $i < ProduceConfig::RETRY_TIMES; $i++){
                $isGetLock = $r->set($lockKey,1,["NX","PX"=>200]);
                if($isGetLock){
                    break;
                }
                usleep(ProduceConfig::RETRY_CD);
            }
            if(!$isGetLock){
                $r->close();
                return returnArr(502,"","访问超时,请重试(0029)");
            }

            //给用户仓库添加合成产品，扣除原料， 增加经验
            //T调用张鹏的方法
            $storageArr = array(
                "qid" => $paramArr["qId"],
                "cid" => $paramArr["productId"],
                "amount" => $paramArr["productNums"],
                "service" => $paramArr["server"],
            );

            $storageRs = WarehouseModel::storage($storageArr);
            if(!$storageRs){
                $r->del($lockKey);
                $r->close();
                return returnArr(403,"","合成失败");
            }

            //给用户加经验 张鹏的方法
            //加个人经验
            $perEx = FactoryConfig::userExLable(3);
            $perEx = $paramArr["productNums"] * $perEx;
            $personalEx = CenterModel::addEx($paramArr["qId"],$perEx,1);

            //合成产品扣除幸福值
            $reduceHpnum = $paramArr["productNums"] * ProduceConfig::COMPOSE_HAPPINESS_BASE_NUM;
            $reduceRs = HappinessModel::reduceHappiness($paramArr["server"],$paramArr["region"],$paramArr["pId"],$paramArr["qId"],$paramArr["facId"],$reduceHpnum);


            //取库存
            $leftNum = $r->hGet(TAB::userCard.$paramArr["qId"],$paramArr["productId"]);
            if(empty($leftNum)){
                $leftNum = 0;
            }

            //写日志队列 赵
            $extend = $cRs["data"];
            $extendStr = json_encode($extend);

            $ntime = getMillisecond();
            $queueStr = "25\t".$paramArr["facId"] . "\t" .
                $paramArr["qId"] . "\t" .
                ltrim($paramArr["productId"],"c") . "\t" .
                $paramArr["productNums"] . "\t" .
                $leftNum . "\t" .
                $ntime . "\t" .
                $extendStr;
            opt_log_for_produce($queueStr);

            //行为日志
            $lStr = "2\t".$paramArr["qId"] . "\t" .
                $paramArr["facId"] . "\t" .
                ltrim($paramArr["productId"],"c") . "\t" .
                $paramArr["productNums"] . "\t" .
                $leftNum . "\t" .
                $ntime . "\t" .
                $extendStr;
            $logRs = optFlowLog($lStr);

            $r->del($lockKey);
            $r->close();

            return returnArr(200,"","success");


        }catch(\Exception $e){
            return returnArr(403,"","合成失败");
        }

    }

    /**
     * 批量获取工厂的生产信息,只返回在生产中的工厂信息
     * @param array 一维的id数组 id格式为 qid:facId array()
     * @reutrn array 返回二维数组 array(["qid:facId"]=>array(...));
     * @return param  productNums:生产材料数量 completeNums:已完成的数量  workerNums：需要的工人数量   eplee：已来打工的人数
     */
    static public function BatchGetProduceInfo($paramArr)
    {

        if (empty($paramArr)){
            return returnArr("400","","params is null");
        }
        try{

            //$r = MyRedis::getConnect();
            $r = MyRedis::getConnectRead();
            if(!$r->ping()){
                return returnArr("502","","connect to redis timeout");
            }

            $r->multi(\Redis::PIPELINE);
            foreach($paramArr as $v){
                $tkey = ProduceConfig::PRODUCE_PREFIX.$v;
                $r->hGetAll($tkey);
            }
            $rs = $r->exec();
            $r->close();

            $res = array();

            foreach($rs as $val){
                if(!empty($val)){
                    $tkey = $val["qId"].":".$val["facId"];
                    $tarr = array(
                        "productNums" => $val["productNums"],
                        "workerNums" => $val["workerNums"],
                        "eplee" => $val["eplee"],
                        "completeNums" => ceil($val["eplee"] * ($val["productNums"] / $val["workerNums"])),
                    );
                    $res[$tkey] = $tarr;
                }
            }

            return returnArr(200,$res,"success");


        }catch (\Exception $e) {
            mLogs("error","BatchGetProduceInfo",array("batch get factory produce info err:".$e->getMessage()),ProduceConfig::PRODUCE_LOG_FILE);
            return returnArr("403","","getProduce err");
        }
    }

    /**
     * 获取工厂的生产信息
     * @param $paramArr
     * @return array
     */
    static public function modelProduceInfo($paramArr)
    {
        try{
            //$r = MyRedis::getConnect();
            $r = MyRedis::getConnectRead();
            if(!$r->ping()){
                return returnArr(502,"","访问超时，请重试");
            }

            $rdsKey = ProduceConfig::PRODUCE_PREFIX.$paramArr["qId"].":".$paramArr["facId"];
            $rs = $r->hGetAll($rdsKey);

            $cbData = array(
                "producing"=>0,
                "info" => array(),
                "workers" => array(),
            );

            if(!empty($rs)){
                //打工时的签名
                $tmpsg = array(
                    "employerQid" => $rs["qId"],
                    "server"=>$rs["server"],
                    "region" =>$rs["region"],
                    "pId" => $rs["pId"],
                    "facId" => $rs["facId"],
                );
                $sign = makeSign($tmpsg);

                /////打工工人分批显示
                $perPage = ceil($rs["workerNums"] / $rs["productNums"]);

                $info = array(
                    //"qId" => $rs["qId"],
                    "tvmId"=>$rs["tvmId"],
                    "server" => $rs["server"],
                    "region" => $rs["region"],
                    "pId"=>$rs["pId"],
                    "facId"=>$rs["facId"],
                    "productId"=>$rs["productId"],
                    "productNums"=>$rs["productNums"],
                    "workerNums"=>$rs["workerNums"],
                    "perPageWorkerNums" => $perPage,
                    "salery"=>$rs["salery"],
                    "complete"=>$rs["complete"],
                    "createTime"=>$rs["createTime"],
                    "guid"=>$rs["guid"],
                    "eplee"=>$rs["eplee"],
                    "sign" => $sign,
                );
                $cbData["producing"] = 1;
                $cbData["info"] = $info;

                if($rs["eplee"] > 0) {
                    $epleesKey = ProduceConfig::WORKERS_PREFIX . $rs["guid"] . ":" . $paramArr["facId"];
                    $eplees = $r->hGetAll($epleesKey);
                    if($eplees){
                        $epleeArr =array();
                        foreach ($eplees as $v){
                            $epleeArr[] = json_decode($v,true);
                        }
                        $timesArr = array();
                        foreach($epleeArr as $tv){
                            $timesArr[] = $tv["createTime"];
                        }
                        array_multisort($timesArr,SORT_ASC,$epleeArr);
                        //$cbData["workers"] = $epleeArr;

                        $page = ceil(count($epleeArr) / $perPage);
                        $perpageWorkers = array();
                        if(($page * $perPage) == count($epleeArr)){
                        }else{
                            $offset = ($page - 1) * $perPage;
                            for($i = $offset;$i < count($epleeArr); $i ++){
                                $perpageWorkers[] = $epleeArr[$i];
                            }
                        }

                        $perPageWorkerNums = $perPage;
                        if($page * $perPage > $rs["workerNums"]){
                            $tp = ($page * $perPage) - $rs["workerNums"];
                            $perPageWorkerNums = $perPage - $tp;
                        }
                        $cbData["info"]["perPageWorkerNums"] = $perPageWorkerNums;
                        $cbData["workers"] = $perpageWorkers;

                    }
                }
            }

            if(empty($cbData["info"])){
                $r->close();
                return returnArr(404,"","工厂没有生产任务");
            }


            $pQid = getQid($paramArr["pTvmid"]);

            //检查当前用户是否需要验证码校验
            $cbData["useVcode"] = 0;
            $doworkVerifyCodeKey = ProduceConfig::WORKER_VERIFY_CODE.$pQid;
            $useVerifyCode = $r->exists($doworkVerifyCodeKey);
            if(!$useVerifyCode){
                $cbData["useVcode"] = 1;
            }

            //获取用户当日打工剩余次数
            $wTimesArr =JobModel::getUserDoworkTimes($pQid);
            $doWorkeTimes = array(
                "limit" => ProduceConfig::WORK_LIMIT_NUMS,
                "done" => 0,
            );
            if($wTimesArr["code"] == 200){
                if(!empty($wTimesArr["data"]["limit"])){
                    $doWorkeTimes["limit"] = $wTimesArr["data"]["limit"];
                }
                if(!empty($wTimesArr["data"]["done"])){
                    $doWorkeTimes["done"] = $wTimesArr["data"]["done"];
                }
            }
            $cbData["doWorkTimes"] = $doWorkeTimes;

            $r->close();

            return returnArr(200,$cbData,"success");

        }catch (\Exception $e){
            return returnArr(403,"","获取工厂生产信息失败");
        }
    }

    /**
     * 工厂撤消生产
     */
    public static function produceCancel($paramArr)
    {
        try{
            $r = MyRedis::getConnect();
            if(!$r->ping()){
                return returnArr(502,"","访问超时，请重试");
            }

            //获取锁
            $lockKey = "lock_cancel_produce_".$paramArr["qId"] . ":" . $paramArr["facId"];
            $isGetLock = false;
            for($i = 0; $i < ProduceConfig::RETRY_TIMES; $i++){
                $isGetLock = $r->set($lockKey,1,["NX","PX"=>3000]);
                if($isGetLock){
                    break;
                }
                usleep(ProduceConfig::RETRY_CD);
            }
            if(!$isGetLock){
                $r->close();
                return returnArr(502,"","请求超时，请重试");
            }

            $rdsKey = ProduceConfig::PRODUCE_PREFIX.$paramArr["qId"].":".$paramArr["facId"];
            $produceExists = $r->exists($rdsKey);
            if(!$produceExists){
                $r->del($lockKey);
                $r->close();
                return returnArr(403,"","没有生产信息");
            }

            //取生产信息
            $produceInfo = $r->hGetAll($rdsKey);
            if(empty($produceInfo)){
                $r->del($lockKey);
                $r->close();
                return returnArr(404,"","没有生产信息(002)");
            }

            //计算应退还的金币数
            $personForOne = ceil((int)$produceInfo["workerNums"] / $produceInfo["productNums"]);
            $eplee = $produceInfo["eplee"];
            $done = floor($eplee / $personForOne);
            $done = $done < 0 ? 0 : $done;
            $leftProduct = $produceInfo["productNums"] - $done;
            $leftWorker = $produceInfo["workerNums"] - $eplee;

            //取单位材料生产成本
            $propArr = FactoryConfig::propArr($produceInfo["productId"]);
            $cost = $propArr["price"];

            //退还未生产材料的成本 5% 手续费
            $refundCost = floor($leftProduct * $cost * 0.95);
            //退还未打工的工资 5% 手续费
            $salery = $produceInfo["salery"];
            $refundSalery = floor($leftWorker * $salery * 0.95);

            $productName = FactoryConfig::propArr($produceInfo['productId'])['name'];

            //成本返还
            $plusCostRs = UserCoinCore("plus",$paramArr["tvmId"],$refundCost,$productName."工厂撤单返还剩余成本");
            if($plusCostRs["code"] != 200){
                $plusCostRs["errMsg"] = "returnCancelCoinErr";
                $plusCostRs["tvmId"] = $paramArr["tvmId"];
                $plusCostRs["amount"] = $refundCost;
                mLogs("error","cancelProduce",$plusCostRs,ProduceConfig::PRODUCE_LOG_FILE);
                $r->del($lockKey);
                $r->close();
                return returnArr(403,"","返还金币失败(001)");
            }
            //工资返还
            $plusSaleryRs = tradeMainToMain($paramArr["tvmId"],$refundSalery,$productName."工厂撤单返还剩余工资","plus");
            if($plusSaleryRs["code"] != 200){
                $plusSaleryRs["errMsg"] = "returnCancelSaleryCoinErr";
                $plusSaleryRs["tvmId"] = $paramArr["tvmId"];
                $plusSaleryRs["amount"] = $refundSalery;
                mLogs("error","cancelProduce",$plusSaleryRs,ProduceConfig::PRODUCE_LOG_FILE);

                $r->del($lockKey);
                $r->close();

                //剩余工资返还失败，将已还给用户的剩余成本扣除
                $minusRs = UserCoinCore("minus",$paramArr["tvmId"],$refundCost,$productName."工厂返还剩余工资失败,撤回返还的成本");
                if($minusRs["code"] != 200){
                    $minusRs["errMsg"] = "deductRefundCostCoinErr";
                    $minusRs["tvmId"] = $paramArr["tvmId"];
                    $minusRs["amount"] = $refundCost;
                    mLogs("error","cancelProduce",$minusRs,ProduceConfig::PRODUCE_LOG_FILE);
                }

                return returnArr(403,"","返还金币失败(002)");
            }

            //删除订单信息
            $serverProduceKey = ProduceConfig::SERVER_PRODUCE_LIST_PREFIX.$paramArr["server"];
            $workersKey = ProduceConfig::WORKERS_PREFIX.$produceInfo["guid"] . ":" . $paramArr["facId"];
            $delProduceRs = $r->del($rdsKey);
            $delWorkerRs = $r->del($workersKey);
            $delFromList = $r->zRem($serverProduceKey,$rdsKey);
            if(empty($delProduceRs)){
                return returnArr(403,"","撤单失败");
            }

            $totalCoin  = $refundCost + $refundSalery;
            //日志队列 赵
            $extend = array(
                "126" => $totalCoin,
                "123" => $leftWorker,
            );
            $extendStr = json_encode($extend);
            $millTime = getMillisecond();
            $queueStr = "19\t".$paramArr["facId"] . "\t" .
                $paramArr["qId"] . "\t" .
                $produceInfo["guid"] . "\t" .
                ltrim($produceInfo["productId"],"c") . "\t" .
                $leftProduct . "\t" .
                $millTime . "\t" .
                $extendStr . "\t3";
            $logRs = opt_log_for_produce($queueStr);

            //行为日志
            $leftNum = $r->hGet(TAB::userCard.$paramArr["qId"],$produceInfo["productId"]);
            if(empty($leftNum)){
                $leftNum = 0;
            }

            $hExtend = array(
                "1261" => $refundCost,
                "1262" => $refundSalery,
                "123" => $leftWorker,
            );
            $bExtendStr = json_encode($hExtend);
            $behaviorStr = "6\t".$paramArr["qId"] . "\t"  .
                $paramArr["facId"] . "\t" .
                $produceInfo["guid"] . "\t" .
                ltrim($produceInfo["productId"],"c") . "\t" .
                $leftProduct . "\t" .
                $leftNum . "\t" .
                $millTime . "\t" .
                $bExtendStr . "\t3";
            $bLogRs = optFlowLog($behaviorStr);

            $data = array(
                "cost" => $refundCost,
                "salery" => $refundSalery,
            );

            return returnArr(200,$data,"success");


        }catch (\Exception $e){
            return returnArr(403,"","撤单失败(001)");
        }
    }


    /**
     * 验证请求数据
     * 原材料工厂
     */
    static private function verifyProduceData($data)
    {
        $res = array("result"=>true,"msg"=>"","data"=>array());
        $cbData = array();
        //获取工厂信息
        $pid = $data["server"]."_".$data["region"]."_".$data["pId"];
        $facInfo = FactoryModel::userSelFactory($pid,$data["qId"]);

        if(empty($facInfo)){
            $res["result"] = false;
            $res["msg"] = "查询工厂信息失败";
            return $res;
        }

//        echo "<pre>";
//        print_r($facInfo);exit;

        //验证工厂ID
        if($data["facId"] != $facInfo["facId"]){
            $res["result"] = false;
            $res["msg"] = "工厂信息错误";
            return $res;
        }

        //验证是否是原材料工厂
        if($facInfo["type"] != 1){
            $res["result"] = false;
            $res["msg"] = "不是原材料工厂";
            return $res;
        }

        //验证生产产品
        if(!in_array($data["productId"],$facInfo["propId"])){
            $res["result"] = false;
            $res["msg"] = "生产产品不合法";
            return $res;
        }

        //验证生产数量区间
        if($data["productNums"] < 1 || $data["productNums"] > $facInfo["makeNum"]){
            $res["result"] = false;
            $res["msg"] = "生产数量不合法";
            return $res;
        }

        //验证幸福指数
        if($facInfo["happinessNum"] < ProduceConfig::HAPPINESS_CRITICALITY_VALUE){
            $res["result"] = false;
            $res["msg"] = "幸福值过低";
            return $res;
        }

        //获取幸福值影响招工人数的指数
        $exponent = ProduceConfig::getHappinessExponent($facInfo["happinessNum"]);

        //验证工人数量
        $propArr = FactoryConfig::propArr();
        $propInfo = $propArr[$data["productId"]];
        if(empty($propInfo)){
            $res["result"] = false;
            $res["msg"] = "取产品信息失败";
            return $res;
        }
        $wNums = ceil($data["productNums"] * $propInfo["servant"] * $exponent);
        if($wNums != $data["workerNums"]){
            $res["result"] = false;
            $res["msg"] = "工人人数不匹配";
            return $res;
        }

        $cbData["productPirce"] = $propInfo["price"];

        $res["data"] = $cbData;
        return $res;
    }

    /**
     * 验证请求数据
     * 合成工厂
     */
    static private function verifyComposeData($data){
        $res = array("result"=>true,"msg"=>"","data"=>array());
        $cbData = array();
        //获取工厂信息
        $pid = $data["server"]."_".$data["region"]."_".$data["pId"];
        $facInfo = FactoryModel::userSelFactory($pid,$data["qId"]);

        if(empty($facInfo)){
            $res["result"] = false;
            $res["msg"] = "查询工厂信息失败";
            return $res;
        }

//        echo "<pre>";
//        print_r($facInfo);exit;

        //验证工厂ID
        if($data["facId"] != $facInfo["facId"]){
            $res["result"] = false;
            $res["msg"] = "合成工厂信息错误";
            return $res;
        }

        //验证是否是合成工厂
        if($facInfo["type"] != 2){
            $res["result"] = false;
            $res["msg"] = "不是合成工厂";
            return $res;
        }

        //验证生产产品
        if(!in_array($data["productId"],$facInfo["propId"])){
            $res["result"] = false;
            $res["msg"] = "生产产品不合法";
            return $res;
        }

        //验证幸福指数 暂时不做限制
        if($facInfo["happinessNum"] < ProduceConfig::COMPOSE_HAPPINESS_BASE_NUM){
            $res["result"] = false;
            $res["msg"] = "幸福值过低";
            return $res;
        }

        //验证矿机最大合成数量
        $maxNum = floor($facInfo["happinessNum"] / ProduceConfig::COMPOSE_HAPPINESS_BASE_NUM);
        if($data["productNums"] > $maxNum){
            $res["result"] = false;
            $res["msg"] = "最多可合成".$maxNum."个";
            return $res;
        }

        //验证原材料是否满足合成条件
        //取需要的原材料个数
        $propArr = FactoryConfig::propArr();
        $propInfo = $propArr[$data["productId"]];

        //获取用户的原材料储量
        $storeKey = Tab::userCard.$data["qId"];
        $r = MyRedis::getConnect();
        $storeInfo = $r->hGetAll($storeKey);
        $r->close();
        if(empty($storeInfo)){
            $res["result"] = false;
            $res["msg"] = "材料不足";
            return $res;
        }

        foreach($propInfo["compound"] as $k=>$v){
            if(empty($storeInfo[$k])){
//                $res["result"] = false;
//                $res["msg"] = "材料不足001";
//                return $res;
            }
            $storeNum = (int)$storeInfo[$k];
            $needNum = (int)$v["num"] * $data["productNums"];
            if($needNum > $storeNum){
                $res["result"] = false;
                $res["msg"] = "材料不足002";
                return $res;
            }
            //返回数据中带回需要的材料数，用于写日志队列
            $logk = ltrim($k,"c");
            $cbData[$logk] = $needNum;
        }

        $res["data"] =$cbData;
        return $res;
    }

    /**
     * 取服id
     */
    private function getServerId($pid){
        $tmpArr = explode("_",$pid);
        $serverId = "";
        if(count($tmpArr) > 1){
            $serverId = $tmpArr[0];
        }
        return $serverId;
    }

    /**
     * test func
     */
    public static function dot($paramArr){
        $tmpList = FactoryModel::userSelFacCenter($paramArr["qId"],1);
        if(empty($tmpList)){
            return returnArr(403,"","fac no found");
        }

        foreach ($tmpList as &$fac){
            if($fac["serverId"] == 755){
                $fac["tvmId"] = $paramArr["tvmId"];
                $fac["qId"] = $paramArr["qId"];
                $fac["nickName"] = $paramArr["nickName"];
                $fac["headImg"] = $paramArr["headImg"];
            }
        }
    }

}