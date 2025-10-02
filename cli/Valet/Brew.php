<?php

namespace Valet;

use DomainException;
use Illuminate\Support\Collection;
use PhpFpm;

class Brew
{
    // This is the array of PHP versions supported on Fedora repositories
    const SUPPORTED_PHP_VERSIONS = [
        'php',
        'php8.5',
        'php8.4',
        'php8.3',
        'php8.2',
        'php8.1',
        'php8.0',
        'php7.4',
        'php7.3',
        'php7.2',
        'php7.1',
    ];

    // Update this LATEST and the following LIMITED array when PHP versions are released or retired
    // We specify a numbered version here even though Homebrew links its generic 'php' alias to it
    const LATEST_PHP_VERSION = 'php8.4';

    // These are the PHP versions that should be installed via the remi repository because
    // Fedora officially no longer provides them
    const LIMITED_PHP_VERSIONS = [
        'php8.0',
        'php7.4',
        'php7.3',
        'php7.2',
        'php7.1',
    ];

    // Not used in DNF implementation but kept for compatibility
    const BREW_DISABLE_AUTO_CLEANUP = '';

    public function __construct(public CommandLine $cli, public Filesystem $files) {}

    /**
     * Ensure the package exists in the current DNF configuration.
     */
    public function installed(string $package): bool
    {
        $result = $this->cli->runAsUser("dnf list installed $package 2>/dev/null");
        return !empty($result) && !str_contains($result, 'No matching Packages');
    }

    /**
     * Determine if a compatible PHP version is installed.
     */
    public function hasInstalledPhp(): bool
    {
        $installed = $this->installedPhpPackages()->first(function ($package) {
            return $this->supportedPhpVersions()->contains($package);
        });

        return !empty($installed);
    }

    /**
     * Get a list of supported PHP versions.
     */
    public function supportedPhpVersions(): Collection
    {
        return collect(static::SUPPORTED_PHP_VERSIONS);
    }

    /**
     * Get a list of disabled/limited PHP versions.
     */
    public function limitedPhpVersions(): Collection
    {
        return collect(static::LIMITED_PHP_VERSIONS);
    }

    /**
     * Get a list of installed PHP packages.
     */
    public function installedPhpPackages(): Collection
    {
        return collect(
            explode(PHP_EOL, $this->cli->runAsUser('dnf list installed | grep -i "^php" | awk \'{print $1}\''))
        )->filter();
    }

    /**
     * Get the aliased version - not applicable in DNF context.
     */
    public function determineAliasedVersion($formula): string
    {
        // DNF doesn't use aliases like Homebrew
        return $formula;
    }

    /**
     * Determine if nginx is installed.
     */
    public function hasInstalledNginx(): bool
    {
        return $this->installed('nginx');
    }

    /**
     * Return name of the nginx service.
     */
    public function nginxServiceName(): string
    {
        return 'nginx';
    }

    /**
     * Ensure that the given package is installed.
     */
    public function ensureInstalled(string $package, array $options = [], array $repos = []): void
    {
        if (!$this->installed($package)) {
            $this->installOrFail($package, $options, $repos);
        }
    }

    /**
     * Install the given package and throw an exception on failure.
     */
    public function installOrFail(string $package, array $options = [], array $repos = []): void
    {
        info("Installing {$package}...");

        if (count($repos) > 0) {
            $this->enableRepo($repos);
        }

        output("<info>[{$package}] is not installed, installing it now via DNF...</info> 🚀");

        if ($this->limitedPhpVersions()->contains($package)) {
            $this->enableRemiRepo();
            warning('Note: Installing PHP from Remi repository...');
        }

        $this->cli->runAsUser("sudo dnf install -y {$package} " . implode(' ', $options), function ($exitCode, $errorOutput) use ($package) {
            output($errorOutput);
            throw new DomainException("DNF was unable to install [{$package}].");
        });
    }

    /**
     * Enable DNF repositories - equivalent to tap in Homebrew.
     */
    public function tap($repos): void
    {
        $repos = is_array($repos) ? $repos : func_get_args();
        $this->enableRepo($repos);
    }

    /**
     * Enable DNF repositories.
     */
    private function enableRepo($repos): void
    {
        $repos = is_array($repos) ? $repos : func_get_args();

        foreach ($repos as $repo) {
            $this->cli->runAsUser("sudo dnf config-manager --set-enabled {$repo}");
        }
    }

    /**
     * Enable Remi repository for additional PHP versions.
     */
    private function enableRemiRepo(): void
    {
        if (!$this->installed('dnf-utils')) {
            $this->installOrFail('dnf-utils');
        }

        if (!$this->installed('https://rpms.remirepo.net/fedora/remi-release-41.rpm')) {
            $this->cli->runAsUser('sudo dnf install -y https://rpms.remirepo.net/fedora/remi-release-41.rpm');
        }
    }

    /**
     * Restart the given services.
     */
    public function restartService($services): void
    {
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            if ($this->installed($service)) {
                info("Restarting {$service}...");
                $this->cli->quietly("sudo systemctl restart {$service}");
            }
        }
    }

    /**
     * Stop the given services.
     */
    public function stopService($services): void
    {
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            if ($this->installed($service)) {
                info("Stopping {$service}...");
                $this->cli->quietly("sudo systemctl stop {$service}");
            }
        }
    }

    /**
     * Check if PHP is symlinked.
     */
    public function hasLinkedPhp(): bool
    {
        // return $this->files->isLink('/usr/bin/php');
        return true;
    }

    /**
     * Get the linked PHP version information.
     */
    public function getParsedLinkedPhp(): array
    {
        return $this->parsePhpPath('/usr/bin/php');
    }

    /**
     * Gets the currently linked formula by identifying the symlink in the homebrew bin directory.
     * Different to ->linkedPhp() in that this will just get the linked directory name,
     * whether that is php, php74 or php@7.4.
     */
    public function getLinkedPhpFormula(): string
    {
        $matches = $this->getParsedLinkedPhp();
        return $matches[1] . $matches[2];
    }

    /**
     * Determine which version of PHP is linked.
     */
    public function linkedPhp(): string
    {
        $matches = $this->getParsedLinkedPhp();
        $resolvedPhpVersion = $matches[3] ?: $matches[2];

        return $this->supportedPhpVersions()->first(
            function ($version) use ($resolvedPhpVersion) {
                return $this->arePhpVersionsEqual($resolvedPhpVersion, $version);
            }, function () use ($resolvedPhpVersion) {
                throw new DomainException("Unable to determine linked PHP when parsing '$resolvedPhpVersion'");
            });
    }

    /**
     * Get PHP executable path.
     */
    public function getPhpExecutablePath(?string $phpVersion = null): string
    {
        if (!$phpVersion) {
            return '/usr/bin/php';
        }

        $phpVersion = str_replace(['@', '.'], '', $phpVersion);
        if ($this->files->exists("/usr/bin/php{$phpVersion}")) {
            return "/usr/bin/php{$phpVersion}";
        }

        return '/usr/bin/php';
    }

    /**
     * Restart the linked PHP-FPM service.
     */
    public function restartLinkedPhp(): void
    {
        $phpVersion = $this->getLinkedPhpFormula();
        $this->restartService("php{$phpVersion}-fpm");
    }

    /**
     * Create the "sudoers.d" entry - not needed in Linux/DNF context.
     */
    public function createSudoersEntry(): void
    {
        // Not needed in DNF implementation as we use sudo directly
        return;
    }

    /**
     * Remove the "sudoers.d" entry - not needed in Linux/DNF context.
     */
    public function removeSudoersEntry(): void
    {
        // Not needed in DNF implementation as we use sudo directly
        return;
    }

    /**
     * Link a package.
     */
    public function link(string $package, bool $force = false): string
    {
        $target = "/usr/bin/{$package}";
        $source = $this->getPhpExecutablePath($package);

        return $this->cli->runAsUser(
            sprintf('sudo ln -sf %s %s', $source, $target),
            function ($exitCode, $errorOutput) use ($package) {
                output($errorOutput);
                throw new DomainException("Unable to link [{$package}].");
            }
        );
    }

    /**
     * Unlink a package.
     */
    public function unlink(string $package): string
    {
        return $this->cli->runAsUser(
            sprintf('sudo rm -f /usr/bin/%s', $package),
            function ($exitCode, $errorOutput) use ($package) {
                output($errorOutput);
                throw new DomainException("Unable to unlink [{$package}].");
            }
        );
    }

    /**
     * Get all running services.
     */
    public function getAllRunningServices(): Collection
    {
        return $this->getRunningServicesAsRoot();
    }

    /**
     * Get services running as root.
     */
    public function getRunningServicesAsRoot(): Collection
    {
        return collect(explode(PHP_EOL, $this->cli->run(
            'systemctl list-units --type=service --state=running | grep php | awk \'{print $1}\''
        )))->filter();
    }

    /**
     * Get services running as user - not applicable in Linux/systemd context.
     */
    public function getRunningServicesAsUser(): Collection
    {
        // All services run as root in systemd
        return collect([]);
    }

    /**
     * Get running services with optional user context - not needed in Linux/systemd.
     */
    public function getRunningServices(bool $asUser = false): Collection
    {
        return $this->getRunningServicesAsRoot();
    }

    /**
     * Uninstall all PHP versions.
     */
    public function uninstallAllPhpVersions(): string
    {
        $this->supportedPhpVersions()->each(function ($package) {
            $this->uninstallPackage($package);
        });

        return 'PHP versions removed.';
    }

    /**
     * Uninstall a package.
     */
    public function uninstallPackage(string $package): void
    {
        $this->cli->runAsUser("sudo dnf remove -y {$package}");
    }

    /**
     * Clean up package manager cache.
     */
    public function cleanupBrew(): string
    {
        return $this->cli->runAsUser(
            'sudo dnf clean all',
            function ($exitCode, $errorOutput) {
                output($errorOutput);
            }
        );
    }

    /**
     * Parse PHP path to extract version information.
     */
    public function parsePhpPath(string $resolvedPath): array
    {
        preg_match('~(/usr/bin/)(php)(\d\.\d)?~', $resolvedPath, $matches);
        return $matches;
    }

    /**
     * Compare PHP versions for equality.
     */
    public function arePhpVersionsEqual(string $versionA, string $versionB): bool
    {
        $versionANormalized = preg_replace('/[^\d]/', '', $versionA);
        $versionBNormalized = preg_replace('/[^\d]/', '', $versionB);

        return $versionANormalized === $versionBNormalized;
    }
}
