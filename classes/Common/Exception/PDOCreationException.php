<?php

namespace DT\Common\Exception;

class PDOCreationException extends DTException
{
    public function __construct($message, $code = 200, $type = 0)
    {
        parent::__construct($message, $code, $type);
    }
}
