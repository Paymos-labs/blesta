<?php

declare(strict_types=1);

namespace PaymosBlesta;

final class Translation
{
    public const LOCALES = array('en_us', 'ru_ru', 'de_de', 'es_es', 'tr_tr', 'zh_cn');

    /** @var array<string, string> */
    private static $aliases = array(
        'en' => 'en_us', 'en-us' => 'en_us', 'en-gb' => 'en_us', 'english' => 'en_us',
        'ru' => 'ru_ru', 'ru-ru' => 'ru_ru', 'russian' => 'ru_ru',
        'de' => 'de_de', 'de-de' => 'de_de', 'german' => 'de_de', 'deutsch' => 'de_de',
        'es' => 'es_es', 'es-es' => 'es_es', 'spanish' => 'es_es', 'espanol' => 'es_es',
        'tr' => 'tr_tr', 'tr-tr' => 'tr_tr', 'turkish' => 'tr_tr',
        'zh' => 'zh_cn', 'zh-cn' => 'zh_cn', 'zh-hans' => 'zh_cn', 'chinese' => 'zh_cn',
    );

    /** @var array<string, string> */
    private static $sdkLocales = array(
        'en_us' => 'en',
        'ru_ru' => 'ru',
        'de_de' => 'de',
        'es_es' => 'es',
        'tr_tr' => 'tr',
        'zh_cn' => 'zh',
    );

    /** @var array<string, array<string, string>> */
    private static $cache = array();

    public static function normalizeLocale(?string $locale): string
    {
        if ($locale === null) {
            return 'en_us';
        }

        $trimmed = strtolower(trim($locale));
        if ($trimmed === '') {
            return 'en_us';
        }

        if (in_array($trimmed, self::LOCALES, true)) {
            return $trimmed;
        }

        $dashed = str_replace('_', '-', $trimmed);
        if (isset(self::$aliases[$dashed])) {
            return self::$aliases[$dashed];
        }

        $prefix = explode('-', $dashed)[0];
        return self::$aliases[$prefix] ?? 'en_us';
    }

    public static function sdkLocale(?string $locale = null): string
    {
        $normalized = self::normalizeLocale($locale ?? self::detectLocale());
        return self::$sdkLocales[$normalized] ?? 'en';
    }

    public static function isEnglish(?string $locale = null): bool
    {
        return self::normalizeLocale($locale ?? self::detectLocale()) === 'en_us';
    }

    public static function detectLocale(?string $explicit = null): string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            return self::normalizeLocale($explicit);
        }

        if (class_exists('Configure') && method_exists('Configure', 'get')) {
            $configured = \Configure::get('Blesta.language');
            if (is_scalar($configured) && trim((string) $configured) !== '') {
                return self::normalizeLocale((string) $configured);
            }
        }

        if (isset($_SESSION['blesta_lang']) && is_scalar($_SESSION['blesta_lang'])
            && trim((string) $_SESSION['blesta_lang']) !== '') {
            return self::normalizeLocale((string) $_SESSION['blesta_lang']);
        }

        return 'en_us';
    }

    public static function text(string $key, ?string $locale = null): string
    {
        $normalized = self::normalizeLocale($locale ?? self::detectLocale());
        $catalog = self::catalog($normalized);

        if (isset($catalog[$key]) && is_string($catalog[$key])) {
            return $catalog[$key];
        }

        $prefixed = strncmp($key, 'Paymos.', 7) === 0 ? $key : 'Paymos.' . $key;
        if (isset($catalog[$prefixed]) && is_string($catalog[$prefixed])) {
            return $catalog[$prefixed];
        }

        $fallback = self::catalog('en_us');
        if (isset($fallback[$key]) && is_string($fallback[$key])) {
            return $fallback[$key];
        }
        if (isset($fallback[$prefixed]) && is_string($fallback[$prefixed])) {
            return $fallback[$prefixed];
        }

        return $key;
    }

    /**
     * @return array<string, string>
     */
    public static function catalog(string $locale): array
    {
        $normalized = self::normalizeLocale($locale);
        if (isset(self::$cache[$normalized])) {
            return self::$cache[$normalized];
        }

        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR
            . $normalized . DIRECTORY_SEPARATOR . 'paymos.php';

        $loaded = array();
        if (is_file($path)) {
            $lang = array();
            include $path;
            if (is_array($lang)) {
                foreach ($lang as $k => $v) {
                    if (is_scalar($v)) {
                        $loaded[(string) $k] = (string) $v;
                    }
                }
            }
        }

        self::$cache[$normalized] = $loaded;
        return $loaded;
    }

    public static function resetCache(): void
    {
        self::$cache = array();
    }
}
