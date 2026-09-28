<?php
/**
 * MAC admin menu icon.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class MenuIcon
{
	/**
	 * The MAC logomark, the same one MAC Core uses. It is kept here rather than as an asset file, so the
	 * release ZIP keeps shipping only PHP, CSS and JS.
	 */
	private const SVG = '<svg width="128" height="128" viewBox="0 0 128 128" fill="none" xmlns="http://www.w3.org/2000/svg">'
		. '<path d="M52.3955 119.623C52.1118 119.779 51.7997 119.864 51.4875 119.865H51.4573C51.7701 119.864 52.083 119.779 52.3674 119.623L63.7263 113.056L52.3955 119.623Z" fill="currentColor"/>'
		. '<path d="M75.9058 119.552C76.2181 119.764 76.5736 119.864 76.9434 119.865H76.9121C76.5432 119.864 76.1885 119.764 75.877 119.552L64.2002 112.782L75.9058 119.552Z" fill="currentColor"/>'
		. '<path d="M119.269 116.195H77.427L67.8555 110.677L70.0029 109.426L88.6909 98.645L119.269 116.195Z" fill="currentColor"/>'
		. '<path d="M74.8101 32.0566L38.3586 95.189L6.78516 113.123L64.1719 13.6104L74.8101 32.0566Z" fill="currentColor"/>'
		. '<path d="M121.217 113.081L90.0137 95.1748L66.3052 54.1011L76.915 35.7258L121.217 113.081Z" fill="currentColor"/>'
		. '<path d="M64.1716 8.13501C64.1077 8.13502 64.0429 8.13838 63.9783 8.14526C64.0428 8.13837 64.1075 8.13477 64.1714 8.13477L64.1716 8.13501Z" fill="currentColor"/>'
		. '</svg>';

	/**
	 * The icon as a data URI, the form add_menu_page() accepts.
	 */
	public static function url(): string
	{
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress admin menu icons accept base64-encoded SVG data URIs.
		return 'data:image/svg+xml;base64,' . \base64_encode( self::SVG );
	}
}
