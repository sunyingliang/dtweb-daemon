<?php

namespace DT\Common\Exception;

class InvalidParameterException extends DTException
{
    public function __construct($message, $code = 100, $type = 0)
    {
        parent::__construct($message, $code, $type);
    }
}
