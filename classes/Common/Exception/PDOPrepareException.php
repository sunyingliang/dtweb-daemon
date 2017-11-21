<?php

namespace DT\Common\Exception;

class PDOPrepareException extends DTException
{
    public function __construct($message, $code = 203, $type = 0)
    {
        parent::__construct($message, $code, $type);
    }
}
