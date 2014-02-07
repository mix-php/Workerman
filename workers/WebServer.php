<?php
require_once WORKERMAN_ROOT_DIR . 'man/Core/SocketWorker.php';
require_once WORKERMAN_ROOT_DIR . 'applications/Common/Protocols/Http/Http.php';

/**
 * 
 *  WebServer
 *  HTTP协议
 *  
 * @author walkor <worker-man@qq.com>
 */
class WebServer extends Man\Core\SocketWorker
{
    /**
     * 缓存最多多少静态文件
     * @var integer
     */
    const MAX_CACHE_FILE_COUNT = 100;
    
    /**
     * 大于这个值则文件不缓存
     * @var integer
     */
    const MAX_CACHE_FILE_SIZE = 300000;
    
    /**
     * 缓存静态文件内容
     * @var array
     */
    public static $fileCache = array();
    
    /**
     * 默认mime类型
     * @var string
     */
    protected static $defaultMimeType = 'text/html; charset=utf-8';
    
    /**
     * 服务器名到文件路径的转换
     * @var array ['workerman.net'=>'/home', 'www.workerman.net'=>'home/www']
     */
    protected static $serverRoot = array();
    
    /**
     * mime类型映射关系
     * @var array
     */
    protected static $mimeTypeMap = array();
    
    
    /**
     * 进程启动的时候一些初始化工作
     * @see Man\Core.SocketWorker::onStart()
     */
    public function onStart()
    {
        // 初始化HttpCache
        App\Common\Protocols\Http\HttpCache::init();
        // 初始化mimeMap
        $this->initMimeTypeMap();
        // 初始化ServerRoot
        $this->initServerRoot();
    }
    
    /**
     * 初始化mimeType
     * @return void
     */
    public function initMimeTypeMap()
    {
        $mime_file = \Man\Core\Lib\Config::get($this->workerName.'.include');
        if(!is_file($mime_file))
        {
            $this->notice("$mime_file mime.type file not fond");
            return;
        }
        $items = file($mime_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if(!is_array($items))
        {
            $this->notice("get $mime_file mime.type content fail");
            return;
        }
        foreach($items as $content)
        {
            if(preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match))
            {
                $mime_type = $match[1];
                $extension_var = $match[2];
                $extension_array = explode(' ', substr($extension_var, 0, -1));
                foreach($extension_array as $extension)
                {
                    self::$mimeTypeMap[$extension] = $mime_type;
                } 
            }
        }
    }
    
    /**
     * 初始化ServerRoot
     * @return void
     */
    public  function initServerRoot()
    {
        self::$serverRoot = \Man\Core\Lib\Config::get($this->workerName.'.root');
    }

    /**
     * 确定数据是否接收完整
     * @see Man\Core.SocketWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return App\Common\Protocols\Http\http_input($recv_str);
    }

    /**
     * 数据接收完整后处理业务逻辑
     * @see Man\Core.SocketWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
         // http请求处理开始。解析http协议，生成$_POST $_GET $_COOKIE
        App\Common\Protocols\Http\http_start($recv_str);
       
        // 请求的文件
        $file = $_SERVER['REQUEST_URI'];
        $pos = strpos($file, '?');
        if($pos !== false)
        {
            // 去掉文件名后面的querystring
            $file = substr($file, 0, $pos);
        }
        
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        if($extension == '')
        {
            $dir_name = $file == '/' ? '' : $file;
        }
        else 
        {
            $dir_name = pathinfo($file, PATHINFO_DIRNAME);
        }
        
        // rootDir
        $root_dir = isset(self::$serverRoot[$_SERVER['HTTP_HOST']]) ? self::$serverRoot[$_SERVER['HTTP_HOST']] : current(self::$serverRoot);
        
        // 得到文件真实路径
        $file = "$root_dir/$file";
        
        // 命中缓存，直接返回
        if(isset(self::$fileCache[$file]) )
        {
                $file_content = self::$fileCache[$file];
                // 发送给客户端
                return $this->sendToClient(App\Common\Protocols\Http\http_end($file_content));
        }
        
        if(!is_file($file))
        {
            // 从定向到index.php
            $file = $root_dir.'/'.$dir_name.'/index.php';
            $extension = 'php';
        }
        
        // 请求的文件存在
        if(is_file($file))
        {
            // 如果请求的是php文件
            if($extension == 'php')
            {
                // 缓冲输出
                ob_start();
                // 载入php文件
                try 
                {
                    // $_SERVER变量
                    $_SERVER['SCRIPT_NAME'] = str_replace($root_dir, '', $file);
                    $_SERVER['REMOTE_ADDR'] = $this->getRemoteIp();
                    $_SERVER['REMOTE_PORT'] = $this->getRemotePort();
                    $_SERVER['SERVER_ADDR'] = $this->getLocalIp();
                    $_SERVER['DOCUMENT_ROOT'] = $root_dir;
                    $_SERVER['SCRIPT_FILENAME'] = $file;
                    include $file;
                }
                catch(\Exception $e) 
                {
                    // 如果不是exit
                    if($e->getMessage() != 'jump_exit')
                    {
                        echo $e;
                    }
                }
                $content = ob_get_clean();
                $buffer = App\Common\Protocols\Http\http_end($content);
                $this->sendToClient($buffer);
                // 执行php每执行一次就退出(原因是有的业务使用了require_once类似的语句，不能重复加载业务逻辑)
                //return $this->stop();
            }
            
            // 请求的是静态资源文件
            if(isset(self::$mimeTypeMap[$extension]))
            {
                App\Common\Protocols\Http\header('Content-Type: '. self::$mimeTypeMap[$extension]);
            }
            else 
            {
                App\Common\Protocols\Http\header('Content-Type: '. self::$defaultMimeType);
            }
            
            // 获取文件信息
            $info = stat($file);
            
            $modified_time = $info ? date('D, d M Y H:i:s', $info['mtime']) . ' GMT' : '';
            
            // 如果有$_SERVER['HTTP_IF_MODIFIED_SINCE']
            if(!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $info)
            {
                // 文件没有更改则直接304
                if($modified_time === $_SERVER['HTTP_IF_MODIFIED_SINCE'])
                {
                    // 304
                    App\Common\Protocols\Http\header('HTTP/1.1 304 Not Modified');
                    // 发送给客户端
                    return $this->sendToClient(App\Common\Protocols\Http\http_end(''));
                }
            }
            
            if(!isset(self::$fileCache[$file]) )
            {
                $file_content = file_get_contents($file);
                // 缓存文件
                if($info['size'] < self::MAX_CACHE_FILE_SIZE && $file_content)
                {
                    self::$fileCache[$file] = $file_content;
                    // 缓存满了删除一个文件
                    if(count(self::$fileCache) > self::MAX_CACHE_FILE_COUNT)
                    {
                        // 删除第一个缓存的文件
                        reset(self::$fileCache);
                        unset(self::$fileCache[key(self::$fileCache)]);
                    }
                }
            }
            else
            {
                $file_content = self::$fileCache[$file];
            }
            
            if($modified_time)
            {
                App\Common\Protocols\Http\header("Last-Modified: $modified_time");
            }
            
            // 发送给客户端
           return $this->sendToClient(App\Common\Protocols\Http\http_end($file_content));
        }
        else 
        {
            // 404
            App\Common\Protocols\Http\header("HTTP/1.1 404 Not Found");
            return $this->sendToClient(App\Common\Protocols\Http\http_end(''));
        }
    }
}