<?php
declare(strict_types=1);

namespace BeehiivSync;

final class Deactivator {

	public static function deactivate(): void {
		// Recurring Action Scheduler jobs will be unscheduled here once
		// the Schedule subsystem lands. Capabilities and options are
		// intentionally retained across deactivation; uninstall.php
		// handles full teardown.
	}
}
