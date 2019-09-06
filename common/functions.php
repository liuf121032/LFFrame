<?php

function pre($param=null)
{
    echo "<pre>".print_r($param,true)."</pre>";
}

function br()
{
    echo "&nbsp;&nbsp;&nbsp;<font color='blue'>↓↓↓</font><br><br>";
}

function gameOff()
{
    $type=SERVER_TYPE;
    if($type!='qa'){
        echo jsonout(returnArr('500','','系统维护中.....'));exit;
    }else{
       return true;
    }
}

//获取当天剩下多少秒
function reSecond()
{
    $t=time();
    $end=mktime(23,59,59,date("m",$t),date("d",$t),date("Y",$t));
    $second=$end-$t;
    return $second;
}

function getLastError()
{ //捕获程序异常终端
    $error=error_get_last();
    $log_content=date("Y-m-d H:i:s").'---[Shutdown]---'.PHP_EOL.
        'Error:'.var_export($error,true).PHP_EOL;
    mLogs('Info',"getLastError",$log_content);
}

/**
 *  curl 模拟form 提交
 * @param string $url 提交地址
 * @param array $data 请求参数
 * @return array
 */
function postForm( $url, array $data )
{
    $ch = curl_init();
    $options = array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLINFO_HEADER_OUT => true, //Request header
        CURLOPT_HEADER => false, //Return header
        CURLOPT_SSL_VERIFYPEER => false, //Don't verify server certificate
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => array( "Content-type: multipart/form-data" ) //post form
    );

    curl_setopt_array( $ch, $options );
    $_return = array( 'err' => false, 'data' => array(), 'header' => array() );
    $_return[ 'data' ] = curl_exec( $ch );
    if ( !$_return[ 'data' ] ) {
        $_return[ 'err' ] = curl_error( $ch );
    }
    $_return[ 'header' ] = curl_getinfo( $ch );
    curl_close( $ch );
    return $_return;
}

function jsonOut($rs)
{
    // header("Access-Control-Allow-Origin:*");
    header("Content-type", "application/json");
    header("Content-type:text/html;charset=utf-8");
    if (is_array($rs)) {
        $rs = json_encode($rs, JSON_UNESCAPED_UNICODE);
    }
    return $rs;
}

function post($val)
{
    if(!empty($_POST[$val])){
        return addslashes($_POST[$val]);
    }else{
        return false;
    }
}

function get($val)
{
    if(!empty($_GET[$val])){
        return addslashes($_GET[$val]);
    }else{
        return false;
    }
}

function returnArr($status, $data, $msg)
{
    $arr=array('code'=>(int)$status,'data'=>$data,'msg'=>$msg);
    return $arr;
}

/*
 * 获取用户红包ID*/
function getUserRedId($tvmId)
{
    $path = 'http://rts-opa.yaotv.tvm.cn/userinfo/appget';
    $param=array(
        'tvmid'=>$tvmId,
    );
    $curl=new \Curl\Curl();
    $curl->get($path,$param);
    if($curl->error){
        $respones_str= $curl->error_message;
        $curl->close();
        mLogs('Info','getUserRedId',array('msg'=>$respones_str));
        return false;
    }else{
        $data=$curl->response;
        $curl->close();
    }
    $data=json_decode($data,true);
    if($data['status']){
        $redId = $data['data']['tvm_red_id'];
        $r=\database\MyRedis::getConnect();
        $db='tvmid_qid';
        $qid = $r->hGet($db, $tvmId);
        $dbThr = 'qid_redid';
        $r->hSet($dbThr,$qid,$redId);
        $r->close();
        return $redId;
    }else{
        return false;
    }
}

/**
 * 用红包Id获取用户的tvmid
 * @param $redId
 * @return bool
 */
function getTvmidByRedid($redId){
    $url = C("GET_TVMID_BY_REDID");
    $param=array(
        'inviteCode'=>$redId,
    );

    $curl = new \Curl\Curl();
    $curl->get($url,$param);
    if($curl->error){
        $errMsg= $curl->error_message;
        return false;
    }
    $result = $curl->response;
    $curl->close();
    $data = json_decode($result,true);
    if(!empty($data["status"]) && $data["status"] == 200){
        if(!empty($data["data"]["tvmid"])) {
            $tvmId = $data["data"]["tvmid"];
            return $tvmId;
        }
    }
    return false;
}

/*
 * type [minus 减少,plus 增减]
 * 用户金币操作*/
function UserCoinCore($type, $tvmId, $money, $msg){
    if($type =='minus'){
        $path =C('balance_path')."/minus_user_coin";//购买建筑
    }elseif($type =='plus'){
        $path = C('balance_path')."/plus_user_coin";//变卖建筑
    }
    $time = time();
    $sig = balanceSig($tvmId ,$money, $time);
    $param = array(
        'tvmid' => $tvmId,
        'coin' => $money,
        'sigtime' => $time,
        'sig' => $sig,
        'msg' => $msg
    );
    $curl = new \Curl\Curl();
    $curl->setHeader('content-type','application/x-www-form-urlencoded');
    $curl->setOpt(CURLOPT_SSL_VERIFYPEER,false);
    $curl->setOpt(CURLOPT_SSL_VERIFYHOST,false);
    $curl->post($path, $param);
    if($curl->error){
        $error_message = $curl->error_message;
        $error_code = $curl->error_code;
        $response = $curl->response_headers;
        $param['response'] = $response;
        $param['code'] = $error_code;
        $param['msg'] = $error_message;
        $param['contents'] = "coin connect  error";
        $curl->close();
        return returnArr($error_code, $param, $error_message);
    }else{
        $data = $curl->response;
        $curl->close();
    }
    $data = json_decode($data,true);
    if($data['status']){
        $arr = returnArr(200,'','success');
    }else {
        $arr = returnArr(400,'','网络延时错误');
    }
    return $arr;
}

/*
 * 金币查询,main主账户金币，返回的单位是:个.多少个金币*/
function selectCoin($tvmId)
{
    $curl=new \Curl\Curl();
    $path=C('icon_path');
    $param=array(
        'tvmid'=>$tvmId
    );
    $curl->get($path,$param);
    if($curl->error){
        $curl->close();
        $result_str= $curl->error_message;
        mLogs('','selectCoin',array('data'=>$result_str));
        return false;
    }else{
        $data=$curl->response;
        $curl->close();
    }
    if($data){
//        $data = array('status'=>'success','data'=>array('main'=>1000000,'extra'=>'1219000'));
//        return $data;
        return json_decode($data,true);

    }else{
        return false;
    }
}

//余额操作秘钥
function balanceSig($tvmid, $money, $time)
{
    $sign_str=$tvmid."|".$money."|".$time."|".'tvm@MD5*pwd-key';
    return hash('md5',$sign_str);
}

//余额查询秘钥
function balanceSelSig($sigExpire, $tvmId, $yyyAppId)
{
    $str='yyyappid='.$yyyAppId.'&openid='.$tvmId.'&sigExpire='.$sigExpire.'&rkey=tvmining654321';
    return hash('md5',hash('md5',$str));
}

/*
 * @param [string] rank     专用redis指定的
 * @param [string] funcName 调用的方法,锁定方法
 * @param [array]  message  日志描述
 * @param [string] logfile  需要单独的日志文件
 *
 * 写日志文件*/
function mLogs($rank='Info', $funName="Info", $message,$logfile=null)
{
    $fun_arr=array("fun_name"=>$funName);
    if(is_array($message)){
        $message=array_merge($fun_arr,$message);
    }else{
        $message=array_merge($fun_arr,array('message'=>$message));
    }
    $today=date("Y-m-d",time());
    if($rank!='Info'){
        $filename=APP_NAME."logs".DIRECTORY_SEPARATOR.$rank.$today.".log";
    }else{
        $filename=APP_NAME."logs".DIRECTORY_SEPARATOR.$today.".log";
    }

    if($logfile !=  null ){
        $filename = APP_NAME."logs".DIRECTORY_SEPARATOR.$logfile."_".$today.".log";
    }

    $message='['.$rank.'] '.date("Y-m-d H:i:s",time())." | ".json_encode($message,JSON_UNESCAPED_UNICODE)."\r\n";

    if(file_exists($filename)){
        file_put_contents($filename,$message,FILE_APPEND);
    }else{
        $logPath = $filename;
        if ( !is_dir( dirname( $logPath ) ) ) {
            mkdir( dirname( $logPath ), 0777 );
        }
        if ( !file_exists( $logPath ) ) {
            touch( $logPath, 0777 );
        }
        file_put_contents($logPath,$message,FILE_APPEND);
    }
}

//获取毫秒数
function getMillisecond()
{ //给JAVA计划任务使用必须使用整数类型
    list($t1, $t2) = explode(' ', microtime());
    $t=$t2.substr($t1,2,3);
    return (int)$t;
}


//判断用户是否正确
function getUserSession($Apptoken, $tvmId)
{
    $dbAppToken = "apptoken_".$tvmId;
    $user_Info_db = "user_info";
    $r = \database\MyRedis::getConnect();
    $redis_token=$r->get($dbAppToken);
    if(empty($redis_token)){
        $r->close();
        return false;
    }
    if($redis_token == $Apptoken){
        $data = $r->hGet($user_Info_db,$tvmId); //取用户信息
        if($data){
            $r->close();
            return json_decode($data,true);
        }else{
            $userInfo = getUserInfo($tvmId);
            $r->hSet($user_Info_db,$tvmId,json_encode($user_Info_db));
            $r->close();
            return $userInfo;
        }
    }else{
        $r->close();
        return false;
    }
}


function getUserInfo($tvmId){
    $path = 'http://10.66.102.40/userinfo/appget';
    $param=array(
        'tvmid'=>$tvmId,
        'systoken'=>'bDFfDIC'
    );
    $curl=new \Curl\Curl();
    $curl->get($path,$param);
    if($curl->error){
        $curl->close();
        $result_str= $curl->error_message;
        mLogs('','selectCoin',array('data'=>$result_str));
        return false;
    }else{
        $data=$curl->response;
        $curl->close();
    }
    $data =json_decode($data,true);
    if($data['status']){
        $userInfo['headimg'] = $data['data']['head_img'];
        $userInfo['nickname'] = $data['data']['nickname'];
        return $userInfo;
    }else{
        return false;
    }
}


//订单号生成唯一
function orderNumber()
{
    $db = "Order_number_transaction";
    $key = date("Ymd", time());
    $r = \database\MyRedis::getConnect();
    $num = $r->hIncrBy($db,$key,1);
    $tmp_num = 100000000;
    $new_num = $tmp_num+$num;
    $real_num = substr($new_num,1);
    return $key.$real_num;
}

//sign钥匙
 function makeSign($params)
 {
     $today = date('Y-m-d');
     $key = 'GameTow'.$today;
     $arr_key = array('key'=>$key);
     $param = array_merge($params,$arr_key);
     ksort($param);
     $str_params = http_build_query($param);
     $sign = md5(sha1($str_params));
     return $sign;
 }

/**
 * 广告签名
 * @param tvmId
 * @param timestamp
 * @return sha1
 */
function getAdToken($params=array()){
    if(empty($params["tvmId"]) || empty($params["timestamp"])){
        return false;
    }

//    过期时间 30s
    $exTime = 30;
    $nt = time();


    if((int)$params["timestamp"] > 1500000000000){
        $nt = getMillisecond();
        $exTime = 30000;
    }


    if($nt - (int)$params["timestamp"] > $exTime){
        return false;
    }

    $key = C("AD_TOKEN_KEY");

    $tokenStr = $params["tvmId"].$key.$params["timestamp"];

    return sha1($tokenStr);
}

function ckAdToken($params=array()){
    $res = array("result"=>true,"msg"=>"");
    if(empty($params["tvmId"]) || empty($params["timestamp"])){
        $res["result"] = false;
        $res["tvmId"] = @$params["tvmId"];
        $res["sig_time"] = @$params["timestamp"];
        $res["msg"] = "AD参数错误";
        return $res;
    }

//    过期时间 30s
    $exTime = 30;
    $nt = time();


    if((int)$params["timestamp"] > 1500000000000){
        $nt = getMillisecond();
        $exTime = 30000;
    }


    if($nt - (int)$params["timestamp"] > $exTime){
        $res["result"] = false;
        $res["tvmId"] = @$params["tvmId"];
        $res["msg"] = "AD签名过期";
        $res["sig_time"] = (int)$params["timestamp"];
        $res["now_time"] = $nt;
        $res["time_gap"] = $nt - (int)$params["timestamp"];
        return $res;
    }

    $key = C("AD_TOKEN_KEY");

    $tokenStr = $params["tvmId"].$key.$params["timestamp"];

    $adSig = sha1($tokenStr);
    if($adSig != $params["adToken"]){
        $res["result"] = false;
        $res["tvmId"] = @$params["tvmId"];
        $res["msg"] = "AD签名错误";
        $res["sig_time"] = (int)$params["timestamp"];
        $res["param_sig"] = $params["adToken"];
        $res["local_sig"] = $adSig;
        return $res;
    }

    return $res;
}

 //
function uuid() {
    $charid = md5(uniqid(mt_rand(), true));
    $hyphen = chr(45);// "-"
    $uuid = chr(123)// "{"
        .substr($charid, 0, 8).$hyphen
        .substr($charid, 8, 4).$hyphen
        .substr($charid,12, 4).$hyphen
        .substr($charid,16, 4).$hyphen
        .substr($charid,20,12)
        .chr(125);// "}"
    return $uuid;
}
//生成主键id
function keyGen() {
    return str_replace('-','',substr(uuid(),1,-1));
}

// curl
function curlUrl($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
/*
 * @param tvmId     用户ID
 * @param cardName  原材料名称
 * @param cardNum   材料数量
 * 全名买楼推送消息*/
function pushMsgDSHB($tvmId,$message)
{
    $path = "http://msg.apps.tvm.cn/app/sysMessage/message";


    $param = array(
        'message' => $message,
        'msgType' => "-1",
        'fromPeer' => 'tvhMD_5a73e1bc16c3a45f503e8357',
        'toPeers' => array($tvmId),
    );
    $curl = new \Curl\Curl();
    $curl->setHeader('content-type','application/x-www-form-urlencoded');
    $curl->post($path, $param);
    if($curl->error){
        $error_message = $curl->error_message;
        $error_code = $curl->error_code;
        $response = $curl->response_headers;
        $param['response'] = $response;
        $param['code'] = $error_code;
        $param['msg'] = $error_message;
        $param['contents'] = "coin connect  error";
        $curl->close();
        return returnArr($error_code, $param, $error_message);
    }else{
        $data = $curl->response;
        $curl->close();
    }
    $data = json_decode($data,true);
    if($data['status']){
        $arr = returnArr(200,'','success');
    }else {
        $arr = returnArr(400,'','网络延时错误');
    }
    return $arr;
}

/**
 * 验证码校验 陈杰
 */
function ck_verify_code($t,$v){

    $url = "http://vcode.yaotv.tvm.cn/code/charSelect/valid";
    $param = array(
        'validateType' => 5,
        't' => $t,
        'v' => $v,
    );
    $curl = new \Curl\Curl();
    //$curl->setHeader('content-type','application/json');
    $curl->post($url, $param);

    if($curl->error){
        $error_message = $curl->error_message;
        $error_code = $curl->error_code;
        $response = $curl->response_headers;
        $param['response'] = $response;
        $param['code'] = $error_code;
        $param['msg'] = $error_message;
        $param['contents'] = "verify code connect  error";
        $curl->close();
        return returnArr($error_code, $param, $error_message);
    }else{
        $data = $curl->response;
        $curl->close();
    }
    $data = json_decode($data,true);

    if($data['success'] == true){
        $arr = returnArr(200,'','success');
    }else {
        $arr = returnArr(403,'',$data['code']);
    }
    return $arr;
}

//获取当天剩下多少秒
function re_second(){
    $t=time();
    $end=mktime(23,59,59,date("m",$t),date("d",$t),date("Y",$t));
    $second=$end-$t;
    return $second;
}








