<?php
/**
 * +----------------------------------------------------------------------
 * | think-module [thinkphp6]
 * +----------------------------------------------------------------------
 *  .--,       .--,             | FILE: Route.php
 * ( (  \.---./  ) )            | AUTHOR: byron
 *  '.__/o   o\__.'             | EMAIL: xiaobo.sun@qq.com
 *     {=  ^  =}                | QQ: 150093589
 *     /       \                | DATETIME: 2019/11/5 09:57
 *    //       \\               |
 *   //|   .   |\\              |
 *   "'\       /'"_.-~^`'-.     |
 *      \  _  /--'         `    |
 *    ___)( )(___               |-----------------------------------------
 *   (((__) (__)))              | 高山仰止,景行行止.虽不能至,心向往之。
 * +----------------------------------------------------------------------
 * | Copyright (c) 2019 http://www.zzstudio.net All rights reserved.
 * +----------------------------------------------------------------------
 */
declare(strict_types=1);

namespace think\module;

use think\helper\Str;
use think\facade\Event;
use think\facade\Config;
use think\exception\HttpException;
use think\module;

class BackendRoute
{

    /**
     * 插件路由请求
     * @param null $addon 插件名称
     * @param string $module 模块名称
     * @param null $controller 控制器类名
     * @param null $action 行为函数
     * @return mixed
     */
    public static function execute($addon = null, $module = 'admin', $controller = null, $action = null)
    {
        $app = app();
        $request = $app->request;
        //去除网址后缀
        $remove=substr($request->pathinfo(),strlen($request->pathinfo())-strlen('.'.Config::get('app.default_return_type')));
        if ($remove == '.'.Config::get('app.default_return_type')){
            $backend=explode('/',str_replace('.'.Config::get('app.default_return_type'),'', $request->pathinfo()));
        }else{
            $backend=explode('/',$request->pathinfo());
        }

        $url=explode(',',$backend[0]);
        $addon=$addon?:$addon=$url[0];
        //$controller=;
        //$action=;
        if ($request->root()!='/'.env('app.admin', 'admin')){
            throw new HttpException(500, lang('Turn off to the background'));
        }

        Event::trigger('module_begin', $request);
        $controller?null:$controller='index';
        $action?null:$action='index';

        if (empty($addon)|| empty($controller) || empty($action)) {
            throw new HttpException(500, lang('addon can not be empty'));
        }

        $request->addon = $addon;
        // 设置当前请求的控制器、操作
        $request->setController($controller)->setAction($action);

        // 获取插件基础信息
        $info = get_module_info($addon);
        if (!$info) {
            throw new HttpException(404, lang('addon %s not found', [$addon]));
        }
        if (!$info['state']) {
            throw new HttpException(500, lang('addon %s is disabled', [$addon]));
        }

        // 监听addon_module_init
        Event::trigger('addon_module_init', $request);
        $class = get_module_class($addon.'.'.$module, 'controller', $controller);
        if (!$class) {
            throw new HttpException(404, lang('addon controller %s not found', [Str::studly($controller)]));
        }

        // 重写视图基础路径
        $config = Config::get('view');
        $config['view_path'] = $app->module->getModulePath() . $addon . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        Config::set($config, 'view');

        // 生成控制器对象
        $instance = new $class($app);
        $vars = [];
        if (is_callable([$instance, $action])) {
            // 执行操作方法
            $call = [$instance, $action];
            $vars =request()->param();
        } elseif (is_callable([$instance, '_empty'])) {
            // 空操作
            $call = [$instance, '_empty'];
            $vars = [$action];
        } else {
            // 操作不存在
            throw new HttpException(404, lang('addon action %s not found', [get_class($instance).'->'.$action.'()']));
        }
        Event::trigger('module_action_begin', $call);

        return call_user_func_array($call, $vars);
    }
}