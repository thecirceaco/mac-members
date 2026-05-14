<?php
/**
 * Service contract.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Contracts;

interface Service
{
	public function register(): void;
}
