<?php
/**
 * Shared batch runner.
 *
 * Every module that walks the posts or media tables registers a job here
 * instead of hand-rolling its own offset loop, nonce and progress UI. A job
 * is resumable: state lives in an option, so a closed browser tab or a PHP
 * timeout does not lose the position.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Batch {

	const AJAX_ACTION = 'dos_batch_step';
	const NONCE       = 'dos_batch';
	const STATE_PREFIX = 'dos_batch_state_';

	private static $jobs = array();

	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'collect_jobs' ), 5 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_step' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Ask each active module for its jobs. Runs on admin_init, which also
	 * fires for admin-ajax.php, so the AJAX handler sees the same registry.
	 */
	public static function collect_jobs() {
		foreach ( DOS_Toolkit::modules() as $key => $module ) {
			if ( ! DOS_Toolkit::is_active( $key ) || ! class_exists( $module['class'] ) ) {
				continue;
			}

			$jobs = call_user_func( array( $module['class'], 'jobs' ) );

			if ( ! is_array( $jobs ) ) {
				continue;
			}

			foreach ( $jobs as $job_key => $args ) {
				$args['module'] = $key;

				self::register( $job_key, $args );
			}
		}

		do_action( 'dos_toolkit_register_jobs' );
	}

	/**
	 * @param string $key  Unique job key.
	 * @param array  $args {
	 *     @type string   $label       Human label for the button.
	 *     @type string   $module      Owning module key.
	 *     @type string   $description Shown under the button.
	 *     @type callable $count       () => int total items.
	 *     @type callable $step        ( int $offset, int $size, bool $dry_run )
	 *                                 => array( processed, changed, notes ).
	 *     @type int      $batch_size  Items per request. Default 50.
	 *     @type bool     $destructive Adds a typed confirmation before running live.
	 * }
	 */
	public static function register( $key, array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'label'       => $key,
				'module'      => 'core',
				'description' => '',
				'count'       => null,
				'step'        => null,
				'batch_size'  => 50,
				'destructive' => false,
			)
		);

		if ( ! is_callable( $args['count'] ) || ! is_callable( $args['step'] ) ) {
			return;
		}

		self::$jobs[ $key ] = $args;
	}

	public static function job( $key ) {
		return isset( self::$jobs[ $key ] ) ? self::$jobs[ $key ] : null;
	}

	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'dos-' ) ) {
			return;
		}

		wp_enqueue_style( 'dos-toolkit-admin', DOS_TOOLKIT_URL . 'assets/admin.css', array(), DOS_TOOLKIT_VERSION );

		wp_enqueue_script( 'dos-toolkit-batch', DOS_TOOLKIT_URL . 'assets/batch.js', array(), DOS_TOOLKIT_VERSION, true );

		wp_localize_script(
			'dos-toolkit-batch',
			'dosBatch',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE ),
				'strings' => array(
					'running'  => __( 'Running…', 'dos-toolkit' ),
					'done'     => __( 'Finished.', 'dos-toolkit' ),
					'failed'   => __( 'Failed. See the log for details.', 'dos-toolkit' ),
					'confirm'  => __( 'This will change site data. Type RUN to continue.', 'dos-toolkit' ),
					'canceled' => __( 'Canceled.', 'dos-toolkit' ),
				),
			)
		);
	}

	public static function state( $key ) {
		$state = get_option( self::STATE_PREFIX . $key, array() );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'offset'    => 0,
				'total'     => 0,
				'processed' => 0,
				'changed'   => 0,
				'dry_run'   => true,
				'started'   => 0,
			)
		);
	}

	private static function save_state( $key, array $state ) {
		update_option( self::STATE_PREFIX . $key, $state, false );
	}

	public static function reset( $key ) {
		delete_option( self::STATE_PREFIX . $key );
	}

	public static function handle_step() {
		if ( ! current_user_can( DOS_Settings::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dos-toolkit' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );

		$key = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$job = self::job( $key );

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Unknown job.', 'dos-toolkit' ) ), 400 );
		}

		$restart = ! empty( $_POST['restart'] );
		$dry_run = ! empty( $_POST['dry_run'] );

		if ( $restart ) {
			self::reset( $key );
		}

		$state = self::state( $key );

		if ( $restart ) {
			$state['total']   = (int) call_user_func( $job['count'] );
			$state['dry_run'] = $dry_run;
			$state['started'] = time();

			DOS_Log::add( $job['module'], 'batch_start', sprintf( '%s started (%d items).', $job['label'], $state['total'] ), 0, $dry_run );
		}

		$size   = max( 1, (int) $job['batch_size'] );
		$result = call_user_func( $job['step'], (int) $state['offset'], $size, (bool) $state['dry_run'] );

		$result = wp_parse_args(
			is_array( $result ) ? $result : array(),
			array(
				'processed' => 0,
				'changed'   => 0,
				'notes'     => array(),
			)
		);

		$state['offset']   += $size;
		$state['processed'] += (int) $result['processed'];
		$state['changed']  += (int) $result['changed'];

		$done = ( 0 === (int) $result['processed'] ) || ( $state['offset'] >= (int) $state['total'] );

		self::save_state( $key, $state );

		if ( $done ) {
			DOS_Log::add(
				$job['module'],
				'batch_finish',
				sprintf( '%s finished. %d scanned, %d changed.', $job['label'], $state['processed'], $state['changed'] ),
				0,
				(bool) $state['dry_run']
			);

			DOS_Log::prune();
		}

		wp_send_json_success(
			array(
				'done'      => $done,
				'total'     => (int) $state['total'],
				'processed' => (int) $state['processed'],
				'changed'   => (int) $state['changed'],
				'dryRun'    => (bool) $state['dry_run'],
				'notes'     => array_map( 'strval', (array) $result['notes'] ),
			)
		);
	}

	/**
	 * Print the runner UI for a job. Modules call this from their page.
	 */
	public static function render_runner( $key ) {
		$job = self::job( $key );

		if ( ! $job ) {
			return;
		}

		$state   = self::state( $key );
		$dry_run = DOS_Settings::dry_run_default();
		?>
		<div class="dos-job" data-job="<?php echo esc_attr( $key ); ?>" data-destructive="<?php echo $job['destructive'] ? '1' : '0'; ?>">
			<h3><?php echo esc_html( $job['label'] ); ?></h3>

			<?php if ( $job['description'] ) : ?>
				<p class="description"><?php echo esc_html( $job['description'] ); ?></p>
			<?php endif; ?>

			<p>
				<label>
					<input type="checkbox" class="dos-job-dry-run" <?php checked( $dry_run ); ?> />
					<?php esc_html_e( 'Dry run (report what would change, change nothing)', 'dos-toolkit' ); ?>
				</label>
			</p>

			<p>
				<button type="button" class="button button-primary dos-job-start"><?php esc_html_e( 'Run', 'dos-toolkit' ); ?></button>
				<span class="dos-job-status"></span>
			</p>

			<div class="dos-job-progress"><div class="dos-job-bar"></div></div>

			<?php if ( $state['processed'] ) : ?>
				<p class="description dos-job-last">
					<?php
					printf(
						/* translators: 1: items scanned, 2: items changed */
						esc_html__( 'Last run: %1$d scanned, %2$d changed.', 'dos-toolkit' ),
						(int) $state['processed'],
						(int) $state['changed']
					);
					?>
				</p>
			<?php endif; ?>

			<ul class="dos-job-notes"></ul>
		</div>
		<?php
	}
}
