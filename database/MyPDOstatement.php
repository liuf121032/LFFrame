<?php
namespace database;
/**
 * Created by PhpStorm.
 * User: liufeng
 * Date: 2017/12/12
 * Time: 下午6:17
 */
class MyPDOStatement extends PDOStatement{


    protected $_debugValues = null;

    protected function __construct()
    {
        // need this empty construct()!
    }

    public function execute($values=array())
    {
        $this->_debugValues = $values;
        try {
            $t = parent::execute($values);
            // maybe do some logging here?
        } catch (PDOException $e) {
            // maybe do some logging here?
            throw $e;
        }

        return $t;
    }

    public function _debugQuery($replaced=true)
    {
        $q = $this->queryString;

        if (!$replaced) {
            return $q;
        }

        return preg_replace_callback('/:([0-9a-z_]+)/i', array($this, '_debugReplace'), $q);
    }

    protected function _debugReplace($m)
    {
        $v = $this->_debugValues[$m[1]];
        if ($v === null) {
            return "NULL";
        }
        if (!is_numeric($v)) {
            $v = str_replace("'", "''", $v);
        }

        return "'". $v ."'";
    }

    /*demo*/
    static public function select($dbName , $tableName, $limit,$start,$pageSize,$name,$type)
    {
        $start=$start?:10;
        $pageSize=$pageSize?:10;
        $db=self::getConnect($dbName);
        $sth = $db->prepare("select name,age from $tableName where name =:NAME and type =:TYPE limit :Start,:PageSize");
        $sth->bindParam(':NAME',$name,\PDO::PARAM_STR,12);
        $sth->bindParam(':TYPE',$type);
        $sth->bindParam(':Start',$start);
        $sth->bindParam(':PageSize',$pageSize);
        $sth->execute();
        $data=$sth->fetchAll(\PDO::FETCH_ASSOC);
        $num=$sth->rowCount();//返回查询到的行数
        $list=$sth->columnCount();//返回字段

    }

    static public function select_two($dbName , $tableName, $limit,$name)
    {
        $limit=$limit?:10;
        $db=self::getConnect($dbName);
        $sth = $db->prepare("select name,age from $tableName where name =:NAME and type =:TYPE limit :LIMIT");
        $sth->execute(array(
            'NAME'=>$name,
            'TYPE'=>(int)2,

        ));
        $data=$sth->fetchAll(\PDO::FETCH_ASSOC);
        return $data;
    }



}