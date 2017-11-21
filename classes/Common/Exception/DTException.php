<?php

namespace DT\Common\Exception;

abstract class DTException extends \Exception
{
    #region Fields
    protected $type;
    #endregion

    public function __construct($message, $code, $type = 0)
    {
        switch($type){
            case 0:
                $this->type = 'error';
                break;
            case 1:
                $this->type = 'warning';
                break;
            case 2:
                $this->type = 'info';
                break;
            default:
                $this->type = 'error';
                break;
        }

        parent::__construct($message, $code);
    }

    public function __toString()
    {
        return __CLASS__ . ': [' . $this->code . '|' . $this->type . ']: ' . $this->message;
    }
}
