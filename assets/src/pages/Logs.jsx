import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { api } from '../api';

export default function Logs() {
	const [ log, setLog ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ logEnabled, setLogEnabled ] = useState( false );
	const [ togglingLog, setTogglingLog ] = useState( false );

	const [ sampleStatus, setSampleStatus ] = useState( 'draft' );
	const [ sample, setSample ] = useState( null );
	const [ sampleLoading, setSampleLoading ] = useState( false );

	const refresh = async () => {
		setLoading( true );
		setError( null );
		try {
			const [ logData, enabledData ] = await Promise.all( [
				api.getDebugLog(),
				api.getLogEnabled(),
			] );
			setLog( logData );
			setLogEnabled( enabledData.enabled );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		refresh();
	}, [] );

	const toggleLogEnabled = async ( checked ) => {
		setTogglingLog( true );
		try {
			const result = await api.setLogEnabled( checked );
			setLogEnabled( result.enabled );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setTogglingLog( false );
		}
	};

	const clearLog = async () => {
		try {
			await api.clearDebugLog();
			refresh();
		} catch ( e ) {
			setError( e.message );
		}
	};

	const runSample = async () => {
		setSampleLoading( true );
		setSample( null );
		setError( null );
		try {
			setSample( await api.getDiagnosticSample( sampleStatus, 'all' ) );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setSampleLoading( false );
		}
	};

	return (
		<VStack spacing={ 4 }>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<HStack justify="space-between">
						<HStack spacing={ 3 } justify="flex-start">
							<strong>{ __( 'Debug log', 'beehiiv-sync' ) }</strong>
							<CheckboxControl
								label={ __( 'Enable', 'beehiiv-sync' ) }
								checked={ logEnabled }
								onChange={ toggleLogEnabled }
								disabled={ togglingLog }
								__nextHasNoMarginBottom
							/>
						</HStack>
						<HStack spacing={ 2 }>
							<Button variant="secondary" onClick={ refresh } disabled={ loading }>
								{ __( 'Refresh', 'beehiiv-sync' ) }
							</Button>
							<Button variant="tertiary" isDestructive onClick={ clearLog } disabled={ ! log?.tail }>
								{ __( 'Clear', 'beehiiv-sync' ) }
							</Button>
						</HStack>
					</HStack>
				</CardHeader>
				<CardBody>
					{ loading && <Spinner /> }
					{ log && (
						<>
							<p style={ { fontSize: 12, color: '#666' } }>
								{ log.path || __( 'No log file yet.', 'beehiiv-sync' ) }
							</p>
							<pre
								style={ {
									background: '#f6f7f7',
									border: '1px solid #ddd',
									padding: 12,
									maxHeight: 480,
									overflow: 'auto',
									fontSize: 12,
									whiteSpace: 'pre-wrap',
									wordBreak: 'break-all',
								} }
							>
								{ log.tail || __( '(empty)', 'beehiiv-sync' ) }
							</pre>
						</>
					) }
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<strong>{ __( 'Fetch a sample post (no import)', 'beehiiv-sync' ) }</strong>
				</CardHeader>
				<CardBody>
					<VStack spacing={ 4 }>
						<p>
							{ __(
								'Pulls one Beehiiv post via the same path the importer uses and dumps the request URL, response, extracted content lengths, and the result after sanitization. Use this to find where content is getting lost.',
								'beehiiv-sync'
							) }
						</p>
						<HStack justify="flex-start" spacing={ 3 }>
							<SelectControl
								label={ __( 'Beehiiv status', 'beehiiv-sync' ) }
								value={ sampleStatus }
								options={ [
									{ value: 'confirmed', label: 'confirmed' },
									{ value: 'draft', label: 'draft' },
									{ value: 'archived', label: 'archived' },
								] }
								onChange={ setSampleStatus }
								__nextHasNoMarginBottom
							/>
							<Button variant="primary" onClick={ runSample } isBusy={ sampleLoading }>
								{ __( 'Run diagnostic', 'beehiiv-sync' ) }
							</Button>
						</HStack>
						{ sample && (
							<pre
								style={ {
									background: '#f6f7f7',
									border: '1px solid #ddd',
									padding: 12,
									maxHeight: 480,
									overflow: 'auto',
									fontSize: 12,
									whiteSpace: 'pre-wrap',
									wordBreak: 'break-all',
								} }
							>
								{ JSON.stringify( sample, null, 2 ) }
							</pre>
						) }
					</VStack>
				</CardBody>
			</Card>
		</VStack>
	);
}
