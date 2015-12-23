<?php

namespace Bolt\Composer;

use Bolt\Filesystem\Handler\JsonFile;
use Bolt\Translation\Translator as Trans;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Ring\Client\ClientUtils;
use Silex\Application;

class PackageManager
{
    /** @var Application */
    protected $app;
    /** @var boolean */
    protected $started = false;
    /** @var boolean */
    protected $useSsl;

    /** @var array|null  */
    private $json;
    /** @var string[] */
    private $messages = [];
    /** @var string Holds the output from Composer\IO\BufferIO */
    private $ioOutput;

    /**
     * Constructor.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;

        // Set composer environment variables
        putenv('COMPOSER_HOME=' . $this->app['resources']->getPath('cache/composer'));

        $this->setup();
    }

    /**
     * Get the stored messages.
     *
     * @return string[]
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * Return the output from the last IO.
     *
     * @return string
     */
    public function getOutput()
    {
        return $this->app['extend.action.io']->getOutput();
    }

    /**
     * Set up function.
     *
     * - Copy/update the installer event class
     * - Update the composer.json
     * - Test connection to the server
     */
    private function setup()
    {
        if ($this->started) {
            return;
        }

        if ($this->app['extend.writeable']) {
            // Do required JSON update/set up
            $this->updateJson();

            // Ping the extensions server to confirm connection
            $this->ping(true);
        }

        $this->started = true;
    }

    /**
     * Check if we can/should use SSL/TLS/HTTP2 or HTTP.
     *
     * @return boolean
     */
    public function useSsl()
    {
        if ($this->useSsl !== null) {
            return $this->useSsl;
        }

        if (!extension_loaded('openssl')) {
            return $this->useSsl = false;
        }

        try {
            ClientUtils::getDefaultCaBundle();

            return $this->useSsl = true;
        } catch (\RuntimeException $e) {
            $this->messages[] = $e->getMessage();

            return $this->useSsl = false;
        }
    }

    /**
     * Check for packages that need to be installed or updated.
     *
     * @return array
     */
    public function checkPackage()
    {
        return $this->app['extend.action']['check']->execute();
    }

    /**
     * Dump fresh autoloader.
     */
    public function dumpAutoload()
    {
        return $this->app['extend.action']['autoload']->execute();
    }

    /**
     * Install configured packages.
     *
     * @return integer 0 on success or a positive error code on failure
     */
    public function installPackages()
    {
        return $this->app['extend.action']['install']->execute();
    }

    /**
     * Remove packages from the root install.
     *
     * @param $packages array Indexed array of package names to remove
     *
     * @return integer 0 on success or a positive error code on failure
     */
    public function removePackage(array $packages)
    {
        return $this->app['extend.action']['remove']->execute($packages);
    }

    /**
     * Require (install) packages.
     *
     * @param $packages array Associative array of package names/versions to remove
     *                        Format: ['name' => '', 'version' => '']
     *
     * @return integer 0 on success or a positive error code on failure
     */
    public function requirePackage(array $packages)
    {
        return $this->app['extend.action']['require']->execute($packages);
    }

    /**
     * Search for packages.
     *
     * @param $packages array Indexed array of package names to search
     *
     * @return array List of matching packages
     */
    public function searchPackage(array $packages)
    {
        return $this->app['extend.action']['search']->execute($packages);
    }

    /**
     * Show packages.
     *
     * @param string $target
     * @param string $package
     * @param string $version
     * @param bool   $root
     *
     * @return array
     */
    public function showPackage($target, $package = '', $version = '', $root = false)
    {
        return $this->app['extend.action']['show']->execute($target, $package, $version, $root);
    }

    /**
     * Update packages in the root install.
     *
     * @param  $packages array Indexed array of package names to update
     *
     * @return integer 0 on success or a positive error code on failure
     */
    public function updatePackage(array $packages)
    {
        return $this->app['extend.action']['update']->execute($packages);
    }

    /**
     * Initialise a new JSON file.
     *
     * @param string $file File to initialise
     * @param array  $data Data to be added as JSON paramter/value pairs
     */
    public function initJson($file, array $data = [])
    {
        $this->app['extend.manager.json']->init($file, $data);
    }

    /**
     * Get packages that a properly installed, pending installed and locally installed.
     *
     * @return array
     */
    public function getAllPackages()
    {
        $collection = new PackageCollection();
        $installed = $this->app['extend.action']['show']->execute('installed');
        $autoloaded = (array) $this->app['extensions']->getAutoload();
        $requires = isset($this->json['require']) ? $this->json['require'] : [];

        // Installed
        foreach ($installed as $composerPacakge) {
            $package = Package::createFromComposerPackage($composerPacakge['package']);
            $name = $package->getName();
            $extension = $this->app['extensions']->get($name);
            $title = $extension ? $extension->getName() : $name;

            $package->setStatus('installed');
            $package->setTitle($title);
            $package->setReadmeLink($this->linkReadMe($name));
            $package->setConfigLink($this->linkConfig($name));
            $package->setConstraint($autoloaded[$name]['constraint']);
            $package->setValid($autoloaded[$name]['valid']);
            $package->setEnabled(true);

            $collection->add($package);
        }

        // Local
        foreach ($autoloaded as $name => $data) {
            if ($collection->get($name)) {
                continue;
            }
            $extension = $this->app['extensions']->get($name);
            $title = $extension ? $extension->getName() : $name;
            /** @var JsonFile $composerJson */
            $composerJson = $this->app['filesystem']->get('extensions://' . $data['path'] . '/composer.json');
            $package = Package::createFromComposerJson($composerJson->parse());
            $package->setStatus('local');
            $package->setValid($autoloaded[$name]['valid']);
            $package->setEnabled(true);
            $package->setTitle($title);

            $collection->add($package);
        }

        // Pending
        foreach ($requires as $name => $version) {
            if ($collection->get($name)) {
                continue;
            }
            $package = new Package();
            $package->setStatus('pending');
            $package->setName($name);
            $package->setTitle($name);
            $package->setVersion($version);
            $package->setType('unknown');
            $package->setDescription(Trans::__('Not yet installed.'));

            $collection->add($package);
        }

        return $collection;
    }

    /**
     * Return the URI for a package's readme.
     *
     * @param string $name
     *
     * @return string
     */
    private function linkReadMe($name)
    {
        $autoloader = $this->app['extensions']->getAutoload();
        if (!isset($autoloader[$name])) {
            return;
        }

        $base = $this->app['resources']->getPath('extensionspath/' . $autoloader[$name]['path']);
        $location = strpos($autoloader[$name]['path'], 'local') === false ? 'vendor' : 'local';
        $readme = null;

        if (is_readable($base . '/README.md')) {
            $readme = $name . '/README.md';
        } elseif (is_readable($base . '/readme.md')) {
            $readme = $name . '/readme.md';
        }

        if (!$readme) {
            return;
        }

        return $this->app['url_generator']->generate('readme', ['location' => $location, 'filename' => $readme]);
    }

    /**
     * Return the URI for a package's config file edit window.
     *
     * @param string $name
     *
     * @return string
     */
    private function linkConfig($name)
    {
        // Generate the configFileName from the extension $name
        $configFileName = join('.', array_reverse(explode('/', $name))) . '.yml';

        // Check if we have a config file, and if it's readable. (yet)
        $configFilePath = $this->app['resources']->getPath('extensionsconfig/' . $configFileName);
        if (!is_readable($configFilePath)) {
            return;
        }

        return $this->app['url_generator']->generate('fileedit', ['namespace' => 'config', 'file' => 'extensions/' . $configFileName]);
    }

    /**
     * Set up Composer JSON file.
     */
    private function updateJson()
    {
        $this->json = $this->app['extend.manager.json']->update();
    }

    /**
     * Get an extension's composer.json data.
     *
     * @param $name
     *
     * @return array
     */
    private function getComposerJson($name)
    {
        $autoloadJsons = (array) $this->app['extensions']->getAutoload();
        foreach ($autoloadJsons as $autoloadJson) {
            if ($autoloadJson['name'] === $name) {
                /** @var JsonFile $jsonFile */
                $jsonFile = $this->app['filesystem']->get('extensions://' . $autoloadJson['path'] . '/composer.json');

                return $jsonFile->parse();
            }
        }

        return ['name' => 'unknown'];
    }

    /**
     * Ping site to see if we have a valid connection and it is responding correctly.
     *
     * @param boolean $addQuery
     */
    private function ping($addQuery = false)
    {
        $uri = $this->app['extend.site'] . 'ping';
        $query = [];
        if ($this->app['request_stack']->getCurrentRequest() !== null) {
            $www = $this->app['request_stack']->getCurrentRequest()->server->get('SERVER_SOFTWARE', 'unknown');
        } else {
            $www = 'unknown';
        }
        if ($addQuery) {
            $query = [
                'bolt_ver'  => $this->app['bolt_version'],
                'bolt_name' => $this->app['bolt_name'],
                'php'       => phpversion(),
                'www'       => $www,
            ];
        }

        try {
            $this->app['guzzle.client']->head($uri, ['query' => $query, 'exceptions' => true, 'connect_timeout' => 5, 'timeout' => 10]);

            $this->app['extend.online'] = true;
        } catch (ClientException $e) {
            // Thrown for 400 level errors
            $this->messages[] = Trans::__(
                'Client error: %errormessage%',
                ['%errormessage%' => $e->getMessage()]
            );
            $this->app['extend.online'] = false;
        } catch (ServerException $e) {
            // Thrown for 500 level errors
            $this->messages[] = Trans::__(
                'Extension server returned an error: %errormessage%',
                ['%errormessage%' => $e->getMessage()]
            );
            $this->app['extend.online'] = false;
        } catch (RequestException $e) {
            // Thrown for connection timeout, DNS errors, etc
            $this->messages[] = Trans::__(
                'Testing connection to extension server failed: %errormessage%',
                ['%errormessage%' => $e->getMessage()]
            );
            $this->app['extend.online'] = false;
        } catch (\Exception $e) {
            // Catch all
            $this->messages[] = Trans::__(
                'Generic failure while testing connection to extension server: %errormessage%',
                ['%errormessage%' => $e->getMessage()]
            );
            $this->app['extend.online'] = false;
        }
    }
}
