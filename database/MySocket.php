<?php
namespace database;
/**
 * Created by PhpStorm.
 * User: USER
 * Date: 2017/6/15
 * Time: 12:05
 */
Class MySocket{

    public function Mexecute(){
        /**
        linux 守护进程方式
        //chmod a+x SocketServer.php
        nohup php SocketServer.php

         php -q SocketServer.php //使用CLI模式运行
         *
        **/

    }

    public function open_test(){
        $fp=fsockopen("wwww.baidu.com",80,$errno,$errstr,30);
        if(!$fp){
            echo "$errstr($errno)";
        }else{
            $out = "GET / HTTP/1.1\n\n";
            //发送数据
            fwrite($fp,$out);
            //接收数据
            while(!feof($fp)){
                echo fgets($fp,128);
            }
            fclose($fp);
        }
    }

    //创建socket客户端 连接服务端 发送数据
    public function clent_socket(){
        //创建
        if(!($socket=socket_create(AF_INET,SOCK_STREAM,0))){
            $errorcode=socket_last_error();
            $errormsg=socket_strerror($errorcode);
            die("Couldn't create socket:[$errorcode]$errormsg\n");
        }
        echo "Socket is created \n";

        //连接
        if(!socket_connect($socket,'localhost',80)){
            $errorcode=socket_last_error();
            $errormsg=socket_strerror($errorcode);
            die("Could not connect:[$errorcode]$errormsg\n");
        }
        echo  "Connection established \n";

        //发送
         $message="GET / HTTP/1.1 \r\n\r\n";
        if(!socket_send($socket,$message,strlen($message),0)){
            $errorcode=socket_last_error();
            $errormsg=socket_strerror($errorcode);
            die("Couldn't send data:[$errorcode]$errormsg\n");
        }

        echo "Message send successfully \n";

    }

    //创建socket 服务端

     public function server_socket_tcp(){
         set_time_limit(0);
         $address="127.0.0.1";
         $port="6789";
         $socket=socket_create(AF_INET,SOCK_STREAM,0);//0参数为 SQL_TCP
         socket_bind($socket,0,$port) or die("Could not bind to address");//0适合用localhost
         socket_listen($socket);
         if(!$socket){
             echo "Failed to create socket !\n";
             exit;
         }
         while(true){
             $client=socket_accept($socket);
             $welcome="welcome to the my socket server.Type'!exit' to close this connect.or type '!die'to halt the server.";
             socket_write($client,$welcome);
             while(true){
                 $input =trim(socket_read($client,256));
                 if($input=="!exit"){
                     break;
                 }
                 if($input=="!die"){
                     socket_close($client);
                     break 2;
                 }
                 $output=strtoupper($input)."\n";
                 socket_write($client,$output);
                 echo input."\n";
             }
             socket_close($client);
         }
         socket_close($socket);
     }

//UDP服务端
    public function server_socket_udp(){
        error_reporting(E_WARNING);
        $socket=socket_create(AF_INET,SOCK_DGRAM,0);//0参数为 SQL_TCP
        if($socket){
            $errorcode=socket_last_error();
            $errormsg=socket_strerror($errorcode);
            $errorms=iconv("GB2312","UTF-8",$errormsg);
            die("Couldn't create socket:[$errorcode]$errormsg\n");
        }
        echo "Socket create success!";
        if(!socket_bind($socket,"127.0.0.1",9999)){
            $errorcode=socket_last_error();
            $errormsg=socket_strerror($errorcode);
            die("Couldn't bind socket:[$errorcode]$errormsg\n");
        }
        echo "Socket bind is OK";
        while(1){
            echo "Waiting for data...\n";
            //接受一些数据
            $r=socket_recvfrom($socket,$buf,512,0,$remote_ip,$remote_port);
            echo "$remote_ip:$remote_port ----".$buf;

            //返回数据给客户端
            socket_send($socket,"OK".$buf,100,0,$remote_ip,$remote_port);
        }
    }

    public function client_socket_udp(){
        error_reporting(E_WARNING);
        $server='127.0.0.1';
        $port=9999;
        $socket=socket_create(AF_INET,SOCK_DGRAM,0);//0参数为 SQL_TCP
        if($socket){
            $errorcode=socket_last_error();
            $errormsg=socket_strerror($errorcode);
            die("Couldn't create socket:[$errorcode]$errormsg\n");
        }
        echo  "Socket created\n";
        //循环处理通信
        while(1){
            //显示数据输入界面
            echo "Enter a message to send:";
            $input="";
            //发送信息给服务器端
            if(!socket_sendto($socket,$input,strlen($input),0,$server,$port)){
                $errorcode=socket_last_error();
                $errormsg=socket_strerror($errorcode);
                die("Couldn't send data:[$errorcode]$errormsg\n");
            }
            //现从服务器接收回复并打印
            if(socket_recv($socket,$reply,2045,MSG_WAITALL)===FALSE){
                $errorcode=socket_last_error();
                $errormsg=socket_strerror($errorcode);
                die("Couldn't receive data:[$errorcode]$errormsg\n");
            }
            echo "Reply:$reply";
        }
    }



}