import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Card,
	CardBody,
	Notice,
	Spinner,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
} from '@wordpress/components';

const STATUS_LABEL = {
	queued: __( 'Queued', 'beehiiv-sync' ),
	running: __( 'Running', 'beehiiv-sync' ),
	completed: __( 'Completed', 'beehiiv-sync' ),
	failed: __( 'Failed', 'beehiiv-sync' ),
};

const STATUS_COLOR = {
	queued: '#dba617',
	running: '#2271b1',
	completed: '#00a32a',
	failed: '#d63638',
};

function relTime( ts ) {
	if ( ! ts ) return '';
	const now = Math.floor( Date.now() / 1000 );
	const delta = Math.max( 0, now - ts );
	if ( delta < 5 ) return __( 'just now', 'beehiiv-sync' );
	if ( delta < 60 ) return sprintf( __( '%ds ago', 'beehiiv-sync' ), delta );
	if ( delta < 3600 ) return sprintf( __( '%dm ago', 'beehiiv-sync' ), Math.floor( delta / 60 ) );
	return sprintf( __( '%dh ago', 'beehiiv-sync' ), Math.floor( delta / 3600 ) );
}

export default function RunProgress( { run } ) {
	const [ , setNow ] = useState( Date.now() );

	// Tick once a second so the "last update" relative timestamp stays fresh
	// even between polls.
	useEffect( () => {
		const id = setInterval( () => setNow( Date.now() ), 1000 );
		return () => clearInterval( id );
	}, [] );

	const counts = run.counts ?? {};
	const seen = counts.items_seen ?? 0;
	const total = counts.expected_total ?? 0;
	const pct = total > 0 ? Math.min( 100, Math.round( ( seen / total ) * 100 ) ) : 0;
	const indeterminate = total === 0 && ( run.status === 'queued' || run.status === 'running' );

	const statusColor = STATUS_COLOR[ run.status ] || '#666';
	const statusLabel = STATUS_LABEL[ run.status ] || run.status;

	return (
		<Card>
			<CardBody>
				<VStack spacing={ 4 }>
					<HStack justify="space-between" alignment="center">
						<HStack spacing={ 2 } justify="flex-start">
							{ ( run.status === 'queued' || run.status === 'running' ) && <Spinner /> }
							<span
								style={ {
									background: statusColor,
									color: 'white',
									padding: '2px 10px',
									borderRadius: 12,
									fontSize: 12,
									fontWeight: 600,
									textTransform: 'uppercase',
								} }
							>
								{ statusLabel }
							</span>
							<span style={ { color: '#555' } }>{ run.current_stage }</span>
						</HStack>
						<span style={ { color: '#888', fontSize: 12 } }>
							{ __( 'Updated', 'beehiiv-sync' ) } { relTime( run.last_event_at ) }
						</span>
					</HStack>

					<div>
						<div
							style={ {
								height: 10,
								background: '#eee',
								borderRadius: 6,
								overflow: 'hidden',
								position: 'relative',
							} }
						>
							{ indeterminate ? (
								<div
									style={ {
										position: 'absolute',
										inset: 0,
										background:
											'linear-gradient(90deg, transparent 0%, #2271b1 50%, transparent 100%)',
										backgroundSize: '40% 100%',
										animation: 'bsIndeterminate 1.2s linear infinite',
									} }
								/>
							) : (
								<div
									style={ {
										width: `${ pct }%`,
										height: '100%',
										background: statusColor,
										transition: 'width 250ms ease',
									} }
								/>
							) }
						</div>
						<HStack justify="space-between">
							<span style={ { color: '#666', fontSize: 13 } }>
								{ total > 0
									? sprintf(
											/* translators: 1: processed, 2: total, 3: percent */
											__( '%1$d of %2$d processed (%3$d%%)', 'beehiiv-sync' ),
											seen,
											total,
											pct
									  )
									: sprintf( __( '%d items processed', 'beehiiv-sync' ), seen ) }
							</span>
							<HStack spacing={ 4 } justify="flex-end">
								<Stat label={ __( 'Inserted', 'beehiiv-sync' ) } value={ counts.inserted ?? 0 } color="#00a32a" />
								<Stat label={ __( 'Updated', 'beehiiv-sync' ) } value={ counts.updated ?? 0 } color="#2271b1" />
								<Stat label={ __( 'Skipped', 'beehiiv-sync' ) } value={ counts.skipped ?? 0 } color="#888" />
							</HStack>
						</HStack>
					</div>

					{ run.errors && run.errors.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							{ sprintf( __( '%d error(s):', 'beehiiv-sync' ), run.errors.length ) }
							<ul style={ { margin: '8px 0 0 16px' } }>
								{ run.errors.slice( -5 ).map( ( e, i ) => (
									<li key={ i }>
										<code>{ e.beehiiv_id }</code>: { e.message }
									</li>
								) ) }
							</ul>
						</Notice>
					) }
				</VStack>
			</CardBody>
			<style>
				{ `@keyframes bsIndeterminate { 0% { background-position: -40% 0; } 100% { background-position: 140% 0; } }` }
			</style>
		</Card>
	);
}

function Stat( { label, value, color } ) {
	return (
		<span style={ { fontSize: 13 } }>
			<span style={ { color: '#666' } }>{ label }:</span>{ ' ' }
			<strong style={ { color } }>{ value }</strong>
		</span>
	);
}
