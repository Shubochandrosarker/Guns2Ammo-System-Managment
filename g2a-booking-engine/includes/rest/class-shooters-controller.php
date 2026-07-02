<?php
/**
 * REST: Shooters CRM.
 *
 * Powers the live search / filter / pagination on the Shooters admin screen and
 * the analytics header. Everything is read-only and staff-gated.
 *
 *   GET /shooters           List shooters (search, segment, sort, page).
 *   GET /shooters/stats     Headline KPIs, 12-month growth, segment mix.
 *   GET /shooters/detail    One shooter's profile + booking history (?email=).
 *
 * @package G2AB
 * @since   1.9.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_REST_Shooters_Controller {

	public function register_routes() {
		register_rest_route( G2AB_REST_NAMESPACE, '/shooters', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'list_shooters' ),
			'permission_callback' => array( $this, 'perm_staff' ),
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/shooters/stats', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'stats' ),
			'permission_callback' => array( $this, 'perm_staff' ),
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/shooters/detail', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'detail' ),
			'permission_callback' => array( $this, 'perm_staff' ),
		) );
	}

	public function perm_staff( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'g2ab_invalid_nonce', __( 'Invalid or missing nonce.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			return new WP_Error( 'g2ab_forbidden', __( 'Staff only.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function list_shooters( WP_REST_Request $request ) {
		$result = G2AB_Analytics::shooters_query( array(
			'search'   => (string) $request->get_param( 'search' ),
			'segment'  => (string) ( $request->get_param( 'segment' ) ?: 'all' ),
			'sort'     => (string) ( $request->get_param( 'sort' ) ?: 'last_seen' ),
			'order'    => (string) ( $request->get_param( 'order' ) ?: 'desc' ),
			'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
			'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 12 ),
		) );

		$rows = array();
		foreach ( $result['rows'] as $r ) {
			$rows[] = $this->shape( $r );
		}
		return rest_ensure_response( array(
			'rows'     => $rows,
			'total'    => $result['total'],
			'page'     => $result['page'],
			'pages'    => $result['pages'],
			'per_page' => $result['per_page'],
		) );
	}

	public function stats( WP_REST_Request $request ) {
		$s = G2AB_Analytics::shooters_stats();
		return rest_ensure_response( $s );
	}

	public function detail( WP_REST_Request $request ) {
		$email = (string) $request->get_param( 'email' );
		$d     = G2AB_Analytics::shooter_detail( $email );
		if ( null === $d ) {
			return new WP_Error( 'g2ab_not_found', __( 'Shooter not found.', 'g2a-booking' ), array( 'status' => 404 ) );
		}
		$shape            = $this->shape( $d );
		$shape['history'] = array();
		foreach ( $d['history'] as $h ) {
			$cat   = isset( $h['event_category'] ) ? $h['event_category'] : '';
			$kind  = ! $h['event_id'] ? 'Lane / Range' : ( 'ccw-class' === $cat ? 'Class' : 'Event' );
			$shape['history'][] = array(
				'id'      => (int) $h['id'],
				'when'    => $h['start_at'] ? G2AB_Events::format_local( $h['start_at'], 'M j, Y · g:i A' ) : '',
				'label'   => $h['event_title'] ? $h['event_title'] : $kind,
				'kind'    => $kind,
				'status'  => $h['status'],
				'amount'  => (float) $h['paid_amount'],
				'party'   => (int) $h['party_size'],
				'edit'    => admin_url( 'admin.php?page=g2ab-bookings-list&view=detail&booking_id=' . (int) $h['id'] ),
			);
		}
		return rest_ensure_response( $shape );
	}

	/** Normalise an analytics shooter row for JSON output. */
	private function shape( $r ) {
		$meta  = G2AB_Analytics::segment_meta( $r['segment'] );
		$login = '';
		if ( $r['user_id'] ) {
			$u = get_userdata( $r['user_id'] );
			if ( $u ) {
				$login = $u->user_login;
			}
		}
		return array(
			'email'        => $r['email'],
			'name'         => $r['name'],
			'phone'        => $r['phone'],
			'initials'     => $this->initials( $r['name'] ),
			'user_id'      => $r['user_id'],
			'wp_login'     => $login,
			'bookings'     => $r['bookings'],
			'event_bookings' => $r['event_bookings'],
			'lost'         => $r['lost_bookings'],
			'spent'        => round( $r['spent'], 2 ),
			'avg_spend'    => round( $r['avg_spend'], 2 ),
			'first_seen'   => $r['first_seen'] ? G2AB_Events::format_local( $r['first_seen'], 'M j, Y' ) : '—',
			'last_seen'    => $r['last_seen'] ? G2AB_Events::format_local( $r['last_seen'], 'M j, Y' ) : '—',
			'days_since'   => $r['days_since'],
			'segment'      => $r['segment'],
			'segment_label'=> $meta['label'],
			'segment_color'=> $meta['color'],
		);
	}

	private function initials( $name ) {
		$name  = trim( (string) $name );
		if ( '' === $name ) {
			return '?';
		}
		$parts = preg_split( '/\s+/', $name );
		$first = mb_substr( $parts[0], 0, 1 );
		$last  = count( $parts ) > 1 ? mb_substr( end( $parts ), 0, 1 ) : '';
		return mb_strtoupper( $first . $last );
	}
}
