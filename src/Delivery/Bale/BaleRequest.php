<?php
/**
 * Normalized Bale outbound message request.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Bale;

use InvalidArgumentException;

/**
 * Holds an already-resolved Bale target and text.
 */
final class BaleRequest {

	/**
	 * Already-resolved chat identifier or channel username.
	 *
	 * @var int|string
	 */
	private int|string $chat_id;

	/**
	 * Message text.
	 *
	 * @var string
	 */
	private string $text;

	/**
	 * Create a normalized request.
	 *
	 * @param int|string $chat_id Already-resolved target.
	 * @param string     $text    Message text.
	 */
	public function __construct( int|string $chat_id, string $text ) {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

		if ( '' === (string) $chat_id || 1 > $length || 4096 < $length ) {
			throw new InvalidArgumentException( 'Bale request requires a resolved target and 1-4096 characters of text.' );
		}

		$this->chat_id = $chat_id;
		$this->text    = $text;
	}

	/**
	 * Already-resolved target.
	 *
	 * @return int|string
	 */
	public function chat_id(): int|string {
		return $this->chat_id;
	}

	/**
	 * Message text.
	 *
	 * @return string
	 */
	public function text(): string {
		return $this->text;
	}
}
