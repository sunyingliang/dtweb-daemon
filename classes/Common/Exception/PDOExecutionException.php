<?php

namespace DT\Common\Exception;

class PDOExecutionException extends DTException
{
    public function __construct($message, $code = 201, $type = 0)
    {
        parent::__construct($message, $code, $type);
    }
}
