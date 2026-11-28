<?php

declare(strict_types=1);

namespace PaymosBlesta;

/**
 * Blesta staff permissions of the companion plugin (paymos.admin_connect / paymos.admin_refunds).
 *
 * Releases before them let every staff member open Connect and Refunds. Blesta adds the
 * permissions on upgrade but grants them to nobody, so the plugin grants them to every staff
 * group of the company once (nobody loses access; admins narrow it in Staff > Groups) and only
 * then lets the controllers enforce them. A fresh install is granted by Blesta itself.
 */
final class StaffPermissions
{
    public const PLUGIN_DIR = 'paymos';
    public const ALIASES = array('paymos.admin_connect', 'paymos.admin_refunds');
    private const GRANTED_SETTING = 'staff_permissions_granted_company_';

    public static function isGranted(CredentialStore $settings): bool
    {
        return $settings->getSetting(self::GRANTED_SETTING . self::companyId()) === '1';
    }

    public static function markGranted(CredentialStore $settings): void
    {
        $settings->putSetting(self::GRANTED_SETTING . self::companyId(), '1');
    }

    /**
     * Grant the plugin permissions to every staff group of the company, once.
     * Returns true when they are granted (now or earlier); false leaves the marker unset so
     * the next admin request tries again.
     */
    public static function grantToAllStaffGroups(CredentialStore $settings, ?int $pluginId = null): bool
    {
        if (self::isGranted($settings)) {
            return true;
        }
        if (!class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
            return false;
        }

        $host = new \stdClass();
        \Loader::loadModels($host, array('PluginManager', 'Permissions', 'StaffGroups'));
        if (!isset($host->Permissions, $host->StaffGroups)
            || !method_exists($host->Permissions, 'getByAlias')
            || !method_exists($host->StaffGroups, 'getAll')
            || !method_exists($host->StaffGroups, 'grantPermission')) {
            return false;
        }

        if ($pluginId === null || $pluginId <= 0) {
            $pluginId = self::installedPluginId($host);
        }
        if ($pluginId === null || $pluginId <= 0) {
            return false;
        }

        // grantPermission() grants only permissions Blesta has already stored for the plugin.
        foreach (self::ALIASES as $alias) {
            if (!$host->Permissions->getByAlias($alias, $pluginId, '*')) {
                return false;
            }
        }

        $groups = $host->StaffGroups->getAll(self::companyId());
        if (!is_array($groups) || count($groups) === 0) {
            return false;
        }
        foreach ($groups as $group) {
            if (is_object($group) && isset($group->id)) {
                $host->StaffGroups->grantPermission((int) $group->id, $pluginId);
            }
        }
        if (method_exists($host->StaffGroups, 'errors') && !empty($host->StaffGroups->errors())) {
            return false;
        }

        self::markGranted($settings);
        return true;
    }

    /**
     * Whether a staff member's group holds $alias (Blesta Permissions::authorized()).
     */
    public static function staffMayUse(int $staffId, string $alias): bool
    {
        if ($staffId <= 0 || !class_exists('Loader') || !method_exists('Loader', 'loadModels')) {
            return false;
        }

        $host = new \stdClass();
        \Loader::loadModels($host, array('StaffGroups', 'Permissions'));
        if (!isset($host->StaffGroups, $host->Permissions)
            || !method_exists($host->StaffGroups, 'getStaffGroupByStaff')
            || !method_exists($host->Permissions, 'authorized')) {
            return false;
        }

        $group = $host->StaffGroups->getStaffGroupByStaff($staffId, self::companyId());
        if (!is_object($group) || !isset($group->id)) {
            return false;
        }

        return (bool) $host->Permissions->authorized('staff_group_' . (int) $group->id, $alias, '*', 'staff', self::companyId());
    }

    public static function companyId(): int
    {
        return class_exists('Configure') && method_exists('Configure', 'get')
            ? (int) (\Configure::get('Blesta.company_id') ?: 1)
            : 1;
    }

    /**
     * @param object $host
     */
    private static function installedPluginId($host): ?int
    {
        if (!isset($host->PluginManager) || !method_exists($host->PluginManager, 'getByDir')) {
            return null;
        }
        $plugins = $host->PluginManager->getByDir(self::PLUGIN_DIR, self::companyId());
        return is_array($plugins) && isset($plugins[0]->id) ? (int) $plugins[0]->id : null;
    }
}
