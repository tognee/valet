<?php

namespace Valet;

class Status
{
    public $brewServicesUserOutput;

    public $brewServicesRootOutput;

    public $debugInstructions = [];

    public function __construct(public Configuration $config, public Brew $brew, public CommandLine $cli, public Filesystem $files) {}

    /**
     * Check the status of the entire Valet ecosystem and return a status boolean
     * and a set of individual checks and their respective booleans as well.
     */
    public function check(): array
    {
        $isValid = true;

        $output = collect($this->checks())->map(function (array $check) use (&$isValid) {
            if (! $thisIsValid = $check['check']()) {
                $this->debugInstructions[] = $check['debug'];
                $isValid = false;
            }

            return ['description' => $check['description'], 'success' => $thisIsValid ? 'Yes' : 'No'];
        });

        return [
            'success' => $isValid,
            'output' => $output->all(),
            'debug' => $this->debugInstructions(),
        ];
    }

    /**
     * Define a list of checks to test the health of the Valet ecosystem of tools and configs.
     */
    public function checks(): array
    {
        $linkedPhp = $this->brew->getLinkedPhpFormula();

        return [
            [
                'description' => 'Is Valet fully installed?',
                'check' => function () {
                    return $this->valetInstalled();
                },
                'debug' => 'Run `composer require laravel/valet` and `valet install`.',
            ],
            [
                'description' => 'Is Valet config valid?',
                'check' => function () {
                    try {
                        $config = $this->config->read();

                        foreach (['tld', 'loopback', 'paths'] as $key) {
                            if (! array_key_exists($key, $config)) {
                                $this->debugInstructions[] = 'Your Valet config is missing the "'.$key.'" key. Re-add this manually, or delete your config file and re-install.';

                                return false;
                            }
                        }

                        return true;
                    } catch (\JsonException $e) {
                        return false;
                    }
                },
                'debug' => 'Run `valet install` to update your configuration.',
            ],
            [
                'description' => 'Is Homebrew installed?',
                'check' => function () {
                    return $this->cli->run('which brew') !== '';
                },
                'debug' => 'Visit https://brew.sh/ for instructions on installing Homebrew.',
            ],
            [
                'description' => 'Is DnsMasq installed?',
                'check' => function () {
                    return $this->brew->installed('dnsmasq');
                },
                'debug' => 'Run `valet install`.',
            ],
            [
                'description' => 'Is Dnsmasq running?',
                'check' => function () {
                    return $this->isBrewServiceRunning('dnsmasq');
                },
                'debug' => 'Run `valet restart`.',
            ],
            [
                'description' => 'Is Dnsmasq running as root?',
                'check' => function () {
                    return $this->isBrewServiceRunningAsRoot('dnsmasq');
                },
                'debug' => 'Uninstall Dnsmasq with Brew and run `valet install`.',
            ],
            [
                'description' => 'Is Nginx installed?',
                'check' => function () {
                    return $this->brew->installed('nginx') || $this->brew->installed('nginx-full');
                },
                'debug' => 'Run `valet install`.',
            ],
            [
                'description' => 'Is Nginx running?',
                'check' => function () {
                    return $this->isBrewServiceRunning('nginx');
                },
                'debug' => 'Run `valet restart`.',
            ],
            [
                'description' => 'Is Nginx running as root?',
                'check' => function () {
                    return $this->isBrewServiceRunningAsRoot('nginx');
                },
                'debug' => 'Uninstall nginx with Brew and run `valet install`.',
            ],
            [
                'description' => 'Is PHP installed?',
                'check' => function () {
                    return $this->brew->hasInstalledPhp();
                },
                'debug' => 'Run `valet install`.',
            ],
            [
                'description' => 'Is linked PHP ('.$linkedPhp.') running?',
                'check' => function () use ($linkedPhp) {
                    return $this->isBrewServiceRunning($linkedPhp);
                },
                'debug' => 'Run `valet restart`.',
            ],
            [
                'description' => 'Is linked PHP ('.$linkedPhp.') running as root?',
                'check' => function () use ($linkedPhp) {
                    return $this->isBrewServiceRunningAsRoot($linkedPhp);
                },
                'debug' => 'Uninstall PHP with Brew and run `valet use php@8.2`',
            ],
            [
                'description' => 'Is valet.sock present?',
                'check' => function () {
                    return $this->files->exists(VALET_HOME_PATH.'/valet.sock');
                },
                'debug' => 'Run `valet install`.',
            ],
        ];
    }

    /**
     * Protected property to cache systemd service status.
     */
    protected ?array $systemdServicesOutput = null;

    /**
     * Check if a service is running (systemd equivalent of brew service check).
     */
    public function isBrewServiceRunning(string $name, bool $exactMatch = true): bool
    {
        // In Linux/systemd context, all services run as root
        return $this->isBrewServiceRunningAsRoot($name, $exactMatch);
    }

    /**
     * Check if a service is running as root (via systemd).
     */
    public function isBrewServiceRunningAsRoot(string $name, bool $exactMatch = true): bool
    {
        if (!$this->systemdServicesOutput) {
            $this->systemdServicesOutput = $this->getSystemdServicesList();
        }

        return $this->isBrewServiceRunningGivenServiceList($this->systemdServicesOutput, $name, $exactMatch);
    }

    /**
     * Check if a service is running as user - not applicable in systemd context.
     */
    public function isBrewServiceRunningAsUser(string $name, bool $exactMatch = true): bool
    {
        // In systemd context, services don't run as user
        return false;
    }

    public function jsonFromCli(string $input, bool $sudo = false): array
    {
        $contents = $sudo ? $this->cli->run($input) : $this->cli->runAsUser($input);
        // Skip to the JSON, to avoid warnings; we're only getting arrays so start with [
        $contents = substr($contents, strpos($contents, '['));

        try {
            return json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $command = $sudo ? 'sudo '.$input : $input;
            throw new \Exception('Invalid JSON returned from command: '.$command);
        }
    }



    /**
     * Check if a service is running given a service list.
     */
    protected function isBrewServiceRunningGivenServiceList(array $serviceList, string $name, bool $exactMatch = true): bool
    {
        foreach ($serviceList as $service) {
            if ($service->running === true) {
                if ($exactMatch && $service->name === $name) {
                    return true;
                } elseif (!$exactMatch && str_contains($service->name, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get systemd services list in a format compatible with brew services info.
     */
    protected function getSystemdServicesList(): array
    {
        $output = $this->cli->run('systemctl list-units --type=service --all --no-pager --output=json');
        $services = json_decode($output) ?: [];

        // Transform systemd output to match brew services format
        return array_map(function($service) {
            return (object)[
                'name' => str_replace('.service', '', $service->unit),
                'running' => $service->active === 'active',
                'status' => $service->active,
                'user' => 'root',
                'file' => $service->unit,
                'exit_code' => null,
                'error_log' => null,
            ];
        }, $services);
    }

    public function valetInstalled(): bool
    {
        return is_dir(VALET_HOME_PATH)
            && file_exists($this->config->path())
            && is_dir(VALET_HOME_PATH.'/Drivers')
            && is_dir(VALET_HOME_PATH.'/Sites')
            && is_dir(VALET_HOME_PATH.'/Log')
            && is_dir(VALET_HOME_PATH.'/Certificates');
    }

    public function debugInstructions(): string
    {
        return collect($this->debugInstructions)->unique()->join(PHP_EOL);
    }
}
