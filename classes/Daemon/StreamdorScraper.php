<?php

namespace DT\Daemon;

use DT\Base;
use DT\Common\Curl;
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


    public function __construct($connection = null)
    {
        $this->apiPage = $this->baseUri . '/Entities?entityName=&topic=&limitPerPage=' . $this->limitPerPage . '&pageNumber=';
        $this->apiItem = $this->baseUri . '/EntityDetails?&ignoreMediaLinkError=false&entityName=';

        parent::__construct($connection);
    }

    #region Methods
    public function scrap()
    {
        // Get video names in each page by retrieving the page with the specified page number
        $pageNumber = 1;
        $pageTotal  = 1;

        while ($pageNumber <= $pageTotal) {
            if (($response = $this->curlPage($pageNumber)) === false) {
                $message = 'Failed to get response of page {' . $pageNumber . '} with URL: {' . $this->apiPage . $pageNumber . '}';
                IO::message($message);
                IO::log('streamdor-scraper.txt', $message);
                return;
            }

            IO::log('page.txt', 'Page response:');
            IO::log('page.txt', print_r($response, true));
            IO::log('page.txt', PHP_EOL . PHP_EOL);
            //return;

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

                IO::log('page.txt', 'Item response:');
                IO::log('page.txt', var_export($itemDetails, true));
                IO::log('page.txt', PHP_EOL);

                // TODO Construct $itemArray for saving into db
                $itemArray[] = [];
            }

            return;

            if (!empty($itemFailed)) {
                $message = 'Failed to get details of item {' . implode(', ', $itemFailed) . '} in page {' . $pageNumber . '}';
                IO::message($message);
                IO::log('streamdor-scraper.txt', $message);
            }

            // TODO Save $itemArray into db
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
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json'
                    ],
                    CURLOPT_POSTFIELDS     => json_encode(['Language' => 'English'])
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

                echo $this->apiItem . $videoName . PHP_EOL;

                $response = Curl::getResult($curl);
                $response = json_decode($response, true);
                print_r($response);
                break;
            } catch (\Exception $e) {
                continue;
            }
        }

        return $response;
    }

    private function saveToDb(&$items)
    {
        // TODO save $items into db

    }
    #endregion
}
