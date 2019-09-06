<?php
namespace web\model;

use database\MySql;
use database\Redis;
use config\Tab as Tab;

class IndexModel extends BaseModel{


    public function index()
    {
        $db=MySql::getConnect('quanming2');
        $sql="show tables";
        $sth=$db->prepare($sql);
        $sth->execute();
        $r=$sth->fetchAll(\PDO::FETCH_ASSOC);
        Tab::buildId;
        var_dump($r);
    }


    public function select()
    {

        $name=get('name');
        $type=get('type')?:2;
        $Start=(int)get('start')?:0;
        $PageSize=(int)get('page')?:10;
        try{
            $dbh=Mysql::getConnect('yjblog');
            $sql = "show tables";
            $sth = $dbh->prepare($sql);
            $sth->execute();
            $rs = $sth->fetch(\PDO::FETCH_ASSOC);
            echo "<pre>";
            print_r($rs);exit;

            $sth = $dbh->prepare('select id,name,age from user where name =:NAME and type =:TYPE limit :Start,:PageSize');
            $sth->bindParam(':NAME',$name,\PDO::PARAM_STR,12);
            $sth->bindParam(':TYPE',$type);
            $sth->bindParam(':Start',$Start,\PDO::PARAM_INT);
            $sth->bindParam(':PageSize',$PageSize,\PDO::PARAM_INT);
            $sth->execute();
            $data=$sth->fetchAll(\PDO::FETCH_ASSOC);
            $num=$sth->rowCount();
            $list=$sth->columnCount();//返回字
            return jsonout(returnArr(200,$data,'success'));
        }catch(Exception $e){
            echo $e->getMessage();
        }
    }

    public function insert()
    {
        $name=get('name')?:'Jim';
        $age=get('age')?:'18';
        $db=Mysql::getConnect('tmp_text');
        $sth=$db->prepare("INSERT INTO user (name,age) VALUES (:col1, :col2)");
        $sth->execute(array(
            'col1'=>$name,
            'col2'=>$age,
        ));
        $id=$db->lastInsertId();
        return $id;
    }




}