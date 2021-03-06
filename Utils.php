<?php

namespace Kryn\CmsBundle;

use Kryn\CmsBundle\Model\AppLockQuery;
use Kryn\CmsBundle\Model\Base\NodeQuery;
use Symfony\Component\HttpFoundation\Response;

class Utils
{
    protected $cachedPageToUrl = [];

    /**
     * @var Core
     */
    protected $krynCore;

    function __construct(Core $krynCore)
    {
        $this->krynCore = $krynCore;
    }

    /**
     * @param Core $krynCore
     */
    public function setKrynCore($krynCore)
    {
        $this->krynCore = $krynCore;
    }

    /**
     * @return Core
     */
    public function getKrynCore()
    {
        return $this->krynCore;
    }

    public function getComposerArray($bundleClass)
    {
        $path = $this->getKrynCore()->getBundleDir($bundleClass);
        $fs = $this->getKrynCore()->getFileSystem();
        if ($fs->has($file = $path . '/composer.json')) {
            return json_decode($fs->read($file), true);
        }
    }

    /**
     * Creates a temp folder and returns its path.
     * Please use TempFile::createFolder() class instead.
     *
     * @static
     * @internal
     *
     * @param  string $prefix
     * @param  bool   $fullPath Returns the full path on true and the relative to the current TempFolder on false.
     *
     * @return string Path with trailing slash
     */
    public function createTempFolder($prefix = '', $fullPath = true)
    {
        $tmp = $this->getKrynCore()->getKernel()->getCacheDir();

        do {
            $path = $tmp . $prefix . dechex(time() / mt_rand(100, 500));
        } while (is_dir($path));

        mkdir($path);

        if ('/' !== substr($path, -1)) {
            $path .= '/';
        }

        return $fullPath ? $path : substr($path, strlen($tmp));
    }

    /**
     * @param string $text
     */
    public function showFullDebug($text = null)
    {
        $exception = new \InternalErrorException();
        $exception->setMessage($text ? : 'Debug stop.');

        static::exceptionHandler($exception);
    }

    /**
     * Returns Domain object
     *
     * @param int $domainId If not defined, it returns the current domain.
     *
     * @return \Kryn\CmsBundle\Model\Domain
     * @static
     */
    public function getDomain($domainId = null)
    {
        if (!$domainId) {
            return $this->getKrynCore()->getCurrentDomain();
        }

        if ($domainSerialized = $this->getKrynCore()->getDistributedCache('core/object-domain/' . $domainId)) {
            return unserialize($domainSerialized);
        }

        $domain = Model\DomainQuery::create()->findPk($domainId);

        if (!$domain) {
            return false;
        }

        $this->getKrynCore()->setDistributedCache('core/object-domain/' . $domainId, serialize($domain));

        return $domain;
    }

    /**
     * Returns a super fast cached Page object.
     *
     * @param  int $pageId If not defined, it returns the current page.
     *
     * @return \Page
     * @static
     */
    public function getPage($pageId = null)
    {
        if (!$pageId) {
            return $this->getKrynCore()->getCurrentPage();
        }

        $data = $this->getKrynCore()->getDistributedCache('core/object/node/' . $pageId);

        if (!$data) {
            $page = NodeQuery::create()->findPk($pageId);
            $this->getKrynCore()->setDistributedCache('core/object/node/' . $pageId, serialize($page));
        } else {
            $page = unserialize($data);
        }

        return $page ? : false;
    }

    /**
     * Returns the domain of the given $id page.
     *
     * @static
     *
     * @param  integer $id
     *
     * @return integer|null
     */
    public function getDomainOfPage($id)
    {
        $id2 = null;

        $page2Domain = $this->getKrynCore()->getDistributedCache('core/node/toDomains');

        if (!is_array($page2Domain)) {
            $page2Domain = $this->updatePage2DomainCache();
        }

        $id = ',' . $id . ',';
        foreach ($page2Domain as $domain_id => &$pages) {
            $pages = ',' . $pages . ',';
            if (strpos($pages, $id) !== false) {
                $id2 = $domain_id;
            }
        }

        return $id2;
    }

    public function updatePage2DomainCache()
    {
        $r2d = array();
        $items = NodeQuery::create()
            ->select(['Id', 'DomainId'])
            ->find();

        foreach ($items as $item) {
            $r2d[$item['DomainId']] = (isset($r2d[$item['DomainId']]) ? $r2d[$item['DomainId']] : '') . $item['Id'] . ',';
        }

        $this->getKrynCore()->setDistributedCache('core/node/toDomains', $r2d);

        return $r2d;
    }

    /**
     * @param  integer $domainId
     *
     * @return array
     */
    public function &getCachedPageToUrl($domainId)
    {
        if (isset($cachedPageToUrl[$domainId])) {
            return $cachedPageToUrl[$domainId];
        }

        $cachedPageToUrl[$domainId] = array_flip($this->getCachedUrlToPage($domainId));

        return $cachedPageToUrl[$domainId];
    }

    public function &getCachedUrlToPage($domainId)
    {
        $cacheKey = 'core/urls/' . $domainId;
        $urls = $this->getKrynCore()->getDistributedCache($cacheKey);

        if (!$urls) {

            $nodes = NodeQuery::create()
                ->select(array('id', 'urn', 'lvl', 'type'))
                ->filterByDomainId($domainId)
                ->orderByBranch()
                ->find();

            //build urls array
            $urls = array();
            $level = array();

            foreach ($nodes as $node) {
                if ($node['lvl'] == 0) {
                    continue;
                } //root
                if ($node['type'] == 3) {
                    continue;
                } //deposit

                if ($node['type'] == 2 || $node['urn'] == '') {
                    //folder or empty url
                    $level[$node['lvl'] + 0] = isset($level[$node['lvl'] - 1]) ? $level[$node['lvl'] - 1] : '';
                    continue;
                }

                $url = isset($level[$node['lvl'] - 1]) ? $level[$node['lvl'] - 1] : '';
                $url .= '/' . $node['urn'];

                $level[$node['lvl'] + 0] = $url;

                $urls[$url] = $node['id'];
            }

            $this->getKrynCore()->setDistributedCache($cacheKey, $urls);
        }

        return $urls;
    }

    /**
     * @param array $files
     * @param string $includePath The directory where to compressed css is. with trailing slash!
     *
     * @return string
     */
    public function compressCss(array $files, $includePath = '')
    {
        $toGecko = array(
            "-moz-border-radius-topleft",
            "-moz-border-radius-topright",
            "-moz-border-radius-bottomleft",
            "-moz-border-radius-bottomright",
            "-moz-border-radius",
        );

        $toWebkit = array(
            "-webkit-border-top-left-radius",
            "-webkit-border-top-right-radius",
            "-webkit-border-bottom-left-radius",
            "-webkit-border-bottom-right-radius",
            "-webkit-border-radius",
        );
        $from = array(
            "border-top-left-radius",
            "border-top-right-radius",
            "border-bottom-left-radius",
            "border-bottom-right-radius",
            "border-radius",
        );

        $webDir = realpath($this->getKrynCore()->getKernel()->getRootDir().'/../web') .'/';
        $content = '';
        foreach ($files as $assetPath) {

            $cssFile = $this->getKrynCore()->resolveWebPath($assetPath); //bundles/kryncms/css/style.css
            $cssDir = dirname($cssFile) . '/'; //admin/css/...
            $cssDir = str_repeat('../', substr_count($includePath, '/')) . $cssDir;

            $content .= "\n\n/* file: $assetPath */\n\n";
            if (file_exists($file = $webDir . $cssFile)) {
                $h = fopen($file, "r");
                if ($h) {
                    while (!feof($h) && $h) {
                        $buffer = fgets($h, 4096);

                        $buffer = preg_replace('/@import \'([^\/].*)\'/', '@import \'' . $cssDir . '$1\'', $buffer);
                        $buffer = preg_replace('/@import "([^\/].*)"/', '@import "' . $cssDir . '$1"', $buffer);
                        $buffer = preg_replace('/url\(\'([^\/][^\)]*)\'\)/', 'url(\'' . $cssDir . '$1\')', $buffer);
                        $buffer = preg_replace('/url\((?!data:image)([^\/\'].*)\)/', 'url(' . $cssDir . '$1)', $buffer);
                        $buffer = str_replace(array('  ', '    ', "\t", "\n", "\r"), '', $buffer);
                        $buffer = str_replace(': ', ':', $buffer);

                        $content .= $buffer;
                        $newLine = str_replace($from, $toWebkit, $buffer);
                        if ($newLine != $buffer) {
                            $content .= $newLine;
                        }
                        $newLine = str_replace($from, $toGecko, $buffer);
                        if ($newLine != $buffer) {
                            $content .= $newLine;
                        }
                    }
                    fclose($h);
                }
            } else {
                $content .= '/* => `' . $cssFile . '` not exist. */';
                $this->getKrynCore()->getLogger()->error(
                    sprintf('Can not find css file `%s` [%s]', $file, $assetPath)
                );
            }
        }

        return $content;
    }

    /**
     * Stores all locked keys, so that we can release all,
     * on process terminating.
     *
     * @var array
     */
    public $lockedKeys = array();

    /**
     * Releases all locked aquired by this process.
     *
     * Will be called during process shutdown. (register_shutdown_function)
     */
    public function releaseLocks()
    {
        foreach ($this->lockedKeys as $key => $value) {
            self::appRelease($key);
        }
    }

    /**
     * Locks the process until the lock of $id has been acquired for this process.
     * If no lock has been acquired for this id, it returns without waiting true.
     *
     * Waits max 15seconds.
     *
     * @param  string $id
     * @param  integer $timeout Milliseconds
     *
     * @return boolean
     */
    public function appLock($id, $timeout = 15)
    {

        //when we'll be caleed, then we register our releaseLocks
        //to make sure all locks are released.
        register_shutdown_function([$this, 'releaseLocks']);

        if (self::appTryLock($id, $timeout)) {
            return true;
        } else {
            for ($i = 0; $i < 1000; $i++) {
                usleep(15 * 1000); //15ms
                if (self::appTryLock($id, $timeout)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Tries to lock given id. If the id is already locked,
     * the function returns without waiting.
     *
     * @see appLock()
     *
     * @param  string $id
     * @param  int $timeout Default is 30sec
     *
     * @return bool
     */
    public function appTryLock($id, $timeout = 30)
    {
        //already aquired by this process?
        if ($this->lockedKeys[$id] === true) {
            return true;
        }

        $now = ceil(microtime(true) * 1000);
        $timeout2 = $now + $timeout;

        dbDelete('system_app_lock', 'timeout <= ' . $now);

        try {
            dbInsert('system_app_lock', array('id' => $id, 'timeout' => $timeout2));
            $this->lockedKeys[$id] = true;

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Releases a lock.
     * If you're not the owner of the lock with $id, then you'll kill it anyway.
     *
     * @param string $id
     */
    public function appRelease($id)
    {
        unset($this->lockedKeys[$id]);

        try {
            AppLockQuery::create()->filterById($id)->delete();
            dbDelete('system_app_lock', array('id' => $id));
        } catch (\Exception $e) {
        }
    }

    /**
     * Returns cached propel object.
     *
     * @param  int   $objectClassName If not defined, it returns the current page.
     * @param  mixed $objectPk        Propel PK for $objectClassName int, string or array
     *
     * @return mixed Propel object
     * @static
     */
    public function getPropelCacheObject($objectClassName, $objectPk)
    {
        if (is_array($objectPk)) {
            $npk = '';
            foreach ($objectPk as $k) {
                $npk .= urlencode($k) . '_';
            }
        } else {
            $pk = urlencode($objectPk);
        }

        $cacheKey = 'core/object-caching.' . strtolower(preg_replace('/[^\w]/', '.', $objectClassName)) . '/' . $pk;
        if ($serialized = $this->getKrynCore()->getDistributedCache($cacheKey)) {
            return unserialize($serialized);
        }

        return $this->setPropelCacheObject($objectClassName, $objectPk);
    }

    /**
     * Returns propel object and cache it.
     *
     * @param int   $objectClassName If not defined, it returns the current page.
     * @param mixed $objectPk        Propel PK for $objectClassName int, string or array
     * @param mixed $object          Pass the object, if you did already fetch it.
     *
     * @return mixed Propel object
     */
    public function setPropelCacheObject($object2ClassName, $object2Pk, $object = false)
    {
        $pk = $object2Pk;
        if ($pk === null && $object) {
            $pk = $object->getPrimaryKey();
        }

        if (is_array($pk)) {
            $npk = '';
            foreach ($pk as $k) {
                $npk .= urlencode($k) . '_';
            }
        } else {
            $pk = urlencode($pk);
        }

        $cacheKey = 'core/object-caching.' . strtolower(preg_replace('/[^\w]/', '.', $object2ClassName)) . '/' . $pk;

        $clazz = $object2ClassName . 'Query';
        $object2 = $object;
        if (!$object2) {
            $object2 = $clazz::create()->findPk($object2Pk);
        }

        if (!$object2) {
            return false;
        }

        $this->getKrynCore()->setDistributedCache($cacheKey, serialize($object2));

        return $object2;

    }

    /**
     * Removes a object from the cache.
     *
     * @param int   $objectClassName If not defined, it returns the current page.
     * @param mixed $objectPk        Propel PK for $objectClassName int, string or array
     */
    public function removePropelCacheObject($objectClassName, $objectPk = null)
    {
        $pk = $objectPk;
        if ($pk !== null) {
            if (is_array($pk)) {
                $npk = '';
                foreach ($pk as $k) {
                    $npk .= urlencode($k) . '_';
                }
            } else {
                $pk = urlencode($pk);
            }
        }
        $cacheKey = 'core/object-caching.' . strtolower(preg_replace('/[^\w]/', '.', $objectClassName));

        if ($objectPk) {
            $this->getKrynCore()->deleteDistributedCache($cacheKey . '/' . $pk);
        } else {
            $this->getKrynCore()->invalidateCache($cacheKey);
        }
    }

}
