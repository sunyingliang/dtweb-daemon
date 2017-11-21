<?php

class Autoload
{
    public static $autoload = [
        'DT\\' => 'classes/'
    ];

    public static $rootDir = __DIR__ . '/';

    public static function loader($className)
    {
        foreach (self::$autoload as $namePrefix => $dirPrefix) {
            if (strpos($className, $namePrefix) === 0) {
                $filename = self::$rootDir . $dirPrefix . str_replace('\\', '/', substr($className, strlen($namePrefix))) . '.php';
                if (file_exists($filename)) {
                    include_once($filename);
                    if (class_exists($className)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}

spl_autoload_register('Autoload::loader');