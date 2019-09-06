<?php
require_once("common" . DIRECTORY_SEPARATOR . "functions.php");
require_once("common" . DIRECTORY_SEPARATOR . "log.php");

function C($param = "Point")
{
  $path = APP_NAME . '.env.' . SERVER_TYPE . '.ini';
  $config_param = parse_ini_file($path);
  return $config_param[$param];
}

function dump($param)
{
  var_dump($param);
  exit;
}

function Qsubstr($string, $start, $length = null)
{
  return mb_substr($string, $start, $length, 'UTF-8');
}



/*
 * 游戏入口处调用
 * 第三方验证用户AppToken*/
function checkToken($tvmId, $AppToken, $nickName, $headimg)
{
  $path = C('checkToken_path');
  $data = getUserSession($AppToken, $tvmId);
  if (!$data) {
    $param = array('tvmid' => $tvmId, 'token' => $AppToken);
    $curl = new \Curl\Curl();
    $curl->setHeader('content-type', 'application/x-www-form-urlencoded');
    $curl->post($path, $param);
    if ($curl->error) {
      $error_message = $curl->error_message;
      $error_code = $curl->error_code;
      $response = $curl->response_headers;
      $param['response'] = $response;
      $param['code'] = $error_code;
      $param['msg'] = $error_message;
      $param['contents'] = "coin connect  error";
      return returnArr($error_code, $param, $error_message);
    } else {
      $data = $curl->response;
    }
    $curl->close();
    $data = json_decode($data, true);
    $dbAppToken = "apptoken_" . $tvmId;
    if (empty($data)) {  //返回空,大仙接口出错误,默认用户通过。
      $r = \database\MyRedis::getConnect();
      $r->set($dbAppToken, $AppToken);
      $r->expire($dbAppToken, 3 * 3600);
      $user_Info_db = "user_info";
      $userData = json_encode(array('nickname' => $nickName, 'headimg' => $headimg));
      $r->hSet($user_Info_db, $tvmId, $userData);
      $r->close();
      return array('tvmid' => $tvmId, 'nickname' => $nickName, 'headimg' => $headimg);
    }
    if ($data['status'] == '200') {
      $r = \database\MyRedis::getConnect();
      $r->set($dbAppToken, $AppToken);
      $r->expire($dbAppToken, 3 * 3600);
      $user_Info_db = "user_info";
      $userData = json_encode(array('nickname' => $nickName, 'headimg' => $headimg));
      $r->hSet($user_Info_db, $tvmId, $userData);
      $r->close();
      return array('tvmid' => $tvmId, 'nickname' => $nickName, 'headimg' => $headimg);
    } else {
      return false;
    }
  } else {
    return $data;
  }
}

/*
 * Qid计算唯一算法*/
function mergeTvmId($tvmId)
{
  $md5_tvmId = md5($tvmId);
  preg_match_all('/\d+/', $md5_tvmId, $arrTvmSum);
  $sumTo = '';
  foreach ($arrTvmSum[0] as $sum) {
    $sumTo .= (int)$sum;
  }
  $tvmIdSum = $tvmId . $sumTo;
  $New_md5_tvmId = md5($tvmIdSum);
  $tmpQidOne = substr($New_md5_tvmId, 0, 8);
  $tmpQidOne1 = substr($New_md5_tvmId, 12, 8);
  $tmpQidOne2 = substr($New_md5_tvmId, -8);
  $Qid = 'qid' . $tmpQidOne . $tmpQidOne1 . $tmpQidOne2;
  return $Qid;
}

/*
 * 生成全局的Qid*/
function getQid($tvmId)
{
  $r = \database\MyRedis::getConnect();
  $db = 'tvmid_qid';      //tvmId 查询Qid
  $dbTow = 'qid_tvmid';
  $dbThr = 'qid_redid';
  $dbFour = 'tvmid_time'; //tvmId 查询用户进入游戏时间
  try {
    if (strlen($tvmId) < 25 || strlen($tvmId) > 35) throw new \Exception('非法tvmid');
    if (!preg_match("/^[a-z\d]*$/i", $tvmId)) throw new \Exception('非法tvmid');
    if (!$r->ping()) throw new \Exception('网络异常');
    if ($r->hExists($db, $tvmId)) {
      $Qid = $r->hGet($db, $tvmId);
    } else {
      $Qid = mergeTvmId($tvmId);
      $result = $r->hSet($db, $tvmId, $Qid);
      $resultTow = $r->hSet($dbTow, $Qid, $tvmId);
      $resultTow = $r->hSet($dbFour, $tvmId, date("Y-m-d H:i:s", time()));
      $optLog = "11\t" . $Qid . "\t" . $tvmId . "\t" . getMillisecond();
      opt_log_for_produce($optLog); // 推日志
    }
    if (!$r->hExists($dbThr, $Qid)) {
      getUserRedId($tvmId);
    }
    $r->close();
    return $Qid;
  } catch (\Exception $e) {
    $msg = $e->getMessage();
    $r->close();
//        mLogs('getQid','func getQid',$msg);
    return false;
  }
}

/*通过Qid查询tvmId*/
function getTvmId($Qid)
{
  try {
    $dbTow = 'qid_tvmid';
    $r = \database\MyRedis::getConnect();
    $tvmId = $r->hGet($dbTow, $Qid);
    $r->close();
    if (!$tvmId) throw new \Exception("服务器内部错误");
    return $tvmId;
  } catch (\Exception $e) {
    $msg = $e->getMessage();
//        mLogs('getTvmId','func getTvmId',$msg);
    return false;
  }
}

/*
 * 通过Qid查询redId*/
function getRedId($Qid)
{
  try {
    $dbThr = 'qid_redid';
    $r = \database\MyRedis::getConnectRead();
    $RedId = $r->hGet($dbThr, $Qid);
    $r->close();
    if (!$RedId) throw new \Exception("服务器内部错误");
    return $RedId;
  } catch (\Exception $e) {
    $msg = $e->getMessage();
//        mLogs('getTvmId','func getRedId',$msg);
    return false;
  }
}

/*  打工专用金币操作接口
 * param @fTvmId 出金币
 * param @tTvmId 入金币
 * param @money 钱数
 * param @poundage  默认值:0 手续费
 * param @desc 描述
 * param @type [minus 减少 工厂主发布招工信息,往主账户充值
 *              plus  增减  打工仔来打工,主账户给打工仔付工资 ]
 * */
function tradeMainToMain($tvmid, $money, $desc, $type)
{
  if ($type == 'minus') {   //工厂主给主账户充钱
    $fTvmId = $tvmid;
    $tTvmId = 'dalou2018001666201802';
  } elseif ($type == 'plus') {  //主账户给打工仔付钱
    $fTvmId = 'dalou2018001666201802';
    $tTvmId = $tvmid;
  }
  $path = 'http://10.66.103.192/gold-interface-dfw/multi_gold_trade/mgt'; //正式地址
  $signKey = '10059d4ceefa51dd21d00898d891f58d'; //正式key

  //测试key 测试地址
// $path=http://qa.yaogame.games.yaotv.tvm.cn/gold-interface-dfw/multi_gold_trade/mgt
// $signKey=0563170786784b2ac76c99f1d99bffa9
  $signTime = getMillisecond();
  $signStr = md5($fTvmId . ":" . $signKey . ':' . $signTime);
  $param = array(
    'source_id' => 62,
    'source_tvmid' => $fTvmId,
    'source_account_name' => 'main',
    'source_description' => $desc,
    'trade_gold' => $money,
    'dest_tvmid' => $tTvmId,
    'dest_account_name' => 'main',
    'dest_description' => $desc,
    'sigtime' => $signTime,
    'sig' => $signStr,
    'poundage' => 0
  );
  $curl = new \Curl\Curl();
  $curl->setHeader('content-type', 'application/x-www-form-urlencoded');
  $curl->post($path, json_encode($param));
  if ($curl->error) {
    $error_message = $curl->error_message;
    $error_code = $curl->error_code;
    $response = $curl->response_headers;
    $param['response'] = $response;
    $param['code'] = $error_code;
    $param['msg'] = $error_message;
    $param['contents'] = "coin connect  error";
    return returnArr($error_code, $param, $error_message);
  } else {
    $data = $curl->response;
  }
  $curl->close();
  $data = json_decode($data, true);
  if ($data['status'] == 'success') {
    return returnArr(200, '', 'success');
  } else {
    return returnArr(400, '', 'error');
  }
}






