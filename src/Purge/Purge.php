<?php

namespace SteadLane\Cloudflare;

use function Sentry\captureMessage;
use Sentry\Severity;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Control\Director;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SteadLane\Cloudflare\Messages\Notifications;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Class Purge
 * @package SteadLane\Cloudflare
 */
class Purge
{
    use Injectable;
    use Extensible;
    use Configurable;

    private static $chunk_size = 30;

    /**
     * @var string
     */
    protected $successMessage;

    /**
     * @var string
     */
    protected $failureMessage;

    /**
     * @var bool
     */
    protected $testOnly = false;

    /**
     * @var string
     */
    protected $testResultSuccess;

    /**
     * @var array
     */
    protected $files;

    /**
     * @var bool
     */
    protected $purgeEverything = false;

    /**
     * @var
     */
    protected $response;

    /**
     * @var array
     */
    protected $fileTypes = array(
        'image' => array(
            "bmp", "gif", "jpg", "jpeg", "pcx", "tif", "png", "alpha", "als", "cel", "icon", "ico", "ps", "svg"
        ),
        'javascript' => array(
            "js"
        ),
        'css' => array(
            'css', 'css.map'
        )
    );

    /**
     * @var string
     */
    protected static $endpoint = "https://api.cloudflare.com/client/v4/zones/:identifier/purge_cache";

    /**
     * @param array $files
     * @return $this
     */
    public function setFiles(array $files)
    {
        $this->clearFiles();
        $this->pushFile($files);

        return $this;
    }

    /**
     * @param string|array $file
     * @return $this
     */
    public function pushFile($file)
    {
        if (!is_array($this->files)) {
            $this->files = array();
        }

        if (is_array($file)) {
            foreach ($file as $pointer) {
                $this->pushFile($pointer);
            }

            return $this;
        }

        array_push($this->files, $this->convertToAbsolute($file));

        return $this;
    }

    /**
     * Recursively find files with a specific extension(s) starting at the document root
     *
     * @param string|array $extensions
     * @param null|string $dir A directory relevant to the project root, if null the entire project root will be searched
     * @return $this
     */
    public function findFilesWithExts($extensions, $dir = null)
    {
        $files = array();
        $rootDir = rtrim(str_replace('//', '/', $_SERVER['DOCUMENT_ROOT'] . Director::baseURL() . "/" . $dir), '/');

        if (is_array($extensions)) {
            foreach ($extensions as &$ext) {
                $ext = ltrim($ext, '.');
            }
            $extensions = implode("|", $extensions);
        }

        $extensions = ltrim($extensions, '.');
        $pattern = sprintf('/.(%s)$/i', $extensions);

        if (is_string($extensions)) {
            $files = $this->fileSearch($rootDir, $pattern);
        }

        $this->pushFile($files);

        return $this;
    }

    /**
     * Recursive glob-like function
     *
     * @param string $dir
     * @param string $pattern Fully qualified regex pattern
     * @return array|bool
     */
    public function fileSearch($dir, $pattern)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array();
        $this->fileSearchAux($dir, $pattern, $files);
        return $files;
    }

    /**
     * Auxiliary function to avoid writing temporary temporary lists on the way back
     *
     * @param string $dir
     * @param string $pattern
     * @param array $files
     */
    private function fileSearchAux($dir, $pattern, &$files)
    {
        $handle = opendir($dir);
        if ($handle) {
            while (($file = readdir($handle)) !== false) {

                if ($file == '.' || $file == '..') {
                    continue;
                }

                $filePath = $dir == '.' ? $file : $dir . '/' . $file;

                if (is_link($filePath)) {
                    continue;
                }

                if (is_file($filePath)) {
                    if (preg_match($pattern, $filePath)) {
                        $files[] = $filePath;
                    }
                }

                if (is_dir($filePath) && !$this->isBlacklisted($file)) {
                    $this->fileSearchAux($filePath, $pattern, $files);
                }
            }
            closedir($handle);
        }
    }


    /**
     * Converts /public_html/path/to/file.ext to example.com/path/to/file.ext, it is perfectly safe to hand this
     * an "already absolute" url.
     *
     * @param string|array $files
     * @return string|array|bool Dependent on input, returns false if input is neither an array, or a string.
     */
    public function convertToAbsolute($files)
    {
        // It's not the best feeling to have to add http:// here, despite it's SSL variant being picked up
        // by getUrlVariants(). However without it cloudflare will respond with an error similar to:
        // "You may only purge files for this zone only"
        // @TODO: get rid of this stupidity, surely we don't need to use DOCUMENT_ROOT at all?
        $baseUrl = "http://" . CloudFlare::singleton()->getServerName() . "/";
        $rootDir = str_replace("//", "/", $_SERVER['DOCUMENT_ROOT']);

        if (is_array($files)) {
            foreach ($files as $index => $file) {
                $basename = basename($file);
                $basenameEncoded = urlencode($basename);
                $file = str_replace($basename, $basenameEncoded, $file);

                $files[$index] = str_replace($rootDir, $baseUrl, $file);
                $files[$index] = str_replace($baseUrl . '/', $baseUrl, $files[$index]); // stooopid
                $files[$index] = str_replace($baseUrl . '\\/', $baseUrl, $files[$index]); // stoooopid
            }

            return $files;
        }

        if (is_string($files)) {
            $basename = basename($files);
            $basenameEncoded = urlencode($basename);
            $files = str_replace($basename, $basenameEncoded, $files);

            $files = str_replace($rootDir, $baseUrl, $files);
            $files = str_replace($baseUrl . '/', $baseUrl, $files); // stoooooopid
            return str_replace($baseUrl . '\\/', $baseUrl, $files); // stooooooooooopid
        }

        return false;
    }

    /**
     * @return int
     */
    public function count()
    {
        return (is_array($this->files)) ? count($this->files) : 0;
    }

    /**
     * @return $this
     */
    public function purge()
    {
        $files = $this->getFiles();

        $this->extend("updateFilesBeforePurge", $files);

        if ($this->purgeEverything) {
            $data = array(
                "purge_everything" => true
            );
        } else {
            $data = array(
                "files" => $files
            );
        }

        CloudFlare::debug("Purge::purge() / data = ", $data);
        $this->setResponse($this->handleRequest($data));

        $success = $this->isSuccessful();

        Notifications::handleMessage(
            ($success) ? ($this->getSuccessMessage() ?: false) : ($this->getFailureMessage() ?: false),
            array(
                'file_count' => $this->count()
            )
        );

        return $this;
    }

    /**
     * @return null|array
     */
    public function getFiles()
    {
        return $this->files;
    }

    /**
     * @param bool $bool    If true, no request to CloudFlare will actually be made and instead you will receive a mock
     *                      response
     * @param bool $success True to simulate a successful request, or false to simulate a failure
     *
     * @return $this
     */
    public function setTestOnly($bool, $success)
    {
        $this->testOnly = $bool;
        $this->testResultSuccess = $success;

        return $this;
    }

    /**
     * @param $response
     *
     * @return $this
     */
    public function setResponse($response)
    {
        $this->extend("onBeforeSetResponse", $response);

        $this->response = $response;

        return $this;
    }

    /**
     * Handles requests for cache purging
     *
     * @param array|null $data
     * @param string $method
     *
     * @param bool $isRecursing
     * @return string|array
     */
    public function handleRequest(array $data = null, $isRecursing = null, $method = 'DELETE')
    {
        if (array_key_exists('files', $data) && !$isRecursing) {
            // get URL variants
            $data['files'] = $this->getUrlVariants($data['files']);
        }

        $chunkSize = $this->config()->chunk_size;

        if (array_key_exists('files', $data) && count($data['files']) > $chunkSize) {
            // slice the array into chunks of $chunkSize then recursively call this function.
            // cloudflare limits cache purging to $chunkSize files per request.
            $chunks = ceil(count($data['files']) / $chunkSize);
            $start = 0;
            $responses = array();

            for ($i = 0; $i < $chunks; $i++) {
                $chunk = array_slice($data['files'], $start, $chunkSize);
                $result = $this->handleRequest(array('files' => $chunk), true);
                $responses[] = json_decode($result, true);
                $start += $chunkSize;
            }

            return $responses;
        }

        if ($this->testOnly) {
            return CloudFlare::getMockResponse('Purge', $this->testResultSuccess);
        }

        $response = CloudFlare::singleton()->curlRequest($this->getEndpoint(), $data, $method);

        return $response;
    }

    /**
     * Generates URL variants (Stage urls, HTTPS, Non-HTTPS)
     *
     * @param $urls
     *
     * @return array
     */
    public function getUrlVariants($urls)
    {
        $output = array();

        foreach ($urls as $url) {
            $output[] = $url;

            // HTTPS Equiv
            if (strstr($url, "http://") && !in_array(str_replace("http://", "https://", $url), $output)) {
                $output[] = str_replace("http://", "https://", $url);
            }

            // HTTP Equiv
            if (strstr($url, "https://") && !in_array(str_replace("https://", "http://", $url), $output)) {
                $output[] = str_replace("http://", "https://", $url);
            }
        }

        $this->extend("onAfterGetUrlVariants", $output);

        return $output;
    }

    /**
     * @return string
     */
    public function getEndpoint()
    {
        $zoneId = CloudFlare::singleton()->fetchZoneID();
        return str_replace(":identifier", $zoneId, static::$endpoint);
    }

    /**
     * @return bool
     */
    public function isSuccessful()
    {
        $response = $this->getResponse();

        if (!is_array($response)) {
            return false;
        }

        if (array_key_exists("0", $response)) {
            // multiple responses in payload, all of them need to be successful otherwise return false;
            foreach ($response as $singular) {
                if ($singular['success']) {
                    continue;
                }

                return false;
            }

            return true;
        }

        if (array_key_exists('success', $response) && $response['success']) {
            return true;
        }

        return false;
    }

    /**
     * @return array
     */
    public function getResponse()
    {
        $response = $this->response;
        if (is_string($response)) {
            $response = json_decode($response, true);
        }

        return $response;
    }

    /**
     * @param bool $bool
     * @return $this
     */
    public function setPurgeEverything($bool = null)
    {
        $this->purgeEverything = ($bool);
        return $this;
    }


    /**
     * @param string $failureMessage
     * @return $this
     */
    public function setFailureMessage($failureMessage)
    {
        $this->failureMessage = $failureMessage;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getFailureMessage()
    {
        return $this->failureMessage;
    }

    /**
     * @param string $successMessage
     * @return $this
     */
    public function setSuccessMessage($successMessage)
    {
        $this->successMessage = $successMessage;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getSuccessMessage()
    {
        return $this->successMessage;
    }

    /**
     * Should we purge related Pages as well as the Page/file/URL that is requested?
     *
     * @return bool
     */
    public function getShouldPurgeRelations()
    {
        return (bool) CloudFlare::config()->should_purge_relations;
    }

    /**
     * Resets the instance
     *
     * @return $this
     */
    public function reset()
    {
        $this->clearFiles();

        $this->response = null;
        $this->successMessage = null;
        $this->failureMessage = null;
        $this->purgeEverything = false;

        return $this;
    }

    /**
     * Clears files
     *
     * @return $this
     */
    public function clearFiles()
    {
        $this->files = null;

        return $this;
    }

    /**
     * @return array
     */
    public function getFileTypes()
    {

        $types = $this->fileTypes;
        $this->extend('updateCloudFlarePurgeFileTypes', $types);

        return $types;
    }

    /**
     * Checks to see if a certain directory is blacklisted from the fileSearch functionality
     *
     * @param $dir
     *
     * @return bool
     */
    public function isBlacklisted($dir)
    {
        if (!is_array($blacklist = CloudFlare::config()->purge_dir_blacklist)) {
            return false;
        }

        if (in_array($dir, $blacklist)) {
            return true;
        }

        return false;
    }

    /**
     * Allows you to quickly purge cache for particular files defined in $fileTypes (See ::getFileTypes() for an
     * extension point to update file types)
     *
     * @param string $what E.g 'image', 'javascript', 'css', or user defined
     *
     * @param null   $other_id Allows you to provide a Page ID for example
     *
     * @return bool
     */
    public function quick($what, $other_id = null)
    {
        // create a new instance of self so we don't interrupt anything
        $purger = self::create();
        $what = trim(strtolower($what));

        if ($what == 'page' && isset($other_id)) {
            if (!($other_id instanceof SiteTree)) {
                $other_id = DataObject::get_by_id(SiteTree::class, $other_id);
            }
            $page = $other_id;

            $purger
                ->pushFile(str_replace("//", "/", $_SERVER['DOCUMENT_ROOT'] . "/" . $page->Link()))
                ->setSuccessMessage('Cache has been purged for: ' . $page->Link())
                ->purge();

            return $purger->isSuccessful();
        }

        if ($what == 'all') {
            if(CloudFlare::config()->purge_all !== true) {
                QueuedJobService::singleton()->queueJob(
                    Injector::inst()->create(PurgePagesJob::class)
                );

                Notifications::handleMessage(
                    _t(
                        "CloudFlare.SuccessCriticalElementChanged",
                        "A critical element has changed in this page (url, menu label, or page title) as a result; everything was purged"
                    )
                );
            } else {
                $purger->setPurgeEverything(true)->purge();
            }

            return $purger->isSuccessful();
        }

        $fileTypes = $this->getFileTypes();

        if (!isset($fileTypes[$what])) {
            user_error("Attempted to purge all {$what} types but it has no file extension list defined. See CloudFlare_Purge::\$fileTypes", E_USER_ERROR);
        }

        $purger->findFilesWithExts($fileTypes[$what]);

        if (!$purger->count()) {
            Notifications::handleMessage(
                _t(
                    "CloudFlare.NoFilesToPurge",
                    "No {what} files were found to purge.",
                    "",
                    array(
                        "what" => $what
                    )
                )
            );
        } else {
            $purger->setSuccessMessage(
                _t(
                    "CloudFlare.SuccessFilesPurged",
                    "Successfully purged {file_count} {what} files from cache.",
                    "",
                    array(
                        "what" => $what
                    )
                )
            );

            $purger->purge();
        }

        return $purger->isSuccessful();
    }
}
