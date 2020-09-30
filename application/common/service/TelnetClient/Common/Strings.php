<?php
namespace app\common\service\TelnetClient\Common;

/**
 * 字符串工具类
 *
 * @author  jshensh <admin@imjs.work>
 */

abstract class Strings
{
    /**
     * String Shift
     *
     * Inspired by array_shift
     *
     * @param string $string
     * @param int $index
     *
     * @access public
     *
     * @return string
     */
    public static function shift(&$string, $index = 1)
    {
        $substr = substr($string, 0, $index);
        $string = substr($string, $index);
        return $substr;
    }
}