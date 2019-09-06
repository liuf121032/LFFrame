<?php
//核心类库
namespace core;

class Bootstrap{

	public static function run(){
		self::parseUrl();
	}
	//分析URL生成控制器方法常量
	public static function parseUrl(){

		if(isset($_GET['m'])){
			$info=explode('/',$_GET['m']);
            $info[0] = ucfirst(strtolower($info[0]));
			$class=$class='\web\model\\'.$info[0].'Model';
			$action=$info[1];
			echo (new $class)->$action();
			exit;
		}else{
			if(isset($_GET['c'])){
				$info=explode('/',$_GET['c']);
				$dPath=get('d')?:'Index';
				if(self::check_file_controller($info[0],$dPath)){
//					$class='\web\controller\Index\\'.self::check_file_controller($info[0],$dPath);
					$class=self::check_file_controller($info[0],$dPath);
					$action=$info[1];
				}else{

					echo jsonOut(returnArr('110','','controller error'));exit;
				}
			}else{
				$class = "\web\controller\Index\IndexController";
				$action = "index";
			}
			 Router::router($class,$action);
		}
	}

//判断实例化的类文件是否存在
	private static function check_file_controller($file_name,$dPath){
		$file_name=strtolower($file_name);
		$file_name=ucfirst($file_name)."Controller";//首字母大写
		$dPath=ucfirst(strtolower($dPath));
		$Dir_controller=APP_NAME.'web'.DIRECTORY_SEPARATOR.'controller'.DIRECTORY_SEPARATOR.$dPath.DIRECTORY_SEPARATOR;
		$root_dir=$Dir_controller.$file_name.".php";

		if(file_exists($root_dir)){
			$class='\web\controller\\'.$dPath.'\\'.$file_name;
			return $class;
		}else{
			return false;
		}
	}

	public static function runCli($argv){
	    if(count($argv)<3){
	        exit("cli.php err: use like: cli.php controller function\r\n");
        }
        self::parseParam($argv);
    }

    public static function parseParam($argv){
        $class = self::checkConsoleController($argv[1],$argv[2]);
        $action = "index";
        if ($class){
            $action = $argv[2];
        }else{
            $class = "\console\IndexController";
        }
        $arvs = array();
        if(count($argv) > 3){
            for($i=3;$i<count($argv);$i++){
                $arvs[] = $argv[$i];
            }
        }
        Router::cliRouter($class,$action,$arvs);

    }

    private static function checkConsoleController($file_name,$dPath){
        $fileName = strtolower($file_name);
        $fileName=ucfirst($fileName)."Controller";
        $dPath=ucfirst(strtolower($dPath));
        $Dir_controller=APP_NAME.'console'.DIRECTORY_SEPARATOR;
        $root_dir=$Dir_controller.$fileName.".php";

        if(file_exists($root_dir)){
            $class='\console\\'.$fileName;
            return $class;
        }else{
            return false;
        }
    }
}

