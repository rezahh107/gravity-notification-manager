<?php
/**
 * Tests for the bounded WU-03 recipient resolver.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Recipient;

use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\Recipient\EntryFieldReader;
use GravityNotify\Recipient\FlowAssigneeReader;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Recipient\UserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic no-network recipient-resolution tests.
 */
final class RecipientResolverTest extends TestCase {

	/**
	 * Fixed SMS and Bale targets stay channel-local and unchanged.
	 *
	 * @return void
	 */
	public function test_fixed_targets_for_both_channels(): void {
		$resolver = $this->resolver();

		$sms = $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_FIXED, 'sms-target-alpha', FeedRuleSchema::CHANNEL_SMS ) );
		$this->assertSame( array( 'sms-target-alpha' ), $sms->destinations() );
		$this->assertSame( array(), $sms->skips() );

		$bale = $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_FIXED, 'bale-target-alpha', FeedRuleSchema::CHANNEL_BALE ) );
		$this->assertSame( array( 'bale-target-alpha' ), $bale->destinations() );
	}

	/**
	 * Entry-field sources accept only field/input IDs and scalar non-empty values.
	 *
	 * @return void
	 */
	public function test_entry_field_resolution_and_invalid_values(): void {
		$fields = new FakeEntryFieldReader(
			array(
				'7'   => 'entry-target-alpha',
				'8.2' => 'entry-target-beta',
				'9'   => array( 'not-scalar' ),
				'10'  => '',
			)
		);
		$resolver = $this->resolver( $fields );

		$this->assertSame( array( 'entry-target-alpha' ), $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_ENTRY_FIELD, '7' ) )->destinations() );
		$this->assertSame( array( 'entry-target-beta' ), $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_ENTRY_FIELD, '8.2' ) )->destinations() );
		$this->assertSame( 'invalid_entry_field_selector', $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_ENTRY_FIELD, 'field-name' ) )->skips()[0]['reason'] );
		$this->assertSame( 'non_scalar_entry_field_value', $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_ENTRY_FIELD, '9' ) )->skips()[0]['reason'] );
		$this->assertSame( 'missing_destination', $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_ENTRY_FIELD, '10' ) )->skips()[0]['reason'] );
	}

	/**
	 * User sources read only the channel-specific closed contact meta.
	 *
	 * @return void
	 */
	public function test_user_channel_specific_contact_lookup(): void {
		$users = new FakeUserDirectory(
			array( 'operator' => 11 ),
			array(),
			array(
				11 => array(
					RecipientResolver::SMS_META_KEY  => 'sms-contact-user-11',
					RecipientResolver::BALE_META_KEY => 'bale-contact-user-11',
					'plato_user_mobile'               => 'forbidden-legacy-contact',
				),
			)
		);
		$resolver = $this->resolver( null, $users );

		$this->assertSame( array( 'sms-contact-user-11' ), $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_USER, 'operator', FeedRuleSchema::CHANNEL_SMS ) )->destinations() );
		$this->assertSame( array( 'bale-contact-user-11' ), $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_USER, 'operator', FeedRuleSchema::CHANNEL_BALE ) )->destinations() );
		$this->assertNotContains( 'forbidden-legacy-contact', $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_USER, 'operator' ) )->destinations() );
	}

	/**
	 * Missing users and contacts are structured skips, not exceptions.
	 *
	 * @return void
	 */
	public function test_missing_user_and_missing_contact_are_nonfatal_skips(): void {
		$users = new FakeUserDirectory( array( 'known' => 12 ), array(), array( 12 => array() ) );
		$resolver = $this->resolver( null, $users );

		$this->assertSame( 'user_not_found', $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_USER, 'unknown' ) )->skips()[0]['reason'] );
		$this->assertSame( 'missing_contact', $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_USER, 'known' ) )->skips()[0]['reason'] );
	}

	/**
	 * Role resolution is deterministic and keeps valid contacts when peers are missing.
	 *
	 * @return void
	 */
	public function test_role_multiplicity_and_partial_missing_contacts(): void {
		$users = new FakeUserDirectory(
			array(),
			array( 'reviewer' => array( 33, 31, 32, 31 ) ),
			array(
				31 => array( RecipientResolver::SMS_META_KEY => 'sms-role-31' ),
				32 => array(),
				33 => array( RecipientResolver::SMS_META_KEY => 'sms-role-33' ),
			)
		);
		$result = $this->resolver( null, $users )->resolve( $this->rule( FeedRuleSchema::RECIPIENT_ROLE, 'reviewer' ) );

		$this->assertSame( array( 'sms-role-31', 'sms-role-33' ), $result->destinations() );
		$this->assertSame( array( array( 'subject' => 'role_member:32', 'reason' => 'missing_contact' ) ), $result->skips() );
	}

	/**
	 * Flow user, role, and email assignees map back to the same WordPress meta contract.
	 *
	 * @return void
	 */
	public function test_flow_assignee_multiplicity_and_supported_identity_forms(): void {
		$users = new FakeUserDirectory(
			array( 'flow-user@example.test' => 44 ),
			array( 'approver' => array( 43, 42 ) ),
			array(
				41 => array( RecipientResolver::BALE_META_KEY => 'bale-flow-41' ),
				42 => array( RecipientResolver::BALE_META_KEY => 'bale-flow-42' ),
				43 => array(),
				44 => array( RecipientResolver::BALE_META_KEY => 'bale-flow-44' ),
			)
		);
		$flow = new FakeFlowAssigneeReader(
			array(
				'available' => true,
				'reason'    => '',
				'assignees' => array(
					array( 'type' => 'user_id', 'id' => '41' ),
					array( 'type' => 'role', 'id' => 'approver' ),
					array( 'type' => 'email', 'id' => 'flow-user@example.test' ),
					array( 'type' => 'token', 'id' => 'opaque' ),
				),
			)
		);
		$result = $this->resolver( null, $users, $flow )->resolve( $this->rule( FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE, '', FeedRuleSchema::CHANNEL_BALE ) );

		$this->assertSame( array( 'bale-flow-41', 'bale-flow-42', 'bale-flow-44' ), $result->destinations() );
		$this->assertSame( 'missing_contact', $result->skips()[0]['reason'] );
		$this->assertSame( 'unsupported_assignee_type', $result->skips()[1]['reason'] );
	}

	/**
	 * Missing or unsupported flow context stays a bounded unresolved outcome.
	 *
	 * @return void
	 */
	public function test_unavailable_flow_context_is_a_safe_skip(): void {
		$flow = new FakeFlowAssigneeReader(
			array(
				'available' => false,
				'reason'    => 'flow_step_unavailable',
				'assignees' => array(),
			)
		);
		$result = $this->resolver( null, null, $flow )->resolve( $this->rule( FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE, '' ) );

		$this->assertSame( array(), $result->destinations() );
		$this->assertSame( 'flow_step_unavailable', $result->skips()[0]['reason'] );
	}

	/**
	 * Empty/invalid configured values do not become destinations.
	 *
	 * @return void
	 */
	public function test_invalid_or_empty_configured_values(): void {
		$resolver = $this->resolver();

		$this->assertSame( 'missing_destination', $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_FIXED, '  ' ) )->skips()[0]['reason'] );
		$this->assertSame( 'invalid_user_selector', $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_USER, '' ) )->skips()[0]['reason'] );
		$this->assertSame( 'invalid_role', $resolver->resolve( $this->rule( FeedRuleSchema::RECIPIENT_ROLE, '' ) )->skips()[0]['reason'] );
	}

	/**
	 * The WU-03 harness explicitly blocks external HTTP and performs no provider calls.
	 *
	 * @return void
	 */
	public function test_no_provider_or_network_io_guard_is_active(): void {
		$this->assertTrue( defined( 'GRAVITY_NOTIFY_TEST_NO_SEND' ) && GRAVITY_NOTIFY_TEST_NO_SEND );
		$this->assertTrue( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL );
		$this->assertSame( array( 'synthetic-target' ), $this->resolver()->resolve( $this->rule( FeedRuleSchema::RECIPIENT_FIXED, 'synthetic-target' ) )->destinations() );
	}

	/**
	 * Build normalized WU-01-style recipient metadata.
	 *
	 * @param string $source_type Recipient source type.
	 * @param string $source      Source value.
	 * @param string $channel     Channel.
	 * @return array<string, string>
	 */
	private function rule( string $source_type, string $source, string $channel = FeedRuleSchema::CHANNEL_SMS ): array {
		return array(
			'recipient_source_type'  => $source_type,
			'recipient_source_value' => $source,
			'channel'                => $channel,
		);
	}

	/**
	 * Build the resolver with deterministic fakes.
	 *
	 * @param EntryFieldReader|null   $fields Entry-field seam.
	 * @param UserDirectory|null      $users  User/contact seam.
	 * @param FlowAssigneeReader|null $flow   Flow assignee seam.
	 * @return RecipientResolver
	 */
	private function resolver( ?EntryFieldReader $fields = null, ?UserDirectory $users = null, ?FlowAssigneeReader $flow = null ): RecipientResolver {
		return new RecipientResolver(
			$fields ?? new FakeEntryFieldReader( array() ),
			$users ?? new FakeUserDirectory( array(), array(), array() ),
			$flow ?? new FakeFlowAssigneeReader( array( 'available' => true, 'reason' => '', 'assignees' => array() ) )
		);
	}
}

/**
 * Deterministic Entry field fake.
 */
final class FakeEntryFieldReader implements EntryFieldReader {

	/**
	 * Selector-to-value map.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $values Selector map.
	 */
	public function __construct( array $values ) {
		$this->values = $values;
	}

	/**
	 * Read one fake Entry field value.
	 *
	 * @param string $selector Configured field/input selector.
	 * @param array  $entry    Ignored Entry object.
	 * @param array  $form     Ignored Form object.
	 * @return mixed
	 */
	public function read( string $selector, array $entry, array $form ) {
		unset( $entry, $form );
		return $this->values[ $selector ] ?? null;
	}
}

/**
 * Deterministic WordPress user/contact fake.
 */
final class FakeUserDirectory implements UserDirectory {

	/**
	 * User selector map.
	 *
	 * @var array<string, int>
	 */
	private array $selectors;

	/**
	 * Role membership map.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $roles;

	/**
	 * Contact meta map.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $contacts;

	/**
	 * Constructor.
	 *
	 * @param array<string, int>               $selectors User selector map.
	 * @param array<string, array<int, int>>   $roles     Role membership map.
	 * @param array<int, array<string, mixed>> $contacts  Contact meta map.
	 */
	public function __construct( array $selectors, array $roles, array $contacts ) {
		$this->selectors = $selectors;
		$this->roles      = $roles;
		$this->contacts   = $contacts;
	}

	/**
	 * Resolve one fake user selector.
	 *
	 * @param string $selector User selector.
	 * @return int|null
	 */
	public function find_user_id( string $selector ): ?int {
		return $this->selectors[ $selector ] ?? null;
	}

	/**
	 * Resolve fake role members.
	 *
	 * @param string $role Role slug.
	 * @return array<int, int>
	 */
	public function find_user_ids_by_role( string $role ): array {
		return $this->roles[ $role ] ?? array();
	}

	/**
	 * Read one fake contact meta value.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Contact meta key.
	 * @return mixed
	 */
	public function get_contact( int $user_id, string $meta_key ) {
		return $this->contacts[ $user_id ][ $meta_key ] ?? null;
	}
}

/**
 * Deterministic Gravity Flow assignee fake.
 */
final class FakeFlowAssigneeReader implements FlowAssigneeReader {

	/**
	 * Assignee collection.
	 *
	 * @var array<string, mixed>
	 */
	private array $collection;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $collection Assignee collection.
	 */
	public function __construct( array $collection ) {
		$this->collection = $collection;
	}

	/**
	 * Return the configured fake assignee collection.
	 *
	 * @param array $entry Ignored Entry object.
	 * @param array $form  Ignored Form object.
	 * @return array<string, mixed>
	 */
	public function read( array $entry, array $form ): array {
		unset( $entry, $form );
		return $this->collection;
	}
}
