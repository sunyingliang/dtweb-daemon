<?php

namespace DT\Common;

use DT\Common\Exception\InvalidParameterException;

class IO
{
    public static function initResponseArray()
    {
        return [
            "status" => "success"
        ];
    }

    public static function formatResponseArray($responseArr)
    {
        return [
            "response" => $responseArr
        ];
    }

    public static function guid()
    {
        if (function_exists('com_create_guid') === true) {
            return trim(com_create_guid(), '{}');
        }

        $md5 = strtoupper(md5(uniqid(mt_srand(intval(microtime(true) * 1000)), true)));

        return substr($md5, 0, 8) . '-' . substr($md5, 8, 4) . '-' . substr($md5, 12, 4) . '-' . substr($md5, 16, 4) . '-' . substr($md5, 20, 12);
    }

    public static function generateRandom($length)
    {
        $string = '';

        for ($i = 0; $i < $length; $i++) {
            $string .= chr(rand(33, 123));
        }

        return $string;
    }

    public static function generatePassword()
    {
        if (function_exists('com_create_guid') === true) {
            return trim(com_create_guid(), '{}');
        }

        return strtoupper(md5(uniqid(mt_srand(intval(microtime(true) * 1000)), true)));
    }

    public static function shell($cmd, $stdIn = null, &$stdOut = null, &$stdErr = null)
    {
        $descriptorSpec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new \Exception('Error opening command process');
        }

        fwrite($pipes[0], $stdIn);
        fclose($pipes[0]);

        $stdOut = stream_get_contents($pipes[1]);
        $stdErr = stream_get_contents($pipes[2]);

        proc_close($process);

        return $stdOut . $stdErr;
    }

    public static function getQueryParameters()
    {
        return $_GET;
    }

    public static function getPostParameters()
    {
        $contentType = '';

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $contentType = strtolower($_SERVER['CONTENT_TYPE']);
        }

        if (strpos($contentType, 'json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $input = null;
            }
        } else {
            $input = $_POST;
        }

        return $input;
    }

    public static function getWithDefault($haystack, $needle, $default = null)
    {
        if (is_numeric($default)) {
            return (isset($haystack[$needle]) && is_numeric($haystack[$needle])) ? intval($haystack[$needle]) : $default;
        } else {
            return isset($haystack[$needle]) ? $haystack[$needle] : $default;
        }
    }

    public static function required($data, $mandatory = null, $strict = false)
    {
        $retValue = [
            'valid'   => false,
            'message' => ''
        ];

        if (is_string($data)) {
            if (defined($data)) {
                $data = constant($data);
            } else {
                $retValue['message'] = 'The given data does not exist';
                return $retValue;
            }
        }

        $required = [];

        if (!isset($data) || !is_array($data)) {
            $retValue['message'] = 'The given data must be a valid array';
        }

        foreach ($mandatory as $item) {
            if (!isset($data[$item])) {
                $required[] = $item;
            } else if ($strict && empty($data[$item])) {
                $required[] = $item;
            }
        }

        if (empty($required)) {
            return ['valid' => true];
        } else {
            $retValue['message'] = 'Required parameters: ' . implode(', ', $required);
        }

        return $retValue;
    }

    public static function getPDOConnection($connection)
    {
        if (!is_array($connection) || !isset($connection['dns']) || !isset($connection['username']) || !isset($connection['password'])) {
            throw new InvalidParameterException('The PDO connection information is not wrapped correctly');
        }

        try {
            $pdo = new \PDO($connection['dns'], $connection['username'], $connection['password'], array(\PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return $pdo;
        } catch(\PDOException $e) {
            return false;
        }
    }

    public static function message($msg, $object = null, $die = false)
    {
        echo '[' .  date('Y-m-d H:i:s') . ']' . $msg . PHP_EOL;
        
        if (!empty($object)) {
            print_r($object);
            echo PHP_EOL;
        }

        if ($die) {
            exit();
        }
    }
}
