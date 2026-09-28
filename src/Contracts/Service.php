<?php
/**
 * Service contract.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

interface Service
{
	public function register(): void;
}
