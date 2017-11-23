<?php

namespace DT\Daemon;

use DT\Base;
use DT\Common\Curl;
use DT\Common\Exception\InvalidParameterException;
use DT\Common\Exception\PDOExecutionException;
use DT\Common\Exception\PDOPrepareException;
use DT\Common\IO;

class CategoryMerging extends Base
{
    public function __construct($connection = null)
    {
        if (!defined('MERGING_CATEGORY')) {
            throw new InvalidParameterException('Constant {MERGING_CATEGORY} is not defined correctly');
        }

        parent::__construct($connection);
    }

    #region Methods
    public function merge()
    {
        // Prepare PDOStatement
        $sqlSelect = "SELECT `video_id` FROM `video_categories` WHERE `category_id` = ?";
        if (($stmtSelect = $this->pdo->prepare($sqlSelect)) === false) {
            throw new PDOPrepareException('Failed to create PDOStatement with sql {' . $sqlSelect . '}');
        }

        $sqlDelete = "DELETE FROM `video_categories` WHERE `video_id` = ? AND `category_id` = ?";
        if (($stmtDelete = $this->pdo->prepare($sqlDelete)) === false) {
            throw new PDOPrepareException('Failed to create PDOStatement with sql {' . $sqlDelete . '}');
        }

        $sqlUpdate = "UPDATE `video_categories` 
                      SET 
                          `category_id` = ?,
                          `id` = concat(`video_id`, '-', ?)
                      WHERE `video_id` = ? AND `category_id` = ?";
        if (($stmtUpdate = $this->pdo->prepare($sqlUpdate)) === false) {
            throw new PDOPrepareException('Failed to create PDOStatement with sql {' . $sqlUpdate . '}');
        }

        $sqlDeleteCategory = "DELETE FROM `categories` WHERE `id` = ?";
        if (($stmtDeleteCategory = $this->pdo->prepare($sqlDeleteCategory)) === false) {
            throw new PDOPrepareException('Failed to create PDOStatement with sql {' . $sqlDeleteCategory . '}');
        }

        // Loop each $category tube in array MERGING_CATEGORY
        foreach (MERGING_CATEGORY as $category) {
            $this->pdo->beginTransaction();

            // Fetch merge video_id and to video_id, and delete existed items with same video_id and update items with different video_id
            if ($stmtSelect->execute([$category['merge']]) === false) {
                IO::message('Failed to execute PDOStatement with sql {' . $sqlSelect . '}');
                $this->pdo->rollBack();
                continue;
            }

            $mergeVideoIds = $stmtSelect->fetchAll();

            if ($stmtSelect->execute([$category['to']]) === false) {
                IO::message('Failed to execute PDOStatement with sql {' . $sqlSelect . '}');
                $this->pdo->rollBack();
                continue;
            }

            $toVideoIds = $stmtSelect->fetchAll();

            $mergeVideoIdsLength = count($mergeVideoIds);
            for ($i = 0; $i < $mergeVideoIdsLength; $i++) {
                if ($this->inSubArray($mergeVideoIds[$i]['video_id'], $toVideoIds)) {
                    $mergeVideoIds[$i]['flag'] = 0;
                } else {
                    $mergeVideoIds[$i]['flag'] = 1;
                }
            }

            for ($i = 0; $i < $mergeVideoIdsLength; $i++) {
                if ($mergeVideoIds[$i]['flag'] === 0) {
                    if ($stmtDelete->execute([$mergeVideoIds[$i]['video_id'], $category['merge']]) === false) {
                        IO::message('Failed to execute PDOStatement with sql {' . $sqlDelete . '}, video_id {' . $mergeVideoIds[$i]['video_id'] . '}, category_id {' . $category['merge'] . '}');
                        $this->pdo->rollBack();
                        continue 2;
                    }
                } elseif ($mergeVideoIds[$i]['flag'] === 1) {
                    if ($stmtUpdate->execute([$category['to'], $category['to'], $mergeVideoIds[$i]['video_id'], $category['merge']]) === false) {
                        IO::message('Failed to execute PDOStatement with sql {' . $sqlUpdate . '}, video_id {' . $mergeVideoIds[$i]['video_id'] . '}, category_id {' . $category['merge'] . '}');
                        $this->pdo->rollBack();
                        continue 2;
                    }
                }
            }

            if ($stmtDeleteCategory->execute([$category['merge']]) === false) {
                IO::message('Failed to execute PDOStatement with sql {' . $sqlDeleteCategory . '}');
                $this->pdo->rollBack();
                continue;
            }

            $this->pdo->commit();
        }

        IO::message('Finished merging categories!');
    }
    #endregion

    #region Utils
    private function inSubArray($element, &$array)
    {
        $retValue = false;

        foreach ($array as $row) {
            if ($element == $row['video_id']) {
                $retValue = true;
                break;
            }
        }

        return $retValue;
    }
    #endregion
}
