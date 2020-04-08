<?php
declare(strict_types=1);

use think\Exception;
use think\facade\Config;
use think\facade\Event;
use think\facade\Route;
use think\helper\{
    Str, Arr
};

\think\Console::starting(function (\think\Console $console) {
    $console->addCommands([
        'module:config' => '\\think\\module\\command\\SendConfig'
    ]);
});


// 插件类库自动载入
spl_autoload_register(function ($class) {

    $class = ltrim($class, '\\');

    $dir = app()->getRootPath();
    $namespace = 'module';

    if (strpos($class, $namespace) === 0) {
        $class = substr($class, strlen($namespace));
        $path = '';
        if (($pos = strripos($class, '\\')) !== false) {
            $path = str_replace('\\', '/', substr($class, 0, $pos)) . '/';
            $class = substr($class, $pos + 1);
        }
        $path .= str_replace('_', '/', $class) . '.php';
        $dir .= $namespace . $path;

        if (file_exists($dir)) {
            include $dir;
            return true;
        }
        return false;
    }
    return false;

});

if (!function_exists('listen')) {
    /**
     * 注册事件监听
     * @access public
     * @param string $event    事件名称
     * @param mixed  $listener 监听操作（或者类名）
     * @param bool   $first    是否优先执行
     * @return \think\Event
     */
    function listen(string $event, $listener, bool $first = false)
    {
        $result = Event::listen($event, $listener, $first);

        return $result;
    }
}

if (!function_exists('hook')) {
    /**
     * 处理插件钩子
     * @param string $event 钩子名称
     * @param array|null $params 传入参数
     * @param bool $once 是否只返回一个结果
     * @return mixed
     */
    function hook($event, $params = null, bool $once = false)
    {
        //下划线转驼峰(首字母小写)
        $event = Str::camel($event);

        $result = Event::trigger($event, $params, $once);

        return join('', $result);
    }
}

if (!function_exists('get_module_info')) {
    /**
     * 读取插件的基础信息
     * @param string $name 插件名
     * @return array
     */
    function get_module_info($name)
    {
        $module = get_module_instance($name);
        if (!$module) {
            return [];
        }

        return $module->getInfo();
    }
}

if (!function_exists('getInfo')) {
    /**
     * 插件基础信息
     * @param string $name 插件名
     * @return array
     */
    function getInfo($name)
    {
        $info = Config::get($name, []);
        if ($info) {
            return $info;
        }

        // 文件属性
        $info = [];
        // 文件配置
        $info_file = module_type(module_PATH.$name.DIRECTORY_SEPARATOR);
        if (is_file($info_file)) {
            $_info = parse_ini_file($info_file, true, INI_SCANNER_TYPED) ?: [];
            $_info['url'] = module_url();
            $info = array_merge($_info, $info);
        }
        Config::set($info,$name);

        return isset($info) ? $info : [];
    }
}

if (!function_exists('get_module_instance')) {
    /**
     * 获取插件的单例
     * @param string $name 插件名
     * @return mixed|null
     */
    function get_module_instance($name)
    {
        static $_module = [];
        if (isset($_module[$name])) {
            return $_module[$name];
        }
        $class = get_module_class($name);
        if (class_exists($class)) {
            $_module[$name] = new $class(app());

            return $_module[$name];
        } else {
            return null;
        }
    }
}

if (!function_exists('get_module_class')) {
    /**
     * 获取插件类的类名
     * @param string $name 插件名
     * @param string $type 返回命名空间类型
     * @param string $class 当前类名
     * @return string
     */
    function get_module_class($name, $type = 'hook', $class = null)
    {
        $namelist=[];
        $name = trim($name);
        if (strrpos($name ,".")!== false){
            $namelist = explode('.', $name);
            $name=$namelist[0];
        }
        // 处理多级控制器情况
        if (!is_null($class) && strpos($class, '.')) {
            $class = explode('.', $class);

            //$class[0] = $class[0].'\\controller';
            $class[count($class) - 1] = Str::studly(end($class));
            $class = implode('\\', $class);
        } else {
            $class = Str::studly(is_null($class) ? $name : $class);
        }
        switch ($type) {
            case 'controller':
                if($namelist){
                    $namespace = '\\module\\' . $namelist[0] . '\\app\\'. $namelist[1] .'\\controller\\'.$class ;
                }else{
                    $namespace = '\\module\\' . $name . '\\controller\\'.$class;
                }
                break;
            default:
                $namespace = '\\module\\' . strtolower(str::snake($name)) . '\\'.str::studly($name);
        }

        return class_exists($namespace) ? $namespace : '';
    }
}

if (!function_exists('module_url')) {
    /**
     * 插件显示内容里生成访问插件的url
     * @param $url
     * @param array $param
     * @param bool|string $suffix 生成的URL后缀
     * @param bool|string $domain 域名
     * @return bool|string
     */
    function module_url($url = '', $param = [], $suffix = true, $domain = false)
    {
        $request = app('request');
        if (empty($url)) {
            // 生成 url 模板变量
            $module = $request->module;
            $controller = $request->controller();
            $controller = str_replace('/', '.', $controller);
            $action = $request->action();
        } else {
            $url = Str::studly($url);
            $url = parse_url($url);
            if (isset($url['scheme'])) {
                $module = strtolower($url['scheme']);
                $controller = $url['host'];
                $action = trim($url['path'], '/');
            } else {
                $route = explode('/', $url['path']);
                $module = $request->module;
                $action = array_pop($route);
                $controller = array_pop($route) ?: $request->controller();
            }
            $controller = Str::snake((string)$controller);

            /* 解析URL带的参数 */
            if (isset($url['query'])) {
                parse_str($url['query'], $query);
                $param = array_merge($query, $param);
            }
        }

        return Route::buildUrl("@module/{$module}/{$controller}/{$action}", $param)->suffix($suffix)->domain($domain);
    }
}

if (!function_exists('module_url2')) {
    /**
     * 插件显示内容里生成访问插件的url
     * @param $url
     * @param array $param
     * @param bool|string $suffix 生成的URL后缀
     * @param bool|string $domain 域名
     * @return bool|string
     */
    function module_url2($url = '', $param = [], $suffix = true, $domain = false)
    {
        $request = app('request');
        if (empty($url)) {
            // 生成 url 模板变量
            $module = $request->module;
            $controller = $request->controller();
            $controller = str_replace('/', '.', $controller);
            $action = $request->action();
        } else {
            $url = Str::studly($url);
            $url = parse_url($url);
            if (isset($url['scheme'])) {
                $module = strtolower($url['scheme']);
                $controller = $url['host'];
                $action = trim($url['path'], '/');
            } else {
                $route = explode('/', $url['path']);
                $module = $request->module.'.admin';
                $action = array_pop($route);
                $controller = array_pop($route) ?: $request->controller();
            }
            $controller = Str::snake((string)$controller);

            /* 解析URL带的参数 */
            if (isset($url['query'])) {
                parse_str($url['query'], $query);
                $param = array_merge($query, $param);
            }
        }

        return Route::buildUrl("@module.{$module}/{$controller}/{$action}", $param)->suffix($suffix)->domain($domain);
    }
}

if (!function_exists('get_module_list')) {

    /**
     * 获得插件列表
     * @return array
     */
    function get_module_list()
    {
        $results = scandir(module_PATH);
        $list = [];
        foreach ($results as $name) {
            if ($name === '.' or $name === '..')
                continue;
            if (is_file(module_PATH . $name))
                continue;
            $moduleDir = module_PATH . $name . DIRECTORY_SEPARATOR;
            if (!is_dir($moduleDir))
                continue;

            if (!is_file($moduleDir . str::studly($name) . '.php'))
                continue;

            //这里不采用get_module_info是因为会有缓存
            //$info = get_module_info($name);
            if (module_type($moduleDir,false)=='module'){
                $info_file=module_type($moduleDir);
            }else{
                continue;
            }


            $info = Config::load($info_file, '', "module-info-{$name}");
            $info['url'] = module_url($name);
            $list[$name] = $info;
        }
        return $list;
    }

}

if (!function_exists('get_app_list')) {

    /**
     * 获得应用列表
     * @param bool $isrun 是否只开启的
     * @return array
     */
    function get_app_list($isrun=false)
    {
        $results = scandir(module_PATH);
        $list = [];
        foreach ($results as $name) {
            if ($name === '.' or $name === '..')
                continue;
            if (is_file(module_PATH . $name))
                continue;
            $moduleDir = module_PATH . $name . DIRECTORY_SEPARATOR;
            if (!is_dir($moduleDir))
                continue;

            if (!is_file($moduleDir . str::studly($name) . '.php'))
                continue;

            //这里不采用get_module_info是因为会有缓存
            //$info = get_module_info($name);
            if (module_type($moduleDir,false)=='app'){
                $info_file=module_type($moduleDir);
            }else{
                continue;
            }

            $info = Config::load($info_file);
            if ($isrun&&$info['state']!=1)
                continue;
            $info['url'] = module_url($name);
            $list[$name] = $info;
        }
        return $list;
    }

}


if (!function_exists('get_module_config')) {

    /**
     * 获取插件类的配置值值
     * @param string $name 插件名
     * @return array
     */
    function get_module_config($name)
    {
        $module = get_module_instance($name);
        if (!$module) {
            return [];
        }
        return $module->getConfig($name);
    }

}

if (!function_exists('get_module_instance')) {

    /**
     * 获取插件的单例
     * @param $name
     * @return mixed|null
     */
    function get_module_instance($name)
    {
        static $_module = [];
        if (isset($_module[$name])) {
            return $_module[$name];
        }
        $class = get_module_class($name);
        if (class_exists($class)) {
            $_module[$name] = new $class(app());
            return $_module[$name];
        } else {
            return null;
        }
    }
}

if (!function_exists('get_module_class')) {

    /**
     * 获取插件类的类名
     * @param string $name 插件名
     * @param string $type 返回命名空间类型
     * @param string $class 当前类名
     * @return string
     */
    function get_module_class(string $name, $type = 'hook', $class = null)
    {
        $name = parse_name($name);
        // 处理多级控制器情况
        if (!is_null($class) && strpos($class, '.')) {
            $class = explode('.', $class);

            $class[count($class) - 1] = parse_name(end($class), 1);
            $class = implode('\\', $class);
        } else {
            $class = parse_name(is_null($class) ? $name : $class, 1);
        }
        switch ($type) {
            case 'controller':
                $namespace = "\\module\\" . $name . "\\controller\\" . $class;
                break;
            default:
                $namespace = "\\module\\" . $name . "\\" . $class;
        }
        return class_exists($namespace) ? $namespace : '';
    }
}

if (!function_exists('get_module_info')) {

    /**
     * 读取插件的基础信息
     * @param string $name 插件名
     * @return array
     */
    function get_module_info($name)
    {
        $module = get_module_instance($name);
        if (!$module) {
            return [];
        }
        return $module->getInfo($name);
    }
}

if (!function_exists('check_module_exist')) {

    /**
     * 读取插件的基础信息
     * @param string $name 插件名
     * @return boolean
     */
    function check_module_exist($name)
    {
        $module = get_module_instance($name);
        if (!$module) {
            return [];
        }
        return $module->getInfo($name);
    }
}

if (!function_exists('get_module_fullconfig')) {

    /**
     * 获取插件类的配置数组
     * @param string $name 插件名
     * @return array
     */
    function get_module_fullconfig($name)
    {
        $module = get_module_instance($name);
        if (!$module) {
            return [];
        }
        return $module->getFullConfig($name);
    }
}

if (!function_exists('set_module_info')) {
    /**
     * 设置基础配置信息
     * @param string $name 插件名
     * @param array $array
     * @return boolean
     * @throws Exception
     */
    function set_module_info($name, $array)
    {
        $file = module_type(module_PATH . $name . DIRECTORY_SEPARATOR);
        $module = get_module_instance($name);
        $array = $module->setInfo($name, $array);
        $res = array();
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                $res[] = "[$key]";
                foreach ($val as $skey => $sval)
                    $res[] = "$skey = " . (is_numeric($sval) ? $sval : $sval);
            } else
                $res[] = "$key = " . (is_numeric($val) ? $val : $val);
        }
        if ($handle = fopen($file, 'w')) {
            fwrite($handle, implode("\n", $res) . "\n");
            fclose($handle);
            //清空当前配置缓存
            Config::set(['moduleinfo'=>$name]);
        } else {
            throw new Exception("文件没有写入权限");
        }
        return true;
    }
}

if (!function_exists('set_module_config')) {

    /**
     * 写入配置文件
     * @param string $name 插件名
     * @param array $config 配置数据
     * @param boolean $writefile 是否写入配置文件
     */
    function set_module_config($name, $config, $writefile = true)
    {
        $module = get_module_instance($name);
        $module->setConfig($name, $config);
        $fullconfig = get_module_fullconfig($name);
        foreach ($fullconfig as $k => &$v) {
            if (isset($config[$v['name']])) {
                $value = $v['type'] !== 'array' && is_array($config[$v['name']]) ? implode(',', $config[$v['name']]) : $config[$v['name']];
                $v['value'] = $value;
            }
        }
        if ($writefile) {
            // 写入配置文件
            set_module_fullconfig($name, $fullconfig);
        }
        return true;
    }

}

if (!function_exists('set_module_fullconfig')) {

    /**
     * 写入配置文件
     *
     * @param string $name 插件名
     * @param array $array
     * @return boolean
     * @throws Exception
     */
    function set_module_fullconfig($name, $array)
    {
        $file = module_PATH . $name . DIRECTORY_SEPARATOR . 'config.php';
        if (!is_really_writable($file)) {
            throw new Exception("文件没有写入权限");
        }
        if ($handle = fopen($file, 'w')) {
            fwrite($handle, "<?php\n\n" . "return " . var_export($array, TRUE) . ";\n");
            fclose($handle);
        } else {
            throw new Exception("文件没有写入权限");
        }
        return true;
    }
}

if (!function_exists('get_module_autoload_config')) {

    /**
     * 获得插件自动加载的配置
     * @return array
     */
    function get_module_autoload_config($truncate = false)
    {
        // 读取module的配置
        $config = (array)Config::get('module');
        if ($truncate) {
            // 清空手动配置的钩子
            $config['hooks'] = [];
        }
        $route = [];
        // 读取插件目录及钩子列表
        $base = get_class_methods("\\think\\module");
        $base = array_merge($base, ['install', 'uninstall', 'enable', 'disable']);

        $url_domain_deploy = Config::get('url_domain_deploy');
        $module = get_module_list();
        $domain = [];
        foreach ($module as $name => $module) {
            if (!$module['state'])
                continue;

            // 读取出所有公共方法
            $methods = (array)get_class_methods("\\module\\" . $name . "\\" . ucfirst($name));
            // 跟插件基类方法做比对，得到差异结果
            $hooks = array_diff($methods, $base);
            // 循环将钩子方法写入配置中
            foreach ($hooks as $hook) {
                $hook = parse_name($hook, 0, false);
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
            $conf = get_module_config($module['name']);
            if ($conf) {
                $conf['rewrite'] = isset($conf['rewrite']) && is_array($conf['rewrite']) ? $conf['rewrite'] : [];
                $rule = array_map(function ($value) use ($module) {
                    return "{$module['name']}/{$value}";
                }, array_flip($conf['rewrite']));
                if ($url_domain_deploy && isset($conf['domain']) && $conf['domain']) {
                    $domain[] = [
                        'module'  => $module['name'],
                        'domain' => $conf['domain'],
                        'rule'   => $rule
                    ];
                } else {
                    $route = array_merge($route, $rule);
                }
            }
        }
        $config['route'] = $route;
        $config['route'] = array_merge($config['route'], $domain);
        return $config;
    }
}

/**
 * 插件显示内容里生成访问插件的url
 * @param string      $url    地址 格式：插件名/控制器/方法
 * @param array       $vars   变量参数
 * @param bool|string $suffix 生成的URL后缀
 * @param bool|string $domain 域名
 * @return bool|string
 */
function module_url($url, $vars = [], $suffix = true, $domain = false)
{
    $url = ltrim($url, '/');
    $module = substr($url, 0, stripos($url, '/'));
    if (!is_array($vars)) {
        parse_str($vars, $params);
        $vars = $params;
    }
    $params = [];
    foreach ($vars as $k => $v) {
        if (substr($k, 0, 1) === ':') {
            $params[$k] = $v;
            unset($vars[$k]);
        }
    }
    $val = "@module/{$url}";
    $dd=url();
    $config = get_module_config($module);
    $domainprefix = $config && isset($config['domain']) && $config['domain'] ? $config['domain'] : '';
    $domain = $domainprefix && Config::get('url_domain_deploy') ? $domainprefix : $domain;
    $rewrite = $config && isset($config['rewrite']) && $config['rewrite'] ? $config['rewrite'] : [];
    if ($rewrite) {
        $path = substr($url, stripos($url, '/') + 1);
        if (isset($rewrite[$path]) && $rewrite[$path]) {
            $val = $rewrite[$path];
            array_walk($params, function ($value, $key) use (&$val) {
                $val = str_replace("[{$key}]", $value, $val);
            });
            $val = str_replace(['^', '$'], '', $val);
            if (substr($val, -1) === '/') {
                $suffix = false;
            }
        } else {
            // 如果采用了域名部署,则需要去掉前两段
            if ($indomain && $domainprefix) {
                $arr = explode("/", $val);
                $val = implode("/", array_slice($arr, 2));
            }
        }
    } else {
        foreach ($params as $k => $v) {
            $vars[substr($k, 1)] = $v;
        }
    }
    $url = url($val, [], $suffix, $domain) . ($vars ? '?' . http_build_query($vars) : '');
    $url = preg_replace("/\/((?!index)[\w]+)\.php\//i", "/", $url);
    return $url;
}

if (!function_exists('module_type')) {
    /**
     * 判断插件是module还是app
     * @param string $path 插件路径
     * @param boolean $type 返回类型：true:返回ini文件完整路径 false:返回插件类型
     * @return mixed
     */
    function module_type($path,$type=true)
    {
        if ($type) {
            if (is_file($path . 'module.ini')) {
                return $path . 'module.ini';
            } else if (is_file($path . 'app.ini')) {
                return $path . 'app.ini';
            } else if (is_file($path . 'module.ini')) {
                return $path . 'module.ini';
            } else {
                return $path;
            }
        } else {
            if (is_file($path . 'module.ini')) {
                return 'module';
            } else if (is_file($path . 'app.ini')) {
                return 'app';
            } else if (is_file($path . 'module.ini')) {
                return 'module';
            } else {
                return '';
            }
        }
    }
}