<?php
/**
 * Truthful Bale recipient-mode availability for admin presentation.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

/**
 * Describes which Bale recipient modes GNM actually supports today.
 *
 * This is presentation truth only. It owns no transport, no credentials and no
 * action: the deferred phone-number capability exists here purely so the admin
 * UI can state its real availability instead of implying a capability GNM does
 * not have.
 */
final class BaleDeliveryMode {

	public const CHAT_ID      = 'chat_id';
	public const PHONE_NUMBER = 'phone_number';

	/**
	 * Return every Bale recipient mode with its current real availability.
	 *
	 * @return array<int, array{id:string,label:string,available:bool,state:string,status_label:string,detail:string}>
	 */
	public static function modes(): array {
		return array(
			array(
				'id'           => self::CHAT_ID,
				'label'        => __( 'Bale delivery by chat ID or username', 'gravity-notification-manager' ),
				'available'    => true,
				'state'        => PointStatus::CONFIGURED,
				'status_label' => __( 'Available', 'gravity-notification-manager' ),
				'detail'       => __( 'Active mode. GNM delivers Bale messages through the Bale Bot API to a numeric chat ID or an @username destination.', 'gravity-notification-manager' ),
			),
			array(
				'id'           => self::PHONE_NUMBER,
				'label'        => __( 'Bale delivery by phone number', 'gravity-notification-manager' ),
				'available'    => false,
				'state'        => PointStatus::DISABLED,
				'status_label' => __( 'In development — currently unavailable', 'gravity-notification-manager' ),
				'detail'       => __( 'Deferred capability, outside the current release scope. It has no destination field, no credential field and no save, test or connect action, and GNM makes no external request for it.', 'gravity-notification-manager' ),
			),
		);
	}
}
