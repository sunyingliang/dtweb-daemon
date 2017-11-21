<?php

namespace DT\Daemon;

use DT\Base;
use DT\Common\Curl;
use DT\Common\Exception\PDOExecutionException;
use DT\Common\Exception\PDOPrepareException;
use DT\Common\IO;

class UrlValidatorTest extends Base
{
    private $urlTypes = ['Youtube'];
    private $retry    = 3;

    #region Methods
    public function validate()
    {
        $failedVideoIdArr = [12178,12272];

        //$videoIds = '(' . implode(',', $failedVideoIdArr) . ')';
        $videoIds = implode(',', $failedVideoIdArr);
        IO::message('Video Ids: ' . $videoIds);

        $in = str_repeat('?,', count($failedVideoIdArr) - 1) . '?';

        $sqlDeactivateVideo = "UPDATE `videos` SET `activate` = 0 WHERE `id` IN ($in)";

        if (!($stmtDeactivateVideo = $this->pdo->prepare($sqlDeactivateVideo))) {
            throw new PDOPrepareException('Failed to prepare PDOStatement with sql {' . $sqlDeactivateVideo . '}');
        }

        if ($stmtDeactivateVideo->execute($failedVideoIdArr) === false) {
            throw new PDOExecutionException('Failed to execute PDOStatement with sql {' . $sqlDeactivateVideo . '}');
        }

        $sqlDeleteVideoUrl = "DELETE FROM `urls` WHERE `video_id` IN ($in)";

        if (!($stmtDeleteVideoUrl = $this->pdo->prepare($sqlDeleteVideoUrl))) {
            throw new PDOPrepareException('Failed to prepare PDOStatement with sql {' . $sqlDeleteVideoUrl . '}');
        }

        if ($stmtDeleteVideoUrl->execute($failedVideoIdArr) === false) {
            throw new PDOExecutionException('Failed to execute PDOStatement with sql {' . $stmtDeleteVideoUrl . '}');
        }

        IO::message('Finished processing invalid video urls.');
    }
    #endregion

    #region Utils
    private function validateUrl($type)
    {
        $sql = '';

        switch ($type) {
            case 'Youtube':
                $sql = "SELECT v.id AS id, u.url AS url 
                        FROM `videos` `v` JOIN `urls` `u` 
                            ON v.id = u.video_id 
                        WHERE v.activate=1 AND u.source = 'Youtube'"; // and v.id in(10336,10339,10340,10974);
                break;
            default:
                ;
        }

        $results = $this->pdo->query($sql)->fetchAll();

        if (empty($results)) {
            IO::message('No url to be validated.', null, true);
        }

        $failedVideoIdArr = [];

        foreach ($results as $row) {
            IO::message('checking url with id: ' . $row['id'] . '|url: ' . $row['url']);
            $valid = $this->curlUrlValidation($type, $row['url']);
            IO::message('valid: ' . $valid);

            if ($valid === -1) {
                IO::message('Failed to get the content from url {' . $row['url'] . '}');
                continue;
            }

            if ($valid !== false) {
                $failedVideoIdArr[] = $row['id'];
            }
        }

        // Update video and delete video url
        if (empty($failedVideoIdArr)) {
            IO::message('No invalid video url found...');
            return;
        }

        IO::message('FailedVideoIds:', $failedVideoIdArr, true);

        IO::message('Start processing invalid video urls:');

        $videoIds = '(' . implode(',', $failedVideoIdArr) . ')';

        $sqlDeactivateVideo = "UPDATE `videos` SET `activate` = 0 WHERE `id` IN ?";

        if (!($stmtDeactivateVideo = $this->pdo->prepare($sqlDeactivateVideo))) {
            throw new PDOPrepareException('Failed to prepare PDOStatement with sql {' . $sqlDeactivateVideo . '}');
        }

        if ($stmtDeactivateVideo->execute([$videoIds]) === false) {
            throw new PDOExecutionException('Failed to execute PDOStatement with sql {' . $sqlDeactivateVideo . '}');
        }

        $sqlDeleteVideoUrl = "DELETE FROM `urls` WHERE `video_id` IN ?";

        if (!($stmtDeleteVideoUrl = $this->pdo->prepare($sqlDeleteVideoUrl))) {
            throw new PDOPrepareException('Failed to prepare PDOStatement with sql {' . $sqlDeleteVideoUrl . '}');
        }

        if ($stmtDeleteVideoUrl->execute([$videoIds]) === false) {
            throw new PDOExecutionException('Failed to execute PDOStatement with sql {' . $stmtDeleteVideoUrl . '}');
        }

        IO::message('Finished processing invalid video urls.');
    }

    private function curlUrlValidation($type, $url)
    {
        $keyWords = 'depends on url type';

        switch ($type) {
            case 'Youtube':
                $keyWords = '<title>YouTube</title>';
                break;
            default:
                ;
        }

        for ($i = 0; $i < $this->retry; $i++) {
            try {
                $curl = Curl::getCurl([
                    CURLOPT_URL            => $url,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_RETURNTRANSFER => true,
                ]);

                $response = Curl::getResult($curl);
                $response = str_replace(["\r\n", "\n", "\r"], '', $response);

                $valid = strpos($response, $keyWords);
                break;
            } catch (\Exception $e) {
                continue;
            }
        }

        if ($i >= $this->retry) {
            $valid = -1;
        }

        return $valid;
    }
    #endregion
}
