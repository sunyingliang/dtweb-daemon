<?php

namespace DT\Common;

use DT\Common\Exception\CURLException;
use DT\Common\Exception\InvalidParameterException;

class Curl
{
    public static function getCurl($options = null)
    {
        $curl = curl_init();

        if ($curl === false) {
            throw new CURLException('Failed to initialize curl');
        }

        if (isset($options)) {
            if (!is_array($options)) {
                throw new InvalidParameterException('Passed in parameter {options} must be an array.');
            }

            $curlSetOptArr = curl_setopt_array($curl, $options);

            if ($curlSetOptArr === false) {
                throw new CURLException('Failed to set curl options');
            }
        }

        return $curl;
    }

    public static function setOptArray($curl, $options) {
        if (!is_array($options)) {
            throw new InvalidParameterException('Passed in parameter {options} must be an array.');
        }

        $curlSetOptArr = curl_setopt_array($curl, $options);

        if ($curlSetOptArr === false) {
            throw new CURLException('Failed to set curl options');
        }
    }

    public static function getResult($curl)
    {
        $response = curl_exec($curl);

        $curlErrno = curl_errno($curl);

        if ($response === false || $curlErrno !== 0) {
            throw new CURLException('Failed to get result via CURL request');
        }

        return $response;
    }

    public static function closeCurl($curl)
    {
        curl_close($curl);
    }
}
