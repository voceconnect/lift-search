<?php
/**
 * Transient Async Events
 * Version 0.1-alpha
 */


/**
 * Server for handling TAE crons, restores previous events when cron is running
 */
class TAE_Server {

	private static $events;

	public function __construct() {
		add_action( 'init', array( $this, 'init' ), 9 );
	}

	public function init() {
		if ( (defined( 'DOING_CRON' ) && DOING_CRON) ||
			(defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON) ) {
			self::$events = array( );
			add_filter( 'cron_schedules', array( __CLASS__, '_filter_cron_schedule' ) );

			$keys = get_option( 'tae_event_keys', array( ) );
			foreach ( $keys as $key ) {
				self::$events[$key] = TAE_Async_Event::Restore( $key );
				add_action( 'tae_event_' . $key, array( $event, 'execute' ) );
			}
		}
	}

	public function _filter_cron_schedule( $schedules ) {
		foreach ( self::$events as $key => $event ) {
			$schedules['tae_schedule_' . $key] = array(
				'interval' => $event->frequency,
				'display' => 'TAE Event ' . $key
			);
		}

		return $schedules;
	}

}

class TAE_Async_Event {

	public $key;
	public $callback;
	public $params;
	public $frequency;
	public $then_events;

	/**
	 * 
	 * @param type $callback
	 * @param type $params
	 * @param type $frequency
	 * @return TAE_Async_Event
	 */
	public static function Schedule( $callback, $params = array( ), $frequency = 300 ) {


		$event_key = substr( md5( serialize( $callback ) . serialize( $params ) ), 0, 30 );
		if ( !($event = self::Restore( $event_key )) ) {
			$event = new TAE_Async_Event( $callback, $params, $frequency );
		}
		return $event;
	}

	/**
	 * Restores an event based on the key
	 * @param string $key
	 * @return TAE_Async_Event|boolean
	 */
	public static function Restore( $key ) {
		if ( $event_data = get_option( 'tae_event_' . $key, false ) ) {
			$event = TAE_Async_Event( $event_data['callback'], $event_data['params'], $event_data['frequency'] );
			$event->then_events = $event_data['then_events'];
			return $event;
		}
		return false;
	}

	public function __construct( $callback, $params, $frequency, $key ) {
		$this->then_events = array( );
		$this->callback = $callback;
		$this->params = $params;
		$this->frequency = $frequency;
		$this->key = $key;
	}

	/**
	 * 
	 * @param type $callback
	 * @param type $params
	 * @param type $reschedule_on_error
	 * @return TAE_Async_Event
	 */
	public function then( $callback, $params, $reschedule_on_error = false ) {
		$key = md5(serialize($callback) . serialize($params));
		$this->then_events[$key] = array(
			'callback' => $callback,
			'params' => $params,
			'reschedule_on_error' => $reschedule_on_error
		);
		
		wp_unschedule_event($timestamp, $hook);
		return $this;
	}

	public function execute() {
		if ( !is_callable( $this->callback ) ) {
			_doing_it_wrong( __FUNCTION__, "TAE Events must be callable before 'init' priority '10' for cron to call them", '0.1' );
			return false;
		}
		if ( true === call_user_func_array( $this->callback, $this->params ) ) {
			$keep_events = array( );
			foreach ( $this->then_events as $then_key => $event ) {
				if ( is_callable( $event['callback'] ) ) {
					$result = call_user_func_array( $event['callback'], $event['params'] );
					if ( $event['reschedule_on_error'] && is_wp_error( $result ) ) {
						$keep_events[$then_key] = $event;
					}
				} else {
					_doing_it_wrong( __FUNCTION__, "TAE Events must be callable before 'init' priority '10' for cron to call them", '0.1' );
				}
			}
		}
		$this->then_events = $keep_events;
		$this->commit();
	}

	public function commit() {
		$event_keys = get_option( 'tae_event_keys', array( ) );
		$event_key = substr( md5( serialize( $this->callback ) . serialize( $this->params ) ), 0, 30 );
		$next_scheduled_time = wp_next_scheduled( 'tae_event_' . $event_key );
		if ( count( $this->then_events ) ) {
			$event_keys = array_merge( $event_keys, array( $event_key ) );
			delete_option( 'tae_event_' . $event_key );
			wp_unschedule_event( $next_scheduled_time, 'tae_event_' . $event_key );
		} else {
			$event_keys = array_diff( $event_keys, array( $event_key ) );
			update_option( 'tae_event_' . $event_key, array(
				'callback' => $this->callback,
				'params' => $this->params,
				'frequency' => $this->frequency,
				'then_events' => $this->then_events
			) );
			if ( !$next_scheduled_time ) {
				wp_schedule_event( time() + $this->frequency, 'tae_schedule_' . $event_key, 'tae_event_' . $event_key );
			}
		}

		update_option( 'tae_event_keys', $event_keys );
	}

}