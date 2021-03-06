<?php

namespace DT\Daemon;

use DT\Base;
use DT\Common\Curl;
use DT\Common\Exception\InvalidParameterException;
use DT\Common\Exception\PDOExecutionException;
use DT\Common\Exception\PDOPrepareException;
use DT\Common\IO;

class StreamdorScraper extends Base
{
    private $retry        = 3;
    private $baseUri      = 'https://api.streamdor.com/ontology';
    private $limitPerPage = 24;
    private $apiPage;
    private $apiItem;

    private $currentDateTime;
    private $videoLinkBase = 'https://www.youtube.com/embed/';
    private $imageLinkBase = 'https://83927ddf0fb6449.blob.core.windows.net/images/';


    public function __construct($type, $connection = null)
    {
        if (empty($type) || !in_array($type, ['movie', 'documentary', 'animation'])) {
            throw new InvalidParameterException('Streamdor scraper type is not passed in correctly. e.g.: {/path/to/your-script [movie|documentary|animation]}');
        }

        switch ($type) {
            case 'movie':
                $this->apiPage = $this->baseUri . '/Entities?entityName=&topic=&limitPerPage=' . $this->limitPerPage . '&pageNumber=';
                break;
            case 'documentary':
                $this->apiPage = $this->baseUri . '/Entities?entityName=&topic=documentary&limitPerPage=' . $this->limitPerPage . '&pageNumber=';
                break;
            case 'animation':
                $this->apiPage = $this->baseUri . '/Entities?entityName=&topic=animation&limitPerPage=' . $this->limitPerPage . '&pageNumber=';
                break;
            default:
                $this->apiPage = $this->baseUri . '/Entities?entityName=&topic=&limitPerPage=' . $this->limitPerPage . '&pageNumber=';
        }

        $this->apiItem         = $this->baseUri . '/EntityDetails?&ignoreMediaLinkError=false&entityName=';
        $this->currentDateTime = (new \DateTime())->format('Ymd');

        parent::__construct($connection);
    }

    #region Methods
    public function scrap()
    {
        // Get video names in each page by retrieving the page with the specified page number
        $pageNumber = 101;
        $pageTotal  = 101;

        while ($pageNumber <= $pageTotal) {
            IO::message('Scraping page {' . $pageNumber . '}...');

            if (($response = $this->curlPage($pageNumber)) === false) {
                $message = 'Failed to get response of page {' . $pageNumber . '} with URL: {' . $this->apiPage . $pageNumber . '}';
                IO::message($message);
                IO::log('streamdor-scraper.txt', $message);
                return;
            }

            /* Debug
            IO::log('page.txt', 'Page response:');
            IO::log('page.txt', print_r($response, true));
            IO::log('page.txt', PHP_EOL . PHP_EOL);
            */

            //IO::message('Page response:', $response);

            // Iterate each vidoe name and retrieve the video detials
            $itemCount  = count($response['Key']);
            $itemArray  = [];
            $itemFailed = [];

            for ($i = 0; $i < $itemCount; $i++) {
                $item = $response['Key'][$i];
                if (($itemDetails = $this->curlItem($item['Names'][0])) === false) {
                    $itemFailed[] = $item['Names'][0];
                    continue;
                }

                /* Debug
                IO::log('page.txt', 'Item response:');
                IO::log('page.txt', var_export($itemDetails, true));
                IO::log('page.txt', PHP_EOL);
                */

                // Construct $itemArray for saving into db
                // Treat `actors`, `directors`, `categories` as array
                $actors     = $itemDetails['Key']['Actors'];
                $directors  = $itemDetails['Key']['Directors'];
                $categories = isset($itemDetails['Key']['Attributes']['Genres']) ? $itemDetails['Key']['Attributes']['Genres']['Value'] : null;

                if (!empty($actors) && !is_array($actors)) {
                    $actors = [$actors];
                }
                if (!empty($directors) && !is_array($directors)) {
                    $directors = [$directors];
                }
                if (!empty($categories) && !is_array($categories)) {
                    $categories = [$categories];
                }

                $itemArray[] = [
                    'name'        => $itemDetails['Key']['Names'][0],
                    'actors'      => $actors,
                    'directors'   => $directors,
                    'storyline'   => $itemDetails['Key']['Excerpt'],
                    'releaseDate' => $itemDetails['Key']['Attributes']['Release']['Value'] . substr($this->currentDateTime, -4),
                    'categories'  => $categories,
                    'country'     => isset($itemDetails['Key']['Attributes']['Country']) ? $itemDetails['Key']['Attributes']['Country']['Value'] : 'USA',   // Some item does NOT have 'Country' index, if so, use 'USA' by default
                    'definition'  => $itemDetails['Key']['MovieDefinition'],
                    'rate'        => $itemDetails['Key']['Rating'] * 2,
                    'views'       => $itemDetails['Key']['ProviderViews'],
                    'videoLink'   => $this->videoLinkBase . $itemDetails['Value']['VideoId'],
                    'runTime'     => $itemDetails['Value']['DurationInMinutes'],
                    'source'      => $itemDetails['Value']['ProviderSource'],
                    'createdDate' => $this->currentDateTime,
                    'imageLink'   => $this->buildImageLink($itemDetails['Key']['OfficialImageLink'])
                ];
            }

            /* Debug
            IO::log('items.txt', 'Item response:');
            IO::log('items.txt', var_export($itemArray, true));
            IO::log('items.txt', PHP_EOL);
            */

            if (!empty($itemFailed)) {
                $message = 'Failed to get details of item {' . implode(', ', $itemFailed) . '} in page {' . $pageNumber . '}';
                IO::message($message);
                IO::log('streamdor-scraper.txt', $message);
            }

            // Save $itemArray into db
            if (($this->saveToDb($itemArray)) === false) {
                $itemString = '';
                foreach ($itemArray as $item) {
                    $itemString .= $item['Name'] . ', ';
                }
                $itemString = substr($itemString, -2);

                $message = 'Failed to get details of item {' . $itemString . '} in page {' . $pageNumber . '}';
                IO::message($message);
                IO::log('streamdor-scraper.txt', $message);
                continue;
            }

            // Reset $pageTotal and increase $pageNumber
            $pageTotal = ($response['Value']['Key'] / $this->limitPerPage) + 1;
            $pageNumber++;
        }

        IO::message('Finished scraping steamdor.com!');
    }
    #endregion

    #region Utils
    private function curlPage($page)
    {
        $response = false;

        for ($i = 0; $i < $this->retry; $i++) {
            try {
                $curl = Curl::getCurl([
                    CURLOPT_URL            => $this->apiPage . $page,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => ''
                ]);

                $response = Curl::getResult($curl);
                $response = json_decode($response, true);
                break;
            } catch (\Exception $e) {
                continue;
            }
        }

        return $response;
    }

    private function curlItem($videoName)
    {
        $response = false;

        for ($i = 0; $i < $this->retry; $i++) {
            try {
                $curl = Curl::getCurl([
                    CURLOPT_URL            => $this->apiItem . urlencode($videoName),
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_RETURNTRANSFER => true
                ]);

                //echo $this->apiItem . $videoName . PHP_EOL;

                $response = Curl::getResult($curl);
                $response = json_decode($response, true);
                break;
            } catch (\Exception $e) {
                continue;
            }
        }

        return $response;
    }

    private function buildImageLink($imageLink)
    {
        $posLastSlash = strrpos($imageLink, '\\');
        $posLastDot   = strrpos($imageLink, '.');

        $imageName = substr($imageLink, $posLastSlash + 1, $posLastDot - $posLastSlash - 1);
        $imageType = substr($imageLink, $posLastDot + 1);

        return $this->imageLinkBase . strtolower($imageName) . '_300_300.' . $imageType;
    }

    private function saveToDb(&$items)
    {
        // Loop each item and save it into db:
        foreach ($items as $item) {
            try {
                if ($this->videoExist($item['name'])) {
                    IO::message('Video {' . $item['name'] . '} already existed!');
                    continue;
                }

                /* Debug
                $message = 'BeginTransaction...' . PHP_EOL . var_export($item, true);
                IO::message($message);
                IO::log('streamdor-scraper.txt', $message);
                */

                // Wrap the insertion of video info into a transaction
                $this->pdo->beginTransaction();

                // Save or get `country_id` from table `countries`
                if (($countryId = $this->saveGetCountryId($item['country'])) === false) {
                    $this->pdo->rollBack();
                    continue;
                }
                $item['countryId'] = $countryId;

                /* Debug
                $message = 'Country: ' . $item['country'] . '|contry_id: ' . $countryId;
                IO::message($message);
                IO::log('streamdor-scraper.txt', $message);
                */

                // Save and get `video_id` from table `videos`
                if (($videoId = $this->saveGetVideoId($item)) === false) {
                    $this->pdo->rollBack();
                    continue;
                }
                $item['videoId'] = $videoId;


                $message = 'Finished video insertion {' . $videoId . '}. ';
                IO::message($message);
                /* Debug
                IO::log('streamdor-scraper.txt', $message);
                */

                // Save videoLink into table `urls`
                if ($this->saveUrl($item) === false) {
                    $this->pdo->rollBack();
                    continue;
                }


                $message = 'Finished url insertion. ';
                IO::message($message);
                /* Debug
                IO::log('streamdor-scraper.txt', $message);
                */

                // Save imageLink into table `images`
                if ($this->saveImage($item) === false) {
                    $this->pdo->rollBack();
                    continue;
                }


                $message = 'Finished image insertion. ';
                IO::message($message);
                /* Debug
                IO::log('streamdor-scraper.txt', $message);
                */

                // Save or get `actor_id` from table `actors`, complete table `video_actors`
                if ($this->saveActorsNCompleteVideoActors($item) === false) {
                    $this->pdo->rollBack();
                    continue;
                }


                $message = 'Finished actors. Actors: ' . implode(',', $item['actors']);
                IO::message($message);
                /* Debug
                IO::log('streamdor-scraper.txt', $message);
                */

                // Save or get `director_id` from table `directors`, complete table `video_directors`
                if ($this->saveDirectorsNCompleteVideoDirectors($item) === false) {
                    $this->pdo->rollBack();
                    continue;
                }


                $message = 'Finished directors. Directors: ' . implode(',', $item['directors']);
                IO::message($message);
                /* Debug
                IO::log('streamdor-scraper.txt', $message);
                */

                // Save or get `category_id` from table `categories`, complete table `video_category`
                if ($this->saveCategoriesNCompleteVideoCategories($item) === false) {
                    $this->pdo->rollBack();
                    continue;
                }


                $message = 'Finished categories. Categories: ' . implode(',', $item['categories']);
                IO::message($message);
                /* Debug
                IO::log('streamdor-scraper.txt', $message);
                */

                $this->pdo->commit();
                usleep(10000);
                //die('Terminated!!!');
            } catch (\Exception $e) {
                IO::message('Error: Failed to insert video {' . $item['name'] . '}');
                IO::message($e->getMessage());
                if ($this->pdo->intransaction()) {
                    $this->pdo->rollBack();
                }
                continue;
            }
        }
    }

    private function videoExist($videoName)
    {
        $sql = "SELECT COUNT(*) FROM `videos` WHERE `video_name` = ?";

        if (($stmt = $this->pdo->prepare($sql)) === false) {
            throw new PDOPrepareException('Failed to create PDOStatement with sql {' . $sql . '}');
        }

        if ($stmt->execute([$videoName]) === false) {
            throw new PDOExecutionException('Failed to execute PDOStatement with sql {' . $sql . '}');
        }

        if (($count = $stmt->fetchColumn()) === false) {
            return false;
        }

        return $count > 0;
    }

    private function saveGetCountryId($country)
    {
        $sql = "SELECT `id` FROM `countries` WHERE `country` = ?";

        if (($stmt = $this->pdo->prepare($sql)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sql . '}');
            return false;
        }

        if ($stmt->execute([$country]) === false) {
            IO::message('Failed to execute PDOStatement with sql {' . $sql . '}');
            return false;
        }


        $countryId = $stmt->fetchColumn();
        if ($countryId) {
            return $countryId;
        }

        // Insert the new country item, and return the inserted id
        $sql = "INSERT INTO `countries`(`country`, `created_date_id`, `changed_date_id`) VALUES (?, ?, ?)";

        if (($stmt = $this->pdo->prepare($sql)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sql . '}');
            return false;
        }

        if ($stmt->execute([$country, $this->currentDateTime, $this->currentDateTime]) === false) {
            return false;
        }

        return $this->pdo->lastInsertId();
    }

    private function saveGetVideoId(array &$item)
    {
        $sql = "INSERT INTO `videos`(`release_date_id`, `video_type_id`, `video_name`, `run_time`, `country_id`, `views`, `rate`, 
                    `created_date_id`, `changed_date_id`, `actors`, `directors`, `categories`, `storyline`, `definition`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        if (($stmt = $this->pdo->prepare($sql)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sql . '}');
            return false;
        }

        $values = [
            $item['releaseDate'], 1, $item['name'], $item['runTime'], $item['countryId'], $item['views'], $item['rate'], $this->currentDateTime, $this->currentDateTime,
            implode(',', $item['actors']), implode(',', $item['directors']), implode(',', $item['categories']), $item['storyline'], $item['definition']
        ];
        if ($stmt->execute($values) === false) {
            return false;
        }

        return $this->pdo->lastInsertId();
    }

    private function saveUrl(array &$item)
    {
        $sql = "INSERT INTO `urls`(`video_id`, `url`, `created_date_id`, `changed_date_id`, `source`) 
                VALUES (?, ?, ?, ?, ?)";

        if (($stmt = $this->pdo->prepare($sql)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sql . '}');
            return false;
        }

        $values = [
            $item['videoId'], $item['videoLink'], $this->currentDateTime, $this->currentDateTime, $item['source']
        ];
        if ($stmt->execute($values) === false) {
            return false;
        }

        return true;
    }

    private function saveImage(array &$item)
    {
        $sql = "INSERT INTO `images`(`video_id`, `video_type_id`, `order`, `type`, `url`, `created_date_id`, `changed_date_id`) 
                VALUES (?, 1, 1, 1, ?, ?, ?)";

        if (($stmt = $this->pdo->prepare($sql)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sql . '}');
            return false;
        }

        $values = [
            $item['videoId'], $item['imageLink'], $this->currentDateTime, $this->currentDateTime
        ];
        if ($stmt->execute($values) === false) {
            return false;
        }

        return true;
    }

    private function saveActorsNCompleteVideoActors(array &$item)
    {
        // Return true if no actors need to be inserted
        if (empty($item['actors'])) {
            return true;
        }

        // Start process
        $actorIds = [];

        // Select PDOStatement
        $sqlSelect = "SELECT `id` FROM `actors` WHERE `full_name` = ?";
        if (($stmtSelect = $this->pdo->prepare($sqlSelect)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sqlSelect . '}');
            return false;
        }

        // Insert PDOStatement
        $sqlInsert = "INSERT INTO `actors`(`full_name`, `created_date_id`, `changed_date_id`) VALUES (?, ?, ?)";
        if (($stmtInsert = $this->pdo->prepare($sqlInsert)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sqlInsert . '}');
            return false;
        }

        foreach ($item['actors'] as $actor) {
            // Get `actor_id` if the actor exists
            if ($stmtSelect->execute([$actor]) === false) {
                IO::message('Failed to execute PDOStatement with sql {' . $sqlSelect . '}');
                return false;
            }

            $actorId = $stmtSelect->fetchColumn();
            if ($actorId) {
                $actorIds[] = $actorId;
                continue;
            }

            // Insert the new actor item, and return the inserted id
            if ($stmtInsert->execute([$actor, $this->currentDateTime, $this->currentDateTime]) === false) {
                return false;
            }

            $actorIds[] = $this->pdo->lastInsertId();
        }

        // Complete the many-to-many table `video_actors`
        $sqlComplete = "INSERT INTO `video_actors`(`id`, `video_id`, `actor_id`, `video_type_id`, `created_date_id`, `changed_date_id`) 
                        VALUES (?, ?, ?, ?, ?, ?)";
        if (($stmtComplete = $this->pdo->prepare($sqlComplete)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sqlComplete . '}');
            return false;
        }

        foreach ($actorIds as $actorId) {
            if ($stmtComplete->execute([$item['videoId'] . '-' . $actorId, $item['videoId'], $actorId, 1, $this->currentDateTime, $this->currentDateTime]) === false) {
                return false;
            }
        }

        return true;
    }

    private function saveDirectorsNCompleteVideoDirectors(array &$item)
    {
        // Return true if no directors need to be inserted
        if (empty($item['directors'])) {
            return true;
        }

        // Start process
        $directorIds = [];

        // Select PDOStatement
        $sqlSelect = "SELECT `id` FROM `directors` WHERE `full_name` = ?";
        if (($stmtSelect = $this->pdo->prepare($sqlSelect)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sqlSelect . '}');
            return false;
        }

        // Insert PDOStatement
        $sqlInsert = "INSERT INTO `directors`(`full_name`, `created_date_id`, `changed_date_id`) VALUES (?, ?, ?)";
        if (($stmtInsert = $this->pdo->prepare($sqlInsert)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sqlInsert . '}');
            return false;
        }

        foreach ($item['directors'] as $director) {
            // Get `actor_id` if the actor exists
            if ($stmtSelect->execute([$director]) === false) {
                IO::message('Failed to execute PDOStatement with sql {' . $sqlSelect . '}');
                return false;
            }

            $directorId = $stmtSelect->fetchColumn();
            if ($directorId) {
                $directorIds[] = $directorId;
                continue;
            }

            // Insert the new actor item, and return the inserted id
            if ($stmtInsert->execute([$director, $this->currentDateTime, $this->currentDateTime]) === false) {
                return false;
            }

            $directorIds[] = $this->pdo->lastInsertId();
        }

        // Complete the many-to-many table `video_actors`
        $sqlComplete = "INSERT INTO `video_directors`(`id`, `video_id`, `director_id`, `video_type_id`, `created_date_id`, `changed_date_id`) 
                        VALUES (?, ?, ?, ?, ?, ?)";
        if (($stmtComplete = $this->pdo->prepare($sqlComplete)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sqlComplete . '}');
            return false;
        }

        foreach ($directorIds as $directorId) {
            if ($stmtComplete->execute([$item['videoId'] . '-' . $directorId, $item['videoId'], $directorId, 1, $this->currentDateTime, $this->currentDateTime]) === false) {
                return false;
            }
        }

        return true;
    }

    private function saveCategoriesNCompleteVideoCategories(array &$item)
    {
        // Return true if no categories need to be inserted
        if (empty($item['categories'])) {
            return true;
        }

        // Start process
        $categoryIds = [];

        // Select PDOStatement
        $sqlSelect = "SELECT `id` FROM `categories` WHERE `name` = ?";
        if (($stmtSelect = $this->pdo->prepare($sqlSelect)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sqlSelect . '}');
            return false;
        }

        // Insert PDOStatement
        $sqlInsert = "INSERT INTO `categories`(`name`, `created_date_id`, `changed_date_id`) VALUES (?, ?, ?)";
        if (($stmtInsert = $this->pdo->prepare($sqlInsert)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sqlInsert . '}');
            return false;
        }

        foreach ($item['categories'] as $category) {
            // Get `actor_id` if the actor exists
            if ($stmtSelect->execute([$category]) === false) {
                IO::message('Failed to execute PDOStatement with sql {' . $sqlSelect . '}');
                return false;
            }

            $categoryId = $stmtSelect->fetchColumn();
            if ($categoryId) {
                $categoryIds[] = $categoryId;
                continue;
            }

            // Insert the new actor item, and return the inserted id
            if ($stmtInsert->execute([$category, $this->currentDateTime, $this->currentDateTime]) === false) {
                return false;
            }

            $categoryIds[] = $this->pdo->lastInsertId();
        }

        // Complete the many-to-many table `video_actors`
        $sqlComplete = "INSERT INTO `video_categories`(`id`, `video_id`, `category_id`, `video_type_id`, `created_date_id`, `changed_date_id`) 
                        VALUES (?, ?, ?, ?, ?, ?)";
        if (($stmtComplete = $this->pdo->prepare($sqlComplete)) === false) {
            IO::message('Failed to create PDOStatement with sql {' . $sqlComplete . '}');
            return false;
        }

        foreach ($categoryIds as $categoryId) {
            if ($stmtComplete->execute([$item['videoId'] . '-' . $categoryId, $item['videoId'], $categoryId, 1, $this->currentDateTime, $this->currentDateTime]) === false) {
                return false;
            }
        }

        return true;
    }

    #endregion
}
