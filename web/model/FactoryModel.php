<?php
namespace web\model;

use config\GroundConfig;
use config\ProduceConfig;
use config\Tab;
use config\FactoryConfig;
use database\MyRedis;



class FactoryModel extends BaseModel
{

    /*
     * 生成工厂facId*/
    public static function getFacId()
    {
        $eng = chr(rand(65, 90)) . chr(rand(97, 122)) . chr(rand(65, 90)) . chr(rand(97, 122)) . chr(rand(97, 122));
        $db = Tab::buildId;
        $r = MyRedis::getConnect();
        $id = $r->incr($db, 1);
        $r->close();
        return $id . $eng;
    }

    /*
     * pId  地块ID
     * fId  工厂ID
     * type 工厂类别
     * serverID  服ID
     * regionID  区ID
     * 新增工厂*/
    public static function addFactory($pId, $fId, $Qid, $type, $serverId, $regionId, $coordX, $coordY)
    {
        $pId_key = $serverId . '_' . $regionId . '_' . $pId;//组合工厂的地区ID号
        $FacList = FactoryConfig::FactoryList($type);
        if (!is_array($FacList)) {
            return returnArr('407', '', 'type error');
        }
        $FactoryResult = $FacList[$fId];
        if (!$FactoryResult) {
            return returnArr('408', '', 'fId error');
        }
        $gdataTmp = GrounddealModel::whetherSale($serverId,$regionId,$pId);
        if($gdataTmp['code'] == 200){
            return returnArr(413, '', '地块出售中,不能新建工厂!');
        }
        $price = $FactoryResult['price'];
        $newTvmId = getTvmId($Qid);
        if (!$newTvmId) {
            return returnArr(412, '', '用户不存在');
        }

        $data_coin = selectCoin($newTvmId); //查询用户金币
        if ($data_coin['status'] == 'success') {
            $coin = $data_coin['data']['main'];
            if ($coin < $price) {
                return returnArr('501', '', '用户金币不足');
            }
        } else {
            return returnArr('405', '', '用户金币查询异常');
        }
        $r = MyRedis::getConnect();
        $ping_tmp = $r->ping();
        if ($ping_tmp != '+PONG') {
            $r->close();
            return returnArr('406', '', '网络延时,请稍后重试');
        }
        self::getFactoryLock($pId_key,'add');
        $msg = '添加建筑「' . $FactoryResult['name'] . '」';
        $data = UserCoinCore('minus', $newTvmId, $price, $msg);//购买楼层
        if ($data['code'] == 200) {
            $facId = self::getFacId();
            $FactoryResult['serverId'] = $serverId;
            $FactoryResult['regionId'] = $regionId;
            $FactoryResult['pId'] = $pId;
            $FactoryResult['coordX'] = $coordX;
            $FactoryResult['coordY'] = $coordY;
            $FactoryResult['icon'] = $price;//建筑总价值
            $FactoryResult['facId'] = $facId;

            unset($FactoryResult['describe']);
            unset($FactoryResult['imageName']);

            //通知地块
            $tmpPath = FactoryConfig::factoryImagePath($fId);
            $gResult = GroundModel::groundFactory($serverId, $regionId, $pId, $Qid, $facId, $tmpPath, 'add' ,$FactoryResult['type'], $FactoryResult['fid']);
            if(!$gResult) {
                $msg = '购买工厂失败,返回用户金币';
                $result = UserCoinCore('plus', $newTvmId, $price, $msg);
                self::getFactoryLock($pId_key,'del'); //解锁工厂
                $r->close();
                return returnArr('414', $gResult, '获取地块数据无效');
            }
                $dataF = json_encode($FactoryResult);
                $st = $r->hSet(Tab::Factory_Place, $pId_key, $dataF); //新增地块建筑信息,地块ID查工厂
                self::getFactoryLock($pId_key,'del'); //解锁工厂
                $r->close();

                self::userPlace($Qid, $pId_key, 'add');//添加用户地块集合
                self::allFactory($pId_key, $facId, 'add');//添加工厂集合,通过工厂ID查地块

                CenterModel::addEx($Qid, FactoryConfig::userExLable(4), 1); //推送给个人中心,个人等级经验
                CenterModel::addEx($Qid, FactoryConfig::productExLable(2), 3); //推送给个人中心,经营者称号经验

                $fType = $FactoryResult['type'];
                $fGrade = $FactoryResult['grade'];
                opt_log_for_FactoryAdd($serverId, $regionId, $pId, $Qid, $facId, $fId, $fType, $fGrade, 1, $price);//推送日志

                $resultData = self::matchFact($FactoryResult);//合并参数返回
                $resultData['userIcon'] = $coin - $price;
                return returnArr('200', $resultData, 'success');
        } else {
            $msg = array('msg'=>$data);
            mLogs('Info', "add_floor:minus_user_coin_upblv", array('status' => 400, 'msg' => $msg),'Factory');
            return returnArr('406', '', "网络延时,请稍后重试");
        }
    }

    /*删除工厂*/
    public static function delFactory($Qid, $pId, $fId, $facId, $serverId, $regionId)
    {
        $pId_key = $serverId . '_' . $regionId . '_' . $pId;//组合工厂的地区ID号
        $result = self::userPlace($Qid, $pId_key, 'is_sel');
        if (!$result) {
            return returnArr(403, '', '地块不属于你');
        }
        $gdataTmp = GrounddealModel::whetherSale($serverId,$regionId,$pId);
        if($gdataTmp['code'] == 200){
            return returnArr(413, '', '地块出售中,不能折除工厂!');
        }
        $userFactoryData = self::userSelFactory($pId_key, $Qid);//查询用户工厂信息
        if ($userFactoryData['fid'] != $fId || $userFactoryData['facId'] != $facId) {
            return returnArr(411, '', '参数不正确');
        }
        $r = MyRedis::getConnect();
        $ping_tmp = $r->ping();
        if ($ping_tmp != '+PONG') {
            $r->close();
            return returnArr('406', '', '网络延时,请稍后重试');
        }
        $iCon = $userFactoryData['icon'];//地块建筑总价
        $newTvmId = getTvmId($Qid);
        if (!$newTvmId) {
            $r->close();
            return returnArr(412, '', '用户不存在');
        }

        $keyTmp = $Qid.":".$userFactoryData['facId'];
        $ProData = ProduceModel::BatchGetProduceInfo(array($keyTmp));//获取订单信息
        if($ProData['code'] == 200 && !empty($ProData['data'][$keyTmp])){
            return returnArr(413,'','工厂正在生产中,不能折除');
        }
        //通知给地块
        $tmpPath = FactoryConfig::factoryImagePath($fId);
        $gResult = GroundModel::groundFactory($serverId, $regionId, $pId, $Qid, $facId, $tmpPath, 'del' ,$userFactoryData['type'], $userFactoryData['fid']);
        if(!$gResult){
            $r->close();
            return returnArr('414', '', '地块无效');
        }
        self::getFactoryLock($pId_key,'add');//添加锁
        $data = self::payFactory($newTvmId, $iCon, '折除建筑');//给用户返回变卖金币
        if ($data['code'] == 200) {
            $dataP = self::userPlace($Qid, $pId_key, 'del');
            $dataOne = $r->hDel(Tab::Factory_Place, $pId_key);
            $dataD = self::allFactory($pId_key, $facId, 'del');
            if ($dataP && $dataOne && $dataD) {
                $data_coin = selectCoin($newTvmId); //查询用户金币
                $userIcon = 'xxxxxx';
                if ($data_coin['status'] == 'success') {
                    $userIcon = $data_coin['data']['main'];
                }
                self::getFactoryLock($pId_key,'del');//解锁
                $r->close();
                opt_log_for_FactoryDel($serverId, $regionId, $pId, $Qid, $facId, $data['data']['price'],1);
                return returnArr(200, array('userIcon' => $userIcon), '折除成功');
            }
        } else {
            $msg = array('msg'=>$data);
            mLogs('Info', "delFactory", array('status' => 400, 'msg' => $msg),'Factory');
            return returnArr(400, '', $data['msg']);
        }
    }

    /*工厂升级*/
    public static function upFactory($Qid, $pId, $fId, $type, $grade, $facId, $serverId, $regionId)
    {

        $pId_key = $serverId . '_' . $regionId . '_' . $pId;//组合工厂的地区ID号
        $result = self::userPlace($Qid, $pId_key, 'is_sel');
        if (!$result) {
            return returnArr('403', '', '地块不属于你');
        }
        $userFactoryData = self::userSelFactory($pId_key, $Qid);//返回地块对应的工厂信息
        if ($userFactoryData['fid'] != $fId || $userFactoryData['type'] != $type || $userFactoryData['grade'] != $grade || $userFactoryData['facId'] != $facId) {
            return returnArr(411, '', 'fId or type or grade or facId error');
        }
        $gdataTmp = GrounddealModel::whetherSale($serverId,$regionId,$pId);
        if($gdataTmp['code'] == 200){
            return returnArr(413, '', '地块出售中,不能升级工厂!');
        }
        $upList = FactoryConfig::FactoryUpList();
        $new_grade = $userFactoryData['grade'] + 1;
        @$new_data = $upList[$new_grade];
        if (!$new_data) {
            return returnArr('407', '', '已经是最高等级了');
        }
        $price = $new_data['price'];
        $newTvmId = getTvmId($Qid);
        if (!$newTvmId) {
            return returnArr(412, '', '用户不存在');
        }
        $data_coin = selectCoin($newTvmId); //查询用户金币
        if ($data_coin['status'] == 'success') {
            $coin = $data_coin['data']['main'];
            if ($coin < $price) {
                return returnArr('501', '', '用户金币不足');
            }
        } else {
            return returnArr('405', '', '用户金币查询异常');
        }
        $r = MyRedis::getConnect();
        $ping_tmp = $r->ping();
        if ($ping_tmp != '+PONG') {
            $r -> close();
            return returnArr('406', '', '网络延时,请稍后重试');
        }
        $msg = '建筑升级到「' . $new_grade . '」级';
        self::getFactoryLock($pId_key,'add');
        $data = UserCoinCore('minus', $newTvmId, $price, $msg);//购买楼层扣金币
        if ($data['code'] == 200) {
            $userFactoryData['icon'] = $userFactoryData['icon'] + $price;
            $userFactoryData['grade'] = $new_grade;
            $userFactoryData['makeNum'] = $new_data['makeNum'];
            $db = Tab::Factory_Place;
            $data = json_encode($userFactoryData);
            $st = $r->hSet($db, $pId_key, $data);
            $r->close();
            $resultData = self::matchFact($userFactoryData);
            opt_log_for_FactoryUp($serverId, $regionId, $pId, $Qid, $facId, $new_grade, $price);//推送日志
            self::getFactoryLock($pId_key,'del');//解锁
            return returnArr('200', $resultData, 'up success');
        } else {
            $msg = array('msg'=>$data);
            mLogs('Info', "delFactory", array('status' => 400, 'msg' => $msg),'Factory');
            return returnArr('406', '', "网络延时,请稍后重试");
        }
    }

    /*
     * 用户地块列表集合,统计用户所有地块儿*/
    public static function userPlace($Qid, $pId_key, $type)
    {
        $r = MyRedis::getConnect();
        $rd = MyRedis::getConnectRead();
        $db = Tab::Factory_User . $Qid;
        if ($type == 'sel') {
            $data = $r->sMembers($db);
        } elseif ($type == 'add') {
            $data = $r->sAdd($db, $pId_key);
        } elseif ($type == 'del') {
            $data = $r->sRem($db, $pId_key);
        } elseif ($type == 'is_sel') {
            $data = $r->sIsMember($db, $pId_key);//true/false
        }
        $r->close();
        $rd->close();
        return $data;
    }

    /*
     * 查询用户地块建筑信息,type存在合并参数返回*/
    public static function userSelFactory($pId_key, $Qid)
    {
        if($Qid==''){
            return false;
        }
        $uPlaceData = self::userPlace($Qid, $pId_key, 'is_sel');
        if (!$uPlaceData) {
            return false;
        }
        $r = MyRedis::getConnectRead();
        $db = Tab::Factory_Place;
        $result = $r->hGet($db, $pId_key);
        $r->close();
        if ($result) {
            $data = json_decode($result, true);
            $data['Sign'] = makeSign(array('qId' => $Qid, 'pId' => $data['pId'], 'facId' => $data['facId'], 'serverId' => $data['serverId'], 'region' => $data['regionId']));
            $HappenTmp = HappinessModel::controlHappiness(array($pId_key), $Qid, 'select')[$pId_key];
            $HappenNum = $HappenTmp?$HappenTmp:0;
            $data['happinessNum'] = $HappenNum;
            return $data;
        } else {
            return false;
        }
    }


    /*修改用户工厂信息*/
    public static function editUserFactory($pId_key,$data){
        $r = MyRedis::getConnect();
        $r->hSet(Tab::Factory_Place,$pId_key,json_encode($data));
    }


    /*
     * 廖志阳使用 单个查看地图上工厂信息
     * @ pid_key 地块ID
     * @ Qid     请求用户ID
     * @ pQid    地块持有者QID
     * @ type    1 用户自己地块儿  2用户查询别人地块儿
     * selFactory工厂查询*/
    public static function SelFactoryNew($pId_key, $Qid, $type, $pQid)
    {
        $r = MyRedis::getConnectRead();
        $db = Tab::Factory_Place;
        $result = $r->hGet($db, $pId_key);
        $r->close();
        if ($result) {
            $data = json_decode($result, true);
            //TODO:临时修改 燃油厂更名为 原油厂
            if($data['fid']==2004){
                $data['name'] = '原油厂';
                self::editUserFactory($pId_key,$data);
            }

            $data['rate'] = FactoryConfig::Rate;
            if($data['type']==3){ //直接返回公园信息
                $data['serverName'] = GroundConfig::serviceName($data['serverId']);
                $data['isUserType'] = $type;    //返回用户判断是否是 查询自己地块
                $data = self::matchFact($data);
                $data['imageName'] = FactoryConfig::factoryImagePath($data['fid']);
                $HappenTmp = HappinessModel::controlHappiness(array($pId_key), $Qid, 'select')[$pId_key];
                $HappenNum = $HappenTmp?$HappenTmp:GroundConfig::GROUND_HAPPY_INIT; //查询幸福值
                $data['happinessNum'] = $HappenNum;
                return returnArr(200,$data,'success');
            }
            if($type == 1){ //表示用户查看自己的工厂信息
                $data['isUserType'] = $type;
                $data['Sign'] = makeSign(array('qId' => $Qid, 'pId' => $data['pId'], 'facId' => $data['facId'], 'serverId' => $data['serverId'], 'region' => $data['regionId']));
                $data = self::matchFact($data);
                $HappenTmp = HappinessModel::controlHappiness(array($pId_key), $Qid, 'select');
                if(empty($HappenTmp[$pId_key])){
                    $HappenNum = 0;
                }else{
                    $HappenNum = $HappenTmp[$pId_key]?$HappenTmp[$pId_key]:GroundConfig::GROUND_HAPPY_INIT; //查询幸福值
                }
                $data['happinessNum'] = $HappenNum;
                $data['happinessNum_Exponent'] = ProduceConfig::getHappinessExponent($HappenNum); //幸福指数
                $data['imageName'] = FactoryConfig::factoryImagePath($data['fid']);
                $data['serverName'] = GroundConfig::serviceName($data['serverId']);

                if($data['type']==1){
                    unset($data['describe']);
                    unset($data['produceLine']);
                    $keyTmp = $Qid.":".$data['facId'];
                    $ProData = ProduceModel::BatchGetProduceInfo(array($keyTmp));//获取订单信息
                    if($ProData['code'] != 200){
                        $data['propId'][0]['produceOrder'] = null;
                    }else{
                        if(!empty($ProData['data'][$keyTmp])){
                            $data['propId'][0]['produceOrder'] = $ProData['data'][$keyTmp];
                        }else{
                            $data['propId'][0]['produceOrder'] = null;
                        }
                    }
                    $data['propId'][0]['makeNum'] = $data['makeNum'];
                    $data['propId'][0]['produceLine'] = FactoryConfig::factoryLine($data['fid']);
                    $data['propId'][0]['describe'] = FactoryConfig::factoryListDescribe($data['fid']);
                    $cardId = $data['propId'][0]['propId'];//c9001
                    $data['userCard'] = self::selUserCard($Qid, $data['type'], $cardId);//当前工厂材料用户的库存

                    $nextGrade = $data['grade']+1; //升级信息
                    if(empty(FactoryConfig::factoryUpList()[$nextGrade]['price'])){
                        $data['isUpdate'] =2; //表示不能升级
                        $data['nextPrice'] = '88888';
                    }else{
                        $data['isUpdate'] = 1; //可以升级
                        $data['nextPrice'] = FactoryConfig::factoryUpList()[$nextGrade]['price'];
                        $data['nextMakeNum'] = FactoryConfig::factoryUpList()[$nextGrade]['makeNum'];
                    }
                }
                //返回矿机厂的数据
                if($data['type'] == 2){
                    $data['view_one'] = array('name'=>'矿机卡','img'=>'b_kuangji.png');
                    $UserCard = WarehouseModel::wareHouseList($pQid, 1); //返回该工厂用户所拥有的材料数量
                    if($UserCard)
                        $UserCardTmp = array();
                    foreach ($UserCard as $v) {
                        $UserCardTmp[$v['cid']] = array('img' => $v['img'], 'num' => $v['num']);
                    }
                    foreach ($data['propId'] as $k => $v) {
                        $compoundTmp = array();
                        foreach ($v['compound'] as $kk => $vv) {
                            //xNum是合成一个矿机需要的材料数量 yNum是用户库存
                            array_push($compoundTmp,array('cid'=>$kk,'name'=>$vv['name'],'xNum'=>$vv['num'],'yNum'=>$UserCardTmp[$kk]['num'], 'img'=>$UserCardTmp[$kk]['img']));
                        }
                        $data['propId'][$k]['compound'] = $compoundTmp; //合成需要的材料
                    }
                }
                return returnArr(200,$data,'success');
            }
            if($type == 2){//用户查看别人工厂信息
                if($data['type'] == 2){
                    $data['view_one'] = array('name'=>'矿机卡','img'=>'b_kuangji.png');
                }
                $data['isUserType'] = $type;
                $data['Sign'] = makeSign(array('qId' => $pQid, 'pId' => $data['pId'], 'facId' => $data['facId'], 'serverId' => $data['serverId'], 'region' => $data['regionId']));
                $data = self::matchFact($data);
                $HappenTmp = HappinessModel::controlHappiness(array($pId_key), $pQid, 'select');
                if(empty($HappenTmp[$pId_key])){
                    $HappenNum = 0;
                }else{
                    $HappenNum = $HappenTmp[$pId_key]?$HappenTmp[$pId_key]:0; //查询幸福值
                }
                $data['happinessNum'] = $HappenNum;
                $data['happinessNum_Exponent'] = ProduceConfig::getHappinessExponent($HappenNum);
                $data['imageName'] = FactoryConfig::factoryImagePath($data['fid']);
                $data['serverName'] = GroundConfig::serviceName($data['serverId']);
                if($data['type']==1){
                    unset($data['describe']);
                    unset($data['produceLine']);
                    $keyTmp = $pQid.":".$data['facId'];
                    $ProData = ProduceModel::BatchGetProduceInfo(array($keyTmp));//获取订单信息
                    if($ProData['code'] != 200){
                        $data['propId'][0]['produceOrder'] = null;
                    }else{
                        if(!empty($ProData['data'][$keyTmp])){
                            $data['propId'][0]['produceOrder'] = $ProData['data'][$keyTmp];
                        }else{
                            $data['propId'][0]['produceOrder'] = null;
                        }
                    }
                    $data['propId'][0]['makeNum'] = $data['makeNum'];
                    $data['propId'][0]['produceLine'] = FactoryConfig::factoryLine($data['fid']);//生产线1
                    $data['propId'][0]['describe'] = FactoryConfig::factoryListDescribe($data['fid']);
                }
                return returnArr(200,$data,'success');
            }
        } else {
            return returnArr(400,'','该地块工厂信息不存在');
        }
    }


    /*
     * @param type 1:基础材料  2:矿机卡
     * @param card_id  c9001原木,c9002铁矿,c9003橡胶,c9004原油
     * 查询用户仓库某种原材料的数量*/
    public static function selUserCard($Qid, $type, $card_id)
    {
        $data_card = WarehouseModel::wareHouseList($Qid, $type);
        $cardNum = '';
        foreach ($data_card as $v) {
            if ($v['cid'] == $card_id) {
                $cardNum = $v['num']?$v['num']:0;
                return $cardNum;
            }
        }
    }

    /*张鹏个人中心使用
     *
     * 批量查询用户工厂信息(用户中心)*/
    public static function userSelFacCenter($Qid, $type)
    {
        $r = MyRedis::getConnectRead();
        $db = Tab::Factory_User.$Qid;
        $pData = $r->sMembers($db);

        if(!$pData){
            $r->close();
            if($type ==4 ){  //顺序返回 矿机合成厂 + 工厂 + 公共建筑
            $allGround = GroundModel::userGround($Qid);  //获取用户所有地块
                if($allGround['code'] == 200){
                    $tmpAr =array();
                    $tmpG = $allGround['data'];
                    foreach($tmpG as $v){
                        if($v['isfactory'] ==2){ //表示地块没有工厂的瓶装数据返回
                            $v['happinessNum'] = GroundConfig::GROUND_HAPPY_INIT;
                            $v['ground_happy_init'] = GroundConfig::GROUND_HAPPY_INIT;
                            $v['serverName'] = GroundConfig::serviceName($v['service']);
                            array_push($tmpAr,$v);
                        }
                    }
                }
                return $tmpAr;
            } else {
                return false; 
           }
        }
        $allFac = $r->hMGet(Tab::Factory_Place, $pData);//获取用户所有大楼信息
        $r->close();
        $allHap = HappinessModel::controlHappiness($pData, $Qid, 'select'); //获取大楼幸福值
        $param = array();
        foreach($allFac as $k => &$v){
            if($v) {
                $v = json_decode($v, true);
                //TODO:: 更名燃油厂为原油长
                if($v['fid'] == 2004){
                    $pId_key_tmp = $v['serverId'].'_'.$v['regionId'].'_'.$v['pId'];
                    self::editUserFactory($pId_key_tmp,$v);
                }
                if ($v['fid'] == '' || $v['facId'] == '' || $v['serverId'] == '' || $v['type'] == '') {
                    continue;
                }
                $v['imageName'] = FactoryConfig::factoryImagePath($v['fid']);
                $v['isfactory'] = 1; //表示地块有工厂
                if (empty($allHap[$k])) {
                    $v['happinessNum'] = 0;
                } else {
                    $v['happinessNum'] = $allHap[$k] ? $allHap[$k] : GroundConfig::GROUND_HAPPY_INIT;
                }
                $v['ground_happy_init'] = GroundConfig::GROUND_HAPPY_INIT;  //添加字段 拼装
                $v['propId'] = FactoryConfig::propArr($v['propId'][0]);
                $facId = $v['facId'];
                $tmpStr = $Qid . ":" . $facId;
                if($v['type'] == 1){   //获取所有工厂的集合
                    array_push($param, $tmpStr);
                }
            }else{
                unset($allFac[$k]);
            }
        }
        $data = ProduceModel::BatchGetProduceInfo($param);//获取工厂订单信息
        if($data['code'] == '200'){
            foreach($allFac as $k => &$v){
                $v['serverName'] = GroundConfig::serviceName($v['serverId']);
                $tmpStr = $Qid . ":" . $v['facId'];
                if(!empty($data['data'][$tmpStr])){
                    $v['produceOrder'] = $data['data'][$tmpStr]; //工厂订单信息
                }else{
                    $v['produceOrder'] = null;
                }
            }
        }
        $dataOne = $dataTwo = $dataThr = array();
        foreach ($allFac as $item) {
            if($item['type'] == 1){
                array_push($dataOne,$item);
            }
            if($item['type'] == 2){
                array_push($dataTwo,$item);
            }
            if($item['type'] == 3){
                array_push($dataThr,$item);
            }
        }
        if($type == 1 ){  //返回工厂
            return $dataOne;
        }elseif($type == 2 ){  //返回矿机
            return $dataTwo;
        }elseif($type == 3 ){  //返回公共建筑
            return $dataThr;
        }elseif($type ==4 ){  //顺序返回 矿机合成厂 + 工厂 + 公共建筑
            $allGround = GroundModel::userGround($Qid);  //获取用户所有地块
            if($allGround['code'] == 200){
                $tmpAr =array();
                $tmpG = $allGround['data'];
                foreach($tmpG as $v){
                    if($v['isfactory'] ==2){ //表示地块没有工厂的瓶装数据返回
                        $v['happinessNum'] = GroundConfig::GROUND_HAPPY_INIT;
                        $v['ground_happy_init'] = GroundConfig::GROUND_HAPPY_INIT;
                        $v['serverName'] = GroundConfig::serviceName($v['service']);
                        array_push($tmpAr,$v);
                    }
                }
                $tmpData = array_merge($tmpAr,$dataTwo,$dataOne,$dataThr);
            }else{
                $tmpData = array_merge($dataTwo,$dataOne,$dataThr);
            }
            return $tmpData;
        }else{
            return false;
        }
    }


    /*
     * 批量查询工厂信息(招工中心),返回工厂信息*/
    public static function produceSelFactory($paramArr)
    {
        if(!is_array($paramArr)){
            return false;
        }
        $r = MyRedis::getConnectRead();
        $data = $r->hMGet(Tab::Factory_Place,$paramArr);
        $r->close();
        if($data){
            foreach($data as $k=>&$v){
                if($v) {
                    $v = json_decode($v, true);
                    if ($v['type'] == 1) {
                        $v['imageName'] = FactoryConfig::factoryImagePath($v['fid']);
                        $v['propId'] = FactoryConfig::propArr($v['propId'][0]);
                    }
                }else{
                    unset($data[$k]);
                }
            }
            return $data;
        }else{
            return false;
        }
    }

    /*
     * 查询工厂信息*/
    public static function selectFactoryInfo($server_region_pid)
    {
        $r = MyRedis::getConnectRead();
        $data = $r->hGet(Tab::Factory_Place,$server_region_pid);
        $r->close();
        if($data){
            return json_decode($data,true);
        }else{
            return false;
        }
    }


    /*
     * 变卖建筑操作金币*/
    public static function payFactory($tvmId, $iCon, $msg)
    {

        $money=FactoryConfig::Rate*$iCon;
        $result=UserCoinCore('plus', $tvmId, $money, $msg);
        if($result){
            return returnArr(200,array('price' =>$money),'success');
        }else{
            return returnArr(400,'','网络异常,请稍后尝试');
        }
    }


    /*
     * $data,工厂信息
     * 拼装数据*/
    public static function matchFact($data){
        $propArr=FactoryConfig::propArr();
        @$propT=$data['propId'];
        if(!$propT){
            return $data;
        }
        $tempArr=array();
        if(count($propT)<=1){
            $propArr[$propT[0]]['propId'] = $propT[0];
            array_push($tempArr, $propArr[$propT[0]]);
            $data['propId']=$tempArr;
        }else{
            foreach($data['propId'] as $v){
                $propArr[$v]['propId'] = $v;
                array_push($tempArr, $propArr[$v]);
            }
            $data['propId']=$tempArr;
        }
        return $data;
    }
    /*
     * 所有工厂集合*/
    public static function allFactory($pId_key, $facId, $type)
    {
        $db=Tab::Factory_All;
        $r=MyRedis::getConnect();
        if($type=='add'){
            $data=$r->hSet($db,$facId,$pId_key);
        }
        if($type=='isExist'){
            $data=$r->hExists($db,$facId);// true/false
        }
        if($type=='del'){
            $data=$r->hDel($db,$facId);
        }
        $r->close();
        return $data;
    }

    /*
     * @param type  add 上锁/ del解锁
     * 大楼所大楼锁*/
    public static function getFactoryLock($pid_key, $type, $time = 200)
    {
        $lockKey = "Factory_lock_".$pid_key;
        $r = MyRedis::getConnect();
        if($type == 'add'){
            //获取锁
            $isGetLock = false;
            for($i = 0; $i < FactoryConfig::RETRY_TIMES; $i++){
                $isGetLock = $r->set($lockKey,1,["NX","PX"=>$time]);
                if($isGetLock){
                    break;
                }
                usleep(FactoryConfig::RETRY_CD);
            }

            if(!$isGetLock){
                $r -> close();
                return returnArr(502,"","访问超时,请重试(0023)");
            }else{
                $r -> close();
                return returnArr(200,'','success');
            }
        }
        if($type == 'del'){
            if($r->exists($lockKey)){
                $r->del($lockKey);//释放锁
            }
            $r -> close();
            return returnArr(200,'','success');
        }
    }

    /*
     * 土地拍卖:更换工厂对应的用户关系
     * @param y_qid 失去地块的QID
     * @param g_qid 获得地块的QID
     * @param pid_key  地块ID serverId.'_'.$regionId.'_'.$pId
     * */
    public static function changeFactoryUser($y_qid, $g_qid, $serverId, $regionId, $pId)
    {
        $pId_key = $serverId.'_'.$regionId.'_'.$pId;

        $data = self::userPlace($y_qid,$pId_key, 'is_sel');

        if ($data){
            $r = MyRedis::getConnect();
            $result = $r->hGet(Tab::Factory_Place, $pId_key);
            $resultTmp = json_decode($result,true);

            $facId = $resultTmp['facId'];
            $fid = $resultTmp['fid'];
            $fType = $resultTmp['type'];
            $fGrade = $resultTmp['grade'];
            $log_type = 2; //日志type表示是出售的,出售价格price=0;
            $price = 0;

            self::userPlace($y_qid, $pId_key, 'del');

            opt_log_for_FactoryDel($serverId, $regionId, $pId, $y_qid, $facId, $price, $log_type);//失去建筑日志

            self::userPlace($g_qid, $pId_key, 'add');

            opt_log_for_FactoryAdd($serverId, $regionId, $pId, $g_qid, $facId, $fid, $fType, $fGrade, $log_type, $price); //获得建筑日志

            return true;
        }else{
            return false;
        }

    }


}