<?php

namespace DT;

use DT\Common\Exception\PDOCreationException;
use DT\Common\IO;
use DT\Common\Exception\InvalidParameterException;

class Base
{
    protected $responseArr;
    protected $timezone;
    protected $pdo;
    protected $data;

    public function __construct($connection = null)
    {
        $this->responseArr = IO::initResponseArray();
        $this->timezone    = date_default_timezone_get();

        // VALIDATION
        if (empty($this->timezone)) {
            $this->timezone = 'NZ';
        }

        // Used only if an entire handler uses the same connection, otherwise it's done within the handler
        if (!is_null($connection)) {
            if (!isset($connection) || !is_array($connection)) {
                throw new InvalidParameterException('Error: PDO connection is not configured properly');
            }

            $this->pdo = IO::getPDOConnection($connection);

            if ($this->pdo === false) {
                throw new PDOCreationException('Error: Failed to create PDO connection');
            }
        }

        // Take POST as the default request method, can be overridden in specified method
        $this->data = IO::getPostParameters();
    }

    #region Methods
    protected function appendSearch($searchString, $query)
    {
        return $searchString . (strpos($searchString, 'WHERE') === false ? ' WHERE ' : ' AND ') . $query;
    }

    protected function bindParams(\PDOStatement $stmt, $parameters)
    {
        foreach ($parameters as $parameter => $value) {
            $stmt->bindValue($parameter, $value);
        }

        return $stmt;
    }
    #endregion
}
