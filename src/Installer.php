<?php
/**
 * Activation, deactivation and database upgrades.
 *
 * @package WSPS
 */

namespace WSPS;

use WSPS\Log\LogRepository;
use WSPS\Sync\SyncLock;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's database table.
 */
final class Installer {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'wsps_db_version';

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::install();
		// Restore the schedule when the plugin is re-activated with saved settings.
		( new Scheduler( new Settings(), static fn() => null ) )->sync_schedule();
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate(): void {
		Scheduler::clear();
		SyncLock::force_release();
	}

	/**
	 * Runs the installer when the stored database version is outdated,
	 * e.g. after the plugin files were updated without re-activation.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Creates or updates the log table.
	 */
	private static function install(): void {
		LogRepository::create_table();
		// Autoloaded: it is read on every request by maybe_upgrade().
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}
}
