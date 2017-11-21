<?php

namespace DT\Common\Exception;

class PDOQueryException extends DTException
{
    public function __construct($message, $code = 202, $type = 0)
    {
        parent::__construct($message, $code, $type);
    }
}
