<?php

class Asynch_Event_Watcher {

	private static $watcher_instances = array( );

	/**
	 * Identifier for the watcher.  Used for setting the name of the cron hook
	 * and frequency
	 * @var type 
	 */
	private $id;

	/**
	 * Seconds in between checks for condition
	 * @var type 
	 */
	private $watch_frequency;
	private $test_events;

	/**
	 * 
	 * @param string $id Identifier for the watcher.  Used for setting the name of the cron hook and frequency
	 * @param int $watch_frequency seconds between checks
	 */
	private function __construct( $id, $watch_frequency = 300 ) {
		$this->id = $id;
		$this->watch_frequency = $watch_frequency;

		$this->test_events = get_option( 'ew_' . $this->id, array() );
		
		add_action('ew_'.$this->id, array($this, 'test_when'));
		
		add_filter( 'cron_schedules', array( $this, '_filter_cron_schedule' ) );

		register_shutdown_function( array( $this, '_shutdown' ) );
	}

	/**
	 * 
	 * @param type $test_callback
	 * @param type $test_params
	 * @return AE_TestEvent
	 */
	public function when( $test_callback, $test_params = array( ) ) {//, $do_callback, $do_params, $test_value = true, $eval_function = null ) {
		$key = substr(md5( serialize( $test_callback ) . serialize( $test_params ) ), 0, 30);
		if ( !isset( $this->test_events[$key] ) ) {
			$this->test_events[$key] = new AE_TestEvent( $test_callback, $test_params );
			wp_schedule_event(time(), 'ew_' . $this->id, 'ew_' . $this->id, array($key));
		}
		return $this->test_events[$key];
	}
	
	public function test_when($key) {
		if(isset($this->test_events[$key])) {
			if($this->test_events[$key]->execute()) {
				wp_clear_scheduled_hook('ew_' . $this->id, array($key));
				unset($this->test_events[$key]);
			}
		}
	}

	public function _filter_cron_schedule( $schedules ) {
		$schedules['ew_' . $this->id] = array(
			'interval' => $this->watch_frequency,
			'display' => '',
		);

		return $schedules;
	}

	public function _shutdown() {
		update_option( 'ew_' . $this->id, $this->test_events );
	}

	/**
	 * Factory method for event watchers so they are unique by ID per request to prevent 
	 * instances from stomping eachother out.
	 * 
	 * @param string $id Identifier for the watcher.  Used for setting the name of the cron hook and frequency
	 * @param int $watch_frequency seconds between checks
	 * @return Asynch_Event_Watcher
	 */
	public static function GetEventWatcher( $id, $watch_frequency = 300 ) {
		if ( !isset( self::$watcher_instances[$id] ) ) {
			self::$watcher_instances[$id] = new Asynch_Event_Watcher( $id, $watch_frequency );
		}
		return self::$watcher_instances[$id];
	}

}

class AE_Event {

	private $callback;
	private $params;

	public function __construct( $callback, $params = array( ) ) {
		$this->callback = $callback;
		$this->params = $params;
	}

	public function execute() {
		return call_user_func_array( $this->callback, $this->params );
	}

}

class AE_TestEvent extends AE_Event {

	private $then_events;

	public function __construct( $callback, $params = array( ) ) {
		parent::__construct( $callback, $params );
		$this->then_events = array( );
	}

	public function then( $then_callback, $then_params = array( ) ) {
		$this->then_events[] = new AE_TestEvent( $then_callback, $then_params );
		return $this;
	}

	public function execute() {
		if ( parent::execute() === true ) {
			foreach ( $this->then_events as $event ) {
				$event->execute();
			}
			return true;
		}
		false;
	}

}