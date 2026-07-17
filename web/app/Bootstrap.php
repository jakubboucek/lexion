<?php declare(strict_types=1);

namespace App;

use Nette;
use Nette\Bootstrap\Configurator;


class Bootstrap
{
    private readonly Configurator $configurator;
    private readonly string $rootDir;


    public function __construct()
    {
        $this->rootDir = dirname(__DIR__);
        $this->configurator = new Configurator;
        $this->configurator->setTempDirectory($this->rootDir . '/temp');
    }


    public function bootWebApplication(): Nette\DI\Container
    {
        $this->initializeEnvironment();
        $this->setupContainer();
        return $this->configurator->createContainer();
    }


    /** Container for CLI tools (bin/, latte-lint) – same config, no HTTP context expected. */
    public function bootConsoleApplication(): Nette\DI\Container
    {
        $this->initializeEnvironment();
        $this->setupContainer();
        return $this->configurator->createContainer();
    }


    public function initializeEnvironment(): void
    {
        // Detector returns null when the mode cannot be determined – default to production.
        $debugMode = \Redbitcz\DebugMode\Detector::detect() ?? false;

        $this->configurator->setDebugMode($debugMode);
        $this->configurator->enableTracy($this->rootDir . '/log');

        $this->configurator->createRobotLoader()
            ->addDirectory(__DIR__)
            ->register();
    }


    private function setupContainer(): void
    {
        $configDir = $this->rootDir . '/config';
        $this->configurator->addConfig($configDir . '/common.neon');
        $this->configurator->addConfig($configDir . '/services.neon');
        // Environment-specific overrides (DB credentials, …); gitignored.
        $this->configurator->addConfig($configDir . '/local.neon');
    }
}
