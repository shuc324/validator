<?php
namespace Shuc324\Validation;

use Closure;

class Validator
{
    private $var;

    private $val;

    private $data = [];

    private $parseData = [];

    public static function run($data, $format)
    {
        if (!is_array($data) || empty($data)) {
            throw new ValidationException('待校验数据必须为非空数组', 1);
        }
        if (!is_array($format) || empty($format)) {
            throw new ValidationException('字段校验格式必须设置并为数组', 1);
        }
        return (new static())->start($data, $format);
    }

    protected function ruleExplode($rules)
    {
        return is_array($rules) ? $rules : explode('|', $rules);
    }

    protected function parse(array $format)
    {
        return array_map(function ($item) use ($this) {
            return $this->ruleExplode($item);
        }, $format);
    }

    protected function parseData(array $format)
    {
        $parse = $this->parse($format);
        foreach ($parse as $name => $rules) {
            foreach ($rules as $rule) {
                $data = explode(':', $rule);
                $method = array_shift($data);
                $this->parseData[$name][$method] = explode(',', array_shift($data));
            }
        }
    }

    // 没办法只能用eval
    protected function evalArray(array $keys, $value)
    {
        $arr = []; $str = '$arr';
        array_map(function($key) use (&$str) {
            $key = is_numeric($key) ? intval($key) : '\'' . $key . '\'';
            $str .= '[' . $key . ']';
        }, $keys);
        eval($str . ' = ' . $value . ';');
        return $arr;
    }

    protected function start(array $data, array $format)
    {
        $this->parseData($format);
        foreach ($data as $field => $value) {
            // 此处去支持点语法(数组，列表)验证
            $pieces = explode('.', $field);
            if (!isset($this->parseData[$pieces[0]])) {
                continue;
            }
            switch (count($pieces)) {
                // 异常
                case 0:
                    throw new ValidationException('字段名不能为空', 1);
                    break;
                // value
                case 1:
                    foreach ($this->parseData[$field] as $method => $argument) {
                        if (!isset($this->data[$field])) {
                            $this->data[$field] = $value;
                        }
                        array_unshift($argument, $this->data[$field]);
                        if (method_exists($this, 'v' . $method)) {
                            $this->var = $field;
                            $this->data[$field] = call_user_func_array([$this, 'v' . ucfirst($method)], $argument);
                            $this->var = null;
                        } else {
                            throw new ValidationException('mongoDB字段验证方法' . get_class($this) . '->v' . ucfirst($method) . '()不存在', 1);
                        }
                    }
                    break;
                // array
                default:
                    $array = $this->evalArray($pieces, $value);
                    foreach ($this->parseData[$pieces[0]] as $method => $argument) {
                        if (!isset($this->data[$field])) {
                            $this->data[$field] = $array[$pieces[0]];
                        }
                        array_unshift($argument, $this->data[$field]);
                        if (method_exists($this, 'v' . $method)) {
                            $this->val = $value;
                            $this->var = $pieces[0];
                            $this->data[$field] = call_user_func_array([$this, 'v' . ucfirst($method)], $argument);
                            $this->val = null;
                            $this->var = null;
                        } else {
                            throw new ValidationException('mongoDB字段验证方法' . get_class($this) . '->v' . ucfirst($method) . '()不存在', 1);
                        }
                    }
                    break;
            }
        }
        return $this->data;
    }

    # enum:0/1/2
    protected function vEnum($var, $str)
    {
        if (!in_array($var, explode('/', $str))) {
            throw new ValidationException('字段' . $this->var . '不在' . $str . '范围内');
        }
        return $var;
    }

    # number
    protected function vNumber($var)
    {
        if (!(is_int($var) || is_float($var))) {
            throw new ValidationException('字段' . $this->var . '必须为数字');
        }
        return $var;
    }

    # string
    protected function vString($var)
    {
        if (!is_string($var)) {
            throw new ValidationException('字段' . $this->var . '必须为字符串');
        }
        return $var;
    }

    # array
    protected function vArray($var)
    {
        if (!is_array($var)) {
            throw new ValidationException('字段' . $this->var . '必须为数组');
        }
        // 数组类型必须参照此处
        return isset($this->val) ? $this->val : $var;
    }

    # list:int,list:string,list:array,list:object
    protected function vList($var, $type = 'int')
    {
        if (is_array($var)) {
            foreach ($var as $key => $value) {
                if (!is_int($key)) {
                    throw new ValidationException('字段' . $this->var . '必须为列表');
                }
                if (!call_user_func('is_' . $type, $value)) {
                    throw new ValidationException('字段' . $this->var . '必须为' . $type . '列表');
                }
            }
        } else {
            throw new ValidationException('字段' . $this->var . '必须为列表');
        }
        // 数组类型必须参照此处
        return isset($this->val) ? $this->val : $var;
    }

    # callBack:\namespace\class@method
    protected function vCallBack($var, Closure $callable)
    {
        list($class, $method) = explode('@', $callable);
        if (!is_array($var)) {
            throw new ValidationException('回调验证只支持数组字段');
        }
        if (!class_exists($class, $method)) {
            throw new ValidationException('回调方法' . implode('::', [$class, $method]) . '未定义');
        }
        if (!call_user_func_array([$class, $method], [$var])) {
            throw new ValidationException('字段' . $this->var . '不能通过' . implode('::', [$class, $method]) . '的验证');
        }
        // 数组类型必须参照此处
        return isset($this->val) ? $this->val : $var;
    }
}
