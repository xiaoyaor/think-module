<?php

namespace think\module;

use think\Exception;

/**
 * 插件异常处理类
 * @package think\module
 */
class ModuleException extends Exception
{

    public function __construct($message, $code, $data = '')
    {
        $this->message  = $message;
        $this->code     = $code;
        $this->data     = $data;
    }

}
