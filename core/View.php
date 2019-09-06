<?php
namespace core;

class View{
    //模板文件
    protected  $file;
    //模板变量
    protected $vars=[ ];

    protected $getFile;

    public function getFile($file){
        $this->getFile=ucfirst(mb_strtolower($file));
    }

    public function view($file=null,$arr=null){
        header("Content-Type: text/html;charset=Utf-8");
        $this->file='view/'.$this->getFile.'/'.$file.'.html';
        if(count($arr)!=null){
            foreach($arr as $v=>$k){
                $this->vars[$v]=$k;
            }
        }
        return $this;
    }

    public function make($class,$file=null){
         $this->file='view/'.$class.'/'.$file.'.html';
         return $this;
    }
    public function with($arr){
        header("Content-Type: text/html;charset=Utf-8");
        if(count($arr)!=null){
            foreach($arr as $v=>$k){
                $this->vars[$v]=$k;
            }
        }
        return $this;
    }

     public function __toString()
    {
        extract($this->vars); //分配到内存中
        if(!is_file($this->file))
        $this->file='view\Index/index.html';
        $file_names=str_replace('\\', '/', $this->file);
            require_once($file_names);
        return '';
    }
}