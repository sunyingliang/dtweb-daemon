<?php
namespace DT\Common\Exception;

class CURLException extends DTException
{
    public function __construct($message, $code = 300, $type = 0)
    {
        parent::__construct($message, $code, $type);
    }
}
