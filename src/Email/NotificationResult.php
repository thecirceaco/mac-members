<?php
/**
 * Notification delivery result.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class NotificationResult
{
	public function __construct(
		private readonly int $sent_count = 0,
		private readonly int $failed_count = 0
	) {}

	public function has_failures(): bool
	{
		return 0 < $this->failed_count;
	}

	public function get_failed_count(): int
	{
		return $this->failed_count;
	}

	public function get_sent_count(): int
	{
		return $this->sent_count;
	}

	public function get_warning_message(): string
	{
		return __( 'One or more notification emails could not be sent.', 'mac-members' );
	}
}
