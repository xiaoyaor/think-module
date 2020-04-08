<?php
declare(strict_types=1);

namespace think\module;

use easyadmin\Http;
use think\facade\Db;
use think\Exception;
use think\facade\Env;
use think\Route;
use think\helper\Str;
use think\facade\Config;
use think\facade\Lang;
use think\facade\Cache;
use think\facade\Event;
use think\module\middleware\Module;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * 插件服务
 * Class Service
 * @package think\module
 */
class Service extends \think\Service
{
    //存放插件列表数据
    protected $data=[];
    //存放应用下模块列表数据
    protected $module_data=[];
    protected $module_path;
    //插件文件存放文件夹
    protected static $addon_dir='addon';
    //缓存文件夹，插件关闭后可删除
    protected static $cache_dir='cache';
    //存放插件与系统冲突的文件
    protected static $conflict_dir='cache/conflict/';
    //网站数据库文件存放文件夹
    protected static $mysql_dir='mysql';

    /**
     * 注册服务
     */
    public function register()
    {
        // 插件目录
        define('ADDON_PATH', root_path() . 'addons' . DIRECTORY_SEPARATOR);
        // 无则创建addons目录
        $this->module_path = $this->getAddonsPath();
        // 加载系统语言包
        Lang::load([
            $this->app->getRootPath() . '/vendor/xiaoyaor/think-module/src/lang/zh-cn.php'
        ]);
        // 1.自动载入插件
        $this->autoload();
        // 2.加载插件事件
        $this->loadEvent();
        // 3.加载插件系统服务
        $this->loadService();
        // 4.绑定插件容器
        $this->app->bind('module', Service::class);
    }

    /*
     * 启动服务
     */
    public function boot()
    {
        //注册插件路由
        $this->registerRoutes(function (Route $route) {
            // 插件路由
            $execute = '\\think\\addons\\Route::execute';
            $route->rule(Config::get('easyadmin.addons_url_prefix')."/:addon/[:controller]/[:action]", $execute)
                ->middleware(Addons::class);
        });
        //注册应用路由
        $this->registerRoutes(function (Route $route) {
            // 应用路由
            $execute = '\\think\\addons\\AppRoute::execute';
            $route->rule(Config::get('easyadmin.app_url_prefix')."/:addon/[:module]/[:controller]/[:action]", $execute)
                ->middleware(Addons::class);
        });
        //批量注册应用后台路由
        $this->registerRoutes(function (Route $route) {
            // 应用后台路由
            $execute = '\\think\\module\\BackendRoute::execute';
            foreach ($this->data as $key=>$value){
                $route->rule($value."/[:controller]/[:action]", $execute)
                    ->middleware(Addons::class);
            }
        });
        //注册应用模块路由
        $this->registerRoutes(function (Route $route) {
            // 应用路由
            $execute = '\\think\\module\\MouduleAppRoute::execute';
            $route->rule(Config::get('easyadmin.app_url_prefix')."/:addon/module/:app/[:module]/[:controller]/[:action]", $execute)
                ->middleware(Addons::class);
        });
        //批量注册应用模块后台路由
        $this->registerRoutes(function (Route $route) {
            // 应用后台路由
            $execute = '\\think\\module\\MouduleBackendRoute::execute';
            foreach ($this->data as $key=>$value){
                $route->rule($value."/[:controller]/[:action]", $execute)
                    ->middleware(Addons::class);
            }
        });
        //注册自定义路由
        self::addons_route();
    }

    /**
     * 自定义路由
     * @return string
     */
    private function addons_route()
    {
        $this->registerRoutes(function (Route $route) {
            // 自定义路由
            $routes = (array) Config::get('addons.route', []);
            // 应用路由
            $execute = '\\think\\module\\AppRoute::execute';
            foreach ($routes as $key => $val) {
                if (!$val) {
                    continue;
                }
                if (is_array($val)) {
                    $domain = $val['domain'];
                    $rules = [];
                    foreach ($val['rule'] as $k => $rule) {
                        [$addon, $controller, $action] = explode('/', $rule);
                        $rules[$k] = [
                            'addon'        => $addon,
                            'controller'    => $controller,
                            'action'        => $action,
                            'indomain'      => 1,
                        ];
                    }
                    $route->domain($domain, function () use ($rules, $route, $execute) {
                        // 动态注册域名的路由规则
                        foreach ($rules as $k => $rule) {
                            $route->rule($k, $execute)
                                ->name($k)
                                ->completeMatch(true)
                                ->append($rule);
                        }
                    });
                } else {
                    list($addon, $controller, $action) = explode('/', $val);
                    $route->rule($key, $execute)
                        ->name($key)
                        ->completeMatch(true)
                        ->append([
                            'addon' => $addon,
                            'controller' => $controller,
                            'action' => $action
                        ]);
                }
            }
        });
    }

    /**
     * 插件事件
     */
    private function loadEvent()
    {
        $hooks = $this->app->isDebug() ? [] : Cache::get('hooks', []);
        if (empty($hooks)) {
            $hooks = (array) Config::get('addons.hooks', []);
            // 初始化钩子
            foreach ($hooks as $key => $values) {
                if (is_string($values)) {
                    $values = explode(',', $values);
                } else {
                    $values = (array) $values;
                }
                $hooks[$key] = array_filter(array_map(function ($v) use ($key) {
                    return [get_addons_class($v), Str::camel($key)];
                }, $values));
            }
            Cache::set('hooks', $hooks);
        }
        //如果在插件中有定义 AddonsInit，则直接执行
        if (isset($hooks['AddonsInit'])) {
            foreach ($hooks['AddonsInit'] as $k => $v) {
                Event::trigger('AddonsInit', $v);
            }
        }
        Event::listenEvents($hooks);
    }

    /**
     * 挂载插件服务
     */
    private function loadService()
    {
        $results = scandir($this->module_path);
        $bind = [];
        foreach ($results as $name) {
            if ($name === '.' or $name === '..') {
                continue;
            }
            if (is_file($this->module_path . $name)) {
                continue;
            }
            $addonDir = $this->module_path . $name . DIRECTORY_SEPARATOR;
            if (!is_dir($addonDir)) {
                continue;
            }

            if (!is_file($addonDir . ucfirst($name) . '.php')) {
                continue;
            }

            $service_file = $addonDir . 'service.ini';
            if (!is_file($service_file)) {
                continue;
            }
            $info = parse_ini_file($service_file, true, INI_SCANNER_TYPED) ?: [];
            $bind = array_merge($bind, $info);
        }
        $this->app->bind($bind);
    }

    /**
     * 自动载入插件
     * @return bool
     */
    private function autoload()
    {
        // 是否处理自动载入
        if (!Config::get('addons.autoload', true)) {
            return true;
        }
        $config = Config::get('addons');
        // 读取插件目录及钩子列表
        $base = get_class_methods("\\think\\Addons");
        // 读取插件目录中的php文件
        foreach (glob($this->getAddonsPath() . '*/*.php') as $addons_file) {
            // 格式化路径信息
            $info = pathinfo($addons_file);
            // 获取插件目录名
            $name = str::studly(pathinfo($info['dirname'], PATHINFO_FILENAME));
            // 找到插件入口文件
            if (strtolower($info['filename']) === strtolower($name)) {
                //插件关闭后不加载事件
                $ini_file = addons_type($info['dirname'].DIRECTORY_SEPARATOR);
                if (!is_file($ini_file)) {
                    continue;
                }
                $iniinfo = parse_ini_file($ini_file, true, INI_SCANNER_TYPED) ?: [];
                if (!$iniinfo['state']) {
                    continue;
                }
                //读取开启的应用列表
                if (addons_type($info['dirname'].DIRECTORY_SEPARATOR,false)=='app'||addons_type($info['dirname'].DIRECTORY_SEPARATOR,false)=='addon') {
                    $this->data[]=$iniinfo['name'];
                }
                // 读取出所有公共方法
//                if (strpos($iniinfo['name'],'_')!==false){
//                    $methods = (array)get_class_methods("\\addons\\" . $iniinfo['name'] . "\\" . $info['filename']);
//                }else{
//                    $methods = (array)get_class_methods("\\addons\\" . $name . "\\" . $info['filename']);
//                }
                $methods = (array)get_class_methods("\\addons\\" . $iniinfo['name'] . "\\" . $info['filename']);

                // 跟插件基类方法做比对，得到差异结果
                $hooks = array_diff($methods, $base);
                // 循环将钩子方法写入配置中
                foreach ($hooks as $hook) {
                    if (!isset($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = [];
                    }
                    // 兼容手动配置项
                    if (is_string($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = explode(',', $config['hooks'][$hook]);
                    }
                    if (!in_array($name, $config['hooks'][$hook])) {
                        $config['hooks'][$hook][] = $name;
                    }
                }
            }
        }
        Config::set($config, 'addons');
    }

    /**
     * 获取 addons 路径
     * @return string
     */
    public function getAddonsPath()
    {
        // 初始化插件目录
        $module_path = $this->app->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        // 如果插件目录不存在则创建
        if (!is_dir($module_path)) {
            @mkdir($module_path, 0755, true);
        }

        return $module_path;
    }

    /**
     * 获取插件的配置信息
     * @param string $name
     * @return array
     */
    public function getAddonsConfig()
    {
        $name = $this->app->request->addon;
        $addon = get_addons_instance($name);
        if (!$addon) {
            return [];
        }

        return $addon->getConfig();
    }

    /**
     * 远程下载插件
     *
     * @param   string $name 插件名称
     * @param   array $extend 扩展参数
     * @return  string
     * @throws  AddonException
     * @throws  Exception
     */
    public static function download($name, $extend = [])
    {
        $addonTmpDir = runtime_path() . 'addons' . DIRECTORY_SEPARATOR;
        if (!is_dir($addonTmpDir)) {
            @mkdir($addonTmpDir, 0755, true);
        }
        $tmpFile = $addonTmpDir . $name . ".zip";
        $options = [
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'X-REQUESTED-WITH: XMLHttpRequest'
            ]
        ];
        $ser=self::getServerUrl();
        $ret = Http::sendRequest($ser . '/addon/download', array_merge(['name' => $name], $extend), 'GET', $options);
        if ($ret['ret']) {
            if (substr($ret['msg'], 0, 1) == '{') {
                $json = (array)json_decode($ret['msg'], true);
                //如果传回的是一个下载链接,则再次下载
                if ($json['data'] && isset($json['data']['url'])) {
                    array_pop($options);
                    $ret = Http::sendRequest($json['data']['url'], [], 'GET', $options);
                    if (!$ret['ret']) {
                        //下载返回错误，抛出异常
                        throw new AddonException($json['msg'], $json['code'], $json['data']);
                    }
                } else {
                    //下载返回错误，抛出异常
                    throw new AddonException($json['msg'], $json['code'], $json['data']);
                }
            }
            if ($write = fopen($tmpFile, 'w')) {
                fwrite($write, $ret['msg']);
                fclose($write);
                return $tmpFile;
            }
            throw new Exception("没有权限写入临时文件");
        }
        throw new Exception("无法下载远程文件");
    }

    /**
     * 解压插件
     *
     * @param   string $name 插件名称
     * @return  string
     * @throws  Exception
     */
    public static function unzip($name)
    {
        $file = RUNTIME_PATH() . 'addons' . DIRECTORY_SEPARATOR . $name . '.zip';
        $dir = ADDON_PATH . $name . DIRECTORY_SEPARATOR;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            if ($zip->open($file) !== TRUE) {
                throw new Exception('Unable to open the zip file');
            }
            if (!$zip->extractTo($dir)) {
                $zip->close();
                throw new Exception('Unable to extract the file');
            }
            $zip->close();
            return $dir;
        }
        throw new Exception("无法执行解压操作，请确保ZipArchive安装正确");
    }

    /**
     * 备份插件
     * @param string $name 插件名称
     * @return bool
     * @throws Exception
     */
    public static function backup($name)
    {
        $file = RUNTIME_PATH() . 'addons' . DIRECTORY_SEPARATOR . $name . '-backup-' . date("YmdHis") . '.zip';
        $dir = ADDON_PATH . $name . DIRECTORY_SEPARATOR;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            $zip->open($file, ZipArchive::CREATE);
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $filePath = $fileinfo->getPathName();
                $localName = str_replace($dir, '', $filePath);
                if ($fileinfo->isFile()) {
                    $zip->addFile($filePath, $localName);
                } elseif ($fileinfo->isDir()) {
                    $zip->addEmptyDir($localName);
                }
            }
            $zip->close();
            return true;
        }
        throw new Exception("无法执行压缩操作，请确保ZipArchive安装正确");
    }

    /**
     * 检测插件是否完整
     *
     * @param   string $name 插件名称
     * @return  boolean
     * @throws  Exception
     */
    public static function check($name)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon not exists');
        }
        $addonClass = get_addon_class($name);
        if (!$addonClass) {
            throw new Exception("插件主启动程序不存在");
        }
        $addon = new $addonClass(app());
        if (!$addon->checkInfo()) {
            throw new Exception("配置文件不完整");
        }
        return true;
    }

    /**
     * 是否有冲突
     *
     * @param   string $name 插件名称
     * @return  boolean
     * @throws  AddonException
     */
    public static function noconflict($name)
    {
        // 检测冲突文件
        $list = self::getGlobalFiles($name, true);
        if ($list) {
            //发现冲突文件，抛出异常
            //throw new AddonException("发现冲突文件", -3, ['conflictlist' => $list]);
        }
        return true;
    }

    /**
     * 导入SQL
     *
     * @param   string $name 插件名称
     * @return  boolean
     */
    public static function importsql($name)
    {
        $sqlFile = ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'install.sql';
        if (is_file($sqlFile)) {
            $lines = file($sqlFile);
            $templine = '';
            foreach ($lines as $line) {
                if (substr($line, 0, 2) == '--' || $line == '' || substr($line, 0, 2) == '/*')
                    continue;

                $templine .= $line;
                if (substr(trim($line), -1, 1) == ';') {
                    $templine = str_ireplace('__PREFIX__', Env::get('database.prefix'), $templine);
                    $templine = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $templine);
                    try {
                        Db::execute($templine);
                    } catch (\PDOException $e) {
                        $e->getMessage();
                    }
                    $templine = '';
                }
            }
        }
        return true;
    }

    /**
     * 刷新插件缓存文件
     *
     * @return  boolean
     * @throws  Exception
     */
    public static function refresh()
    {
        //刷新addons.js
        $addons = get_addon_list();
        $bootstrapArr = [];
        foreach ($addons as $name => $addon) {
            $bootstrapFile = ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'bootstrap.js';
            if ($addon['state'] && is_file($bootstrapFile)) {
                $bootstrapArr[] = file_get_contents($bootstrapFile);
            }
        }
        $addonsFile = root_path() . str_replace("/", DIRECTORY_SEPARATOR, "assets/js/addons.js");
        if ($handle = fopen($addonsFile, 'w')) {
            $tpl = <<<EOD
define([], function () {
    {__JS__}
});
EOD;
            fwrite($handle, str_replace("{__JS__}", implode("\n", $bootstrapArr), $tpl));
            fclose($handle);
        } else {
            throw new Exception("addons.js文件没有写入权限");
        }

        $file = APP_PATH() . 'extra' . DIRECTORY_SEPARATOR . 'addons.php';

        $config = get_addon_autoload_config(true);
        if ($config['autoload'])
            return;

        if (!is_really_writable($file)) {
            throw new Exception("addons.php文件没有写入权限");
        }

        if ($handle = fopen($file, 'w')) {
            fwrite($handle, "<?php\n\n" . "return " . var_export($config, TRUE) . ";");
            fclose($handle);
        } else {
            throw new Exception("文件没有写入权限");
        }
        return true;
    }

    /**
     * 安装插件
     *
     * @param   string $name 插件名称
     * @param   boolean $force 是否覆盖
     * @param   array $extend 扩展参数
     * @return  boolean
     * @throws  Exception
     * @throws  AddonException
     */
    public static function install($name, $force = false, $extend = [])
    {
        if (!$name || (is_dir(ADDON_PATH . $name) && !$force)) {
            throw new Exception('Addon already exists');
        }

        // 远程下载插件
        $tmpFile = Service::download($name, $extend);

        // 解压插件
        $addonDir = Service::unzip($name);

        // 移除临时文件
        @unlink($tmpFile);

        try {
            // 检查插件是否完整
            Service::check($name);

            if (!$force) {
                Service::noconflict($name);
            }
        } catch (AddonException $e) {
            @rmdirs($addonDir);
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        } catch (Exception $e) {
            @rmdirs($addonDir);
            throw new Exception($e->getMessage());
        }

        // 复制文件
        if (is_dir($addonDir . self::$addon_dir)) {
            copydirs($addonDir . self::$addon_dir, root_path());
        }

        try {
            // 默认启用该插件
            $info = get_addon_info($name);
            if (!$info['state']) {
                $info['state'] = 1;
                set_addon_info($name, $info);
            }

            // 执行安装脚本
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                $addon->install();
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // 导入
        Service::importsql($name);

        // 刷新
        Service::refresh();
        return true;
    }

    /**
     * 卸载插件
     *
     * @param   string $name
     * @param   boolean $force 是否强制卸载
     * @return  boolean
     * @throws  Exception
     */
    public static function uninstall($name, $force = false)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon not exists');
        }

        // 执行卸载脚本
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                $addon->uninstall();
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        if (!$force) {
            Service::noconflict($name);
        }

        // 移除插件全局资源文件
        if ($force) {
            $list = Service::getGlobalFiles($name);
            foreach ($list as $k => $v) {
                @unlink(root_path() . $v);
            }
        }

        // 移除插件目录
        rmdirs(ADDON_PATH . $name);

        // 刷新
        Service::refresh();
        return true;
    }

    /**
     * 启用
     * @param   string $name 插件名称
     * @param   boolean $force 是否强制覆盖
     * @return  boolean
     */
    public static function enable($name, $force = false)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon not exists');
        }

        if (!$force) {
            Service::noconflict($name);
        }

        //备份冲突文件
        $list = Service::getGlobalFiles($name,true);
        Service::ConflictFiles($name,$list,true);

        $addonDir = ADDON_PATH . $name . DIRECTORY_SEPARATOR;

        // 复制文件
        if (is_dir($addonDir . self::$addon_dir)) {
            copydirs($addonDir . self::$addon_dir, root_path());
        }


        $info = get_addon_info($name);
        $info['state'] = 1;
        unset($info['url']);

        set_addon_info($name, $info);

        //执行启用脚本
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                if (method_exists($class, "enable")) {
                    $addon->enable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // 刷新
        Service::refresh();
        return true;
    }

    /**
     * 禁用
     *
     * @param   string $name 插件名称
     * @param   boolean $force 是否强制禁用
     * @return  boolean
     * @throws  Exception
     */
    public static function disable($name, $force = false)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon not exists');
        }

        // 执行禁用脚本
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());

                if (method_exists($class, "disable")) {
                    $addon->disable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        if (!$force) {
            Service::noconflict($name);
        }

        // 移除插件全局资源文件
        $list = Service::getGlobalFiles($name);
        foreach ($list as $k => $v) {
            @unlink(root_path() . $v);
        }
        //还原冲突文件
        Service::ConflictFiles($name);

        $info = get_addon_info($name);
        $info['state'] = 0;
        unset($info['url']);

        set_addon_info($name, $info);

        // 刷新
        Service::refresh();
        return true;
    }

    /**
     * 升级插件
     *
     * @param   string $name 插件名称
     * @param   array $extend 扩展参数
     */
    public static function upgrade($name, $extend = [])
    {
        $info = get_addon_info($name);
        if ($info['state']) {
            throw new Exception(__('Please disable addon first'));
        }
        $config = get_addon_config($name);
        if ($config) {
            //备份配置
        }

        // 备份插件文件
        Service::backup($name);

        // 远程下载插件
        $tmpFile = Service::download($name, $extend);

        // 解压插件
        $addonDir = Service::unzip($name);

        // 移除临时文件
        @unlink($tmpFile);

        if ($config) {
            // 还原配置
            set_addon_config($name, $config);
        }

        // 导入
        Service::importsql($name);

        // 执行升级脚本
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();

                if (method_exists($class, "upgrade")) {
                    $addon->upgrade();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // 刷新
        Service::refresh();

        return true;
    }

    /**
     * 获取插件在全局的文件
     *
     * @param   string $name 插件名称
     * @param   boolean $onlyconflict 判断获取所有插件文件or只冲突的文件，默认获取冲突的文件
     * @return  array
     */
    public static function getGlobalFiles($name, $onlyconflict = false)
    {
        $list = [];
        $addonDir = ADDON_PATH . $name . DIRECTORY_SEPARATOR;
        // 扫描插件目录是否有覆盖的文件
        //检测到存在插件外目录
        if (is_dir($addonDir . self::$addon_dir)) {
            //匹配出所有的文件
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($addonDir . self::$addon_dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $filePath = $fileinfo->getPathName();
                    $path = str_replace($addonDir . self::$addon_dir . DIRECTORY_SEPARATOR, '', $filePath);
                    if ($onlyconflict) {
                        $destPath = root_path() . $path;
                        if (is_file($destPath)) {
                            if (filesize($filePath) != filesize($destPath) || md5_file($filePath) != md5_file($destPath)) {
                                $list[] = $path;
                            }
                        }
                    } else {
                        $list[] = $path;
                    }
                }
            }
        }
        return $list;
    }

    /**
     * 处理冲突的文件
     *
     * @param   string $name 插件名称
     * @param   array $list 文件列表
     * @param   boolean $operation 文件操作，保存or恢复冲突文件。默认恢复
     * @return  boolean
     */
    public static function ConflictFiles($name,$list=[], $operation = false)
    {
        $destPath = root_path();//网站目录
        $conflictDir = ADDON_PATH . $name . DIRECTORY_SEPARATOR. self::$conflict_dir;//插件下冲突文件目录
        if ($operation){
            is_dir($conflictDir)?delDirAndFile($conflictDir):null;
            foreach ($list as $file) {
                if (is_file($destPath . $file)) {
                    $path=dirname($conflictDir . $file);
                    if (!is_dir($path)){
                        mkdir($path,0777,true);
                    }
                    copy($destPath . $file, $conflictDir . $file );
                }
            }
        }else{
            // 恢复文件
            if (is_dir($conflictDir)) {
                copydirs($conflictDir, $destPath);
            }
        }
        return true;
    }

    /**
     * 获取远程服务器
     * @return  string
     */
    protected static function getServerUrl()
    {
        return Config::get('easyadmin.api_url');
    }
    /**
     * 模板变量赋值
     * @access public
     * @param string|array $name  模板变量
     * @param mixed        $value 变量值
     * @return \think\View
     */
    public function assign($name, $value = null)
    {
        if (is_array($name)) {
            $this->data = array_merge($this->data, $name);
        } else {
            $this->data[$name] = $value;
        }

        return $this;
    }
}
