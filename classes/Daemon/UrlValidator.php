<?php

namespace DT\Daemon;

use DT\Base;
use DT\Common\Curl;
use DT\Common\Exception\PDOExecutionException;
use DT\Common\Exception\PDOPrepareException;
use DT\Common\IO;

class UrlValidator extends Base
{
    private $urlTypes = ['Youtube'];
    private $retry    = 3;

    #region Methods
    public function validate()
    {
        foreach ($this->urlTypes as $urlType) {
            $this->validateUrl($urlType);
        }
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
            IO::message('No url to be validated.');
            return;
        }

        // Move invalid videos into table `videos_inact`
        $sqlMoveToInactivateVideo = "INSERT INTO `videos_inact` SELECT * FROM `videos` WHERE `id` = ?";

        if (!($stmtMoveToInactivateVideo = $this->pdo->prepare($sqlMoveToInactivateVideo))) {
            throw new PDOPrepareException('Failed to prepare PDOStatement with sql {' . $sqlMoveToInactivateVideo . '}');
        }

        // Delete invalid videos from table `videos`
        $sqlDeleteVideo = "DELETE FROM `videos` WHERE `id` = ?";

        if (!($stmtDeleteVideo = $this->pdo->prepare($sqlDeleteVideo))) {
            throw new PDOPrepareException('Failed to prepare PDOStatement with sql {' . $sqlDeleteVideo . '}');
        }

        $failedVideos = false;
        foreach ($results as $row) {
            $invalid = $this->curlUrlInvalidation($type, $row['url']);

            if ($invalid === -1) {
                IO::message('Failed to get the content from url {' . $row['url'] . '}');
                continue;
            }

            if ($invalid !== false) {
                $failedVideos = true;

                // Move video from `videos` to `videos_inact`, then delete it from `videos`
                try {
                    $this->pdo->beginTransaction();
                    if ($stmtMoveToInactivateVideo->execute([$row['id']]) === false) {
                        throw new PDOExecutionException('Failed to execute PDOStatement with sql {' . $sqlMoveToInactivateVideo . '} using video id {' . $row['id'] . '}');
                    }

                    if ($stmtDeleteVideo->execute([$row['id']]) === false) {
                        throw new PDOExecutionException('Failed to execute PDOStatement with sql {' . $sqlDeleteVideo . '} using video id {' . $row['id'] . '}');
                    }

                    $this->pdo->commit();
                } catch (\Exception $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }

                    IO::message($e->getMessage());
                    continue;
                }
            }
        }

        if ($failedVideos === false) {
            IO::message('No invalid video url found...');
            return;
        }

        //IO::message('FailedVideoIds:', $failedVideoIdArr, true);

        // Clean up related tables based on the batch of invalid videos
        IO::message('Start clean up invalid videos:');

        try {
            $this->pdo->beginTransaction();

            // Delete invalid urls from table `urls`
            $sqlDeleteVideoUrl = "DELETE u 
                                  FROM `urls` u LEFT JOIN `videos` v 
                                      ON u.video_id = v.id
                                  WHERE v.id IS NULL";

            if ($this->pdo->exec($sqlDeleteVideoUrl) === false) {
                throw new PDOExecutionException('Failed to execute sql {' . $sqlDeleteVideoUrl . '}');
            }

            IO::message('Finished processing invalid video urls.');

            // Delete invalid images from table `images`
            $sqlDeleteVideoImage = "DELETE i 
                                  FROM `images` i LEFT JOIN `videos` v 
                                      ON i.video_id = v.id
                                  WHERE v.id IS NULL";

            if ($this->pdo->exec($sqlDeleteVideoImage) === false) {
                throw new PDOExecutionException('Failed to execute sql {' . $sqlDeleteVideoImage . '}');
            }

            IO::message('Finished processing invalid video images.');

            // Delete invalid video_actors from table `video_actors`
            $sqlDeleteVideoActors = "DELETE a 
                                     FROM `video_actors` a LEFT JOIN `videos` v 
                                         ON a.video_id = v.id
                                     WHERE v.id IS NULL";

            if ($this->pdo->exec($sqlDeleteVideoActors) === false) {
                throw new PDOExecutionException('Failed to execute sql {' . $sqlDeleteVideoActors . '}');
            }

            IO::message('Finished processing invalid video actors.');

            // Delete invalid video_directors from table `video_directors`
            $sqlDeleteVideoDirectors = "DELETE d 
                                     FROM `video_directors` d LEFT JOIN `videos` v 
                                         ON d.video_id = v.id
                                     WHERE v.id IS NULL";

            if ($this->pdo->exec($sqlDeleteVideoDirectors) === false) {
                throw new PDOExecutionException('Failed to execute sql {' . $sqlDeleteVideoDirectors . '}');
            }

            IO::message('Finished processing invalid video directors.');

            // Delete invalid video_categories from table `video_categories`
            $sqlDeleteVideoCategories = "DELETE c 
                                     FROM `video_categories` c LEFT JOIN `videos` v 
                                         ON c.video_id = v.id
                                     WHERE v.id IS NULL";

            if ($this->pdo->exec($sqlDeleteVideoCategories) === false) {
                throw new PDOExecutionException('Failed to execute sql {' . $sqlDeleteVideoCategories . '}');
            }

            IO::message('Finished processing invalid video categories.');
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            IO::message($e->getMessage());
        }
    }

    private function curlUrlInvalidation($type, $url)
    {
        $invalid  = false;
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

                $invalid = strpos($response, $keyWords);
                break;
            } catch (\Exception $e) {
                continue;
            }
        }

        if ($i >= $this->retry) {
            $invalid = -1;
        }

        return $invalid;
    }
    #endregion
}
