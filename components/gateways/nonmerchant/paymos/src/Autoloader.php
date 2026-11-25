<?php

declare(strict_types=1);

namespace PaymosBlesta;

final class Autoloader
{
    /** @var bool */
    private static $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        spl_autoload_register(static function (string $class): void {
            $pluginPrefix = 'PaymosBlesta\\';
            if (strncmp($class, $pluginPrefix, strlen($pluginPrefix)) === 0) {
                $relative = substr($class, strlen($pluginPrefix));
                $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($path)) {
                    require_once $path;
                }
                return;
            }

            $sdkPrefix = 'Paymos\\';
            if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) !== 0) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($sdkPrefix))) . '.php';

            $bundledPath = dirname(__DIR__) . '/vendor/paymos/php-sdk/src/' . $relative;
            if (is_file($bundledPath)) {
                require_once $bundledPath;
                return;
            }

            $envSdkSrc = getenv('PAYMOS_SDK_SRC');
            if (is_string($envSdkSrc) && trim($envSdkSrc) !== '') {
                $envBase = rtrim(trim($envSdkSrc), '/\\');
                foreach (array($envBase . '/' . $relative, $envBase . '/src/' . $relative) as $envPath) {
                    if (is_file($envPath)) {
                        require_once $envPath;
                        return;
                    }
                }
            }

            $monorepoPath = dirname(__DIR__, 6) . '/php-sdk/src/' . $relative;
            if (is_file($monorepoPath)) {
                require_once $monorepoPath;
            }
        });
    }
}
