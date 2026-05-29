import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalHeading as Heading,
} from '@wordpress/components';
import { api } from '../api';
import RunProgress from '../components/RunProgress';

const BEEHIIV_STATUSES = [
	{ value: 'confirmed', label: __( 'Published (confirmed)', 'beehiiv-sync' ) },
	{ value: 'draft', label: __( 'Drafts', 'beehiiv-sync' ) },
	{ value: 'archived', label: __( 'Archived', 'beehiiv-sync' ) },
];

const ACTION_META = {
	new: { label: __( 'New', 'beehiiv-sync' ), color: '#00a32a' },
	update: { label: __( 'Update', 'beehiiv-sync' ), color: '#2271b1' },
	unchanged: { label: __( 'Unchanged', 'beehiiv-sync' ), color: '#888' },
	skip: { label: __( 'Skip', 'beehiiv-sync' ), color: '#888' },
};

function formatDate( ts ) {
	if ( ! ts ) return '—';
	try {
		return new Date( ts * 1000 ).toLocaleDateString( undefined, {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
		} );
	} catch ( e ) {
		return '—';
	}
}

export default function Import( { credentialsConfigured } ) {
	// Per-run filters (seeded from saved settings).
	const [ audience, setAudience ] = useState( 'all' );
	const [ selectedStatuses, setSelectedStatuses ] = useState( {
		confirmed: true,
		draft: false,
		archived: false,
	} );
	const [ importMode, setImportMode ] = useState( 'both' );
	const [ loadingDefaults, setLoadingDefaults ] = useState( true );

	// Preview + selection
	const [ preview, setPreview ] = useState( null );
	const [ selected, setSelected ] = useState( {} );
	const [ search, setSearch ] = useState( '' );
	const [ previewing, setPreviewing ] = useState( false );

	// Run
	const [ run, setRun ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ starting, setStarting ] = useState( false );
	const pollRef = useRef( null );

	useEffect( () => {
		( async () => {
			try {
				const settings = await api.getSettings();
				const d = settings?.defaults || {};
				if ( d.audience ) setAudience( d.audience );
				if ( d.import_mode ) setImportMode( d.import_mode );
			} catch ( e ) {
				// Non-fatal: fall back to component defaults.
			} finally {
				setLoadingDefaults( false );
			}
		} )();
		return () => clearTimeout( pollRef.current );
	}, [] );

	const selectedBeehiivStatuses = () =>
		Object.entries( selectedStatuses )
			.filter( ( [ , v ] ) => v )
			.map( ( [ k ] ) => k );

	const basePayload = () => ( {
		audience,
		beehiiv_statuses: selectedBeehiivStatuses(),
		defaults: { import_mode: importMode },
	} );

	const runPreview = async () => {
		if ( selectedBeehiivStatuses().length === 0 ) {
			setError( __( 'Pick at least one beehiiv status to import.', 'beehiiv-sync' ) );
			return;
		}

		setPreviewing( true );
		setError( null );
		setRun( null );

		try {
			const result = await api.previewImport( basePayload() );
			setPreview( result );
			const next = {};
			( result.items || [] ).forEach( ( item ) => {
				next[ item.beehiiv_id ] = true;
			} );
			setSelected( next );
		} catch ( e ) {
			setError( e.message );
			setPreview( null );
		} finally {
			setPreviewing( false );
		}
	};

	const toggleRow = ( id ) => ( checked ) => {
		setSelected( ( prev ) => ( { ...prev, [ id ]: checked } ) );
	};

	const items = preview ? preview.items : [];
	const visibleItems = search
		? items.filter( ( i ) =>
				( i.title || '' ).toLowerCase().includes( search.toLowerCase() )
		  )
		: items;
	const selectedCount = items.filter( ( i ) => selected[ i.beehiiv_id ] ).length;
	const allVisibleSelected =
		visibleItems.length > 0 && visibleItems.every( ( i ) => selected[ i.beehiiv_id ] );

	const toggleAllVisible = ( checked ) => {
		setSelected( ( prev ) => {
			const next = { ...prev };
			visibleItems.forEach( ( i ) => {
				next[ i.beehiiv_id ] = checked;
			} );
			return next;
		} );
	};

	const startSelected = async () => {
		const ids = items.filter( ( i ) => selected[ i.beehiiv_id ] ).map( ( i ) => i.beehiiv_id );
		if ( ids.length === 0 ) {
			setError( __( 'Select at least one post to import.', 'beehiiv-sync' ) );
			return;
		}

		setStarting( true );
		setError( null );
		setRun( {
			status: 'queued',
			current_stage: __( 'Starting…', 'beehiiv-sync' ),
			counts: { items_seen: 0, inserted: 0, updated: 0, skipped: 0, expected_total: ids.length },
			errors: [],
			last_event_at: Math.floor( Date.now() / 1000 ),
		} );

		try {
			const { run_id } = await api.startImport( { ...basePayload(), selected_ids: ids } );
			let elapsed = 0;
			const tick = () => {
				poll( run_id );
				elapsed += 1000;
				const delay = elapsed < 30_000 ? 1000 : 2000;
				pollRef.current = setTimeout( tick, delay );
			};
			pollRef.current = setTimeout( tick, 500 );
		} catch ( e ) {
			setError( e.message );
			setRun( null );
		} finally {
			setStarting( false );
		}
	};

	const poll = async ( id ) => {
		try {
			const next = await api.getImportStatus( id );
			setRun( next );
			if ( next.status === 'completed' || next.status === 'failed' ) {
				clearTimeout( pollRef.current );
			}
		} catch ( e ) {
			setError( e.message );
			clearTimeout( pollRef.current );
		}
	};

	const resetForNewImport = () => {
		clearTimeout( pollRef.current );
		setRun( null );
		setPreview( null );
		setSelected( {} );
		setSearch( '' );
	};

	if ( ! credentialsConfigured ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'Connect your beehiiv credentials first.', 'beehiiv-sync' ) }
			</Notice>
		);
	}

	if ( loadingDefaults ) {
		return <Spinner />;
	}

	const isRunning = run && ( run.status === 'queued' || run.status === 'running' );

	return (
		<VStack spacing={ 4 }>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ /* Filters */ }
			<Card>
				<CardHeader>
					<Heading level={ 4 }>{ __( 'What to import', 'beehiiv-sync' ) }</Heading>
				</CardHeader>
				<CardBody>
					<VStack spacing={ 4 }>
						<div className="bs-form-row">
							<SelectControl
								label={ __( 'Content type', 'beehiiv-sync' ) }
								value={ audience }
								options={ [
									{ value: 'all', label: __( 'All', 'beehiiv-sync' ) },
									{ value: 'free', label: __( 'Free only', 'beehiiv-sync' ) },
									{ value: 'premium', label: __( 'Premium only', 'beehiiv-sync' ) },
								] }
								onChange={ setAudience }
								__nextHasNoMarginBottom
							/>
							<SelectControl
								label={ __( 'Import option', 'beehiiv-sync' ) }
								value={ importMode }
								options={ [
									{ value: 'new', label: __( 'New only', 'beehiiv-sync' ) },
									{ value: 'update', label: __( 'Update existing only', 'beehiiv-sync' ) },
									{ value: 'both', label: __( 'New + update', 'beehiiv-sync' ) },
								] }
								onChange={ setImportMode }
								__nextHasNoMarginBottom
							/>
							<div />
						</div>

						<div>
							<div className="bs-section-label">
								{ __( 'beehiiv statuses to include', 'beehiiv-sync' ) }
							</div>
							<HStack justify="flex-start" spacing={ 5 }>
								{ BEEHIIV_STATUSES.map( ( bs ) => (
									<CheckboxControl
										key={ bs.value }
										label={ bs.label }
										checked={ !! selectedStatuses[ bs.value ] }
										onChange={ ( checked ) =>
											setSelectedStatuses( ( prev ) => ( { ...prev, [ bs.value ]: checked } ) )
										}
										__nextHasNoMarginBottom
									/>
								) ) }
							</HStack>
						</div>

						<p className="bs-help-text">
							{ __(
								'Post type, author, taxonomy, tags and status mapping are configured on the Settings tab.',
								'beehiiv-sync'
							) }
						</p>

						<HStack justify="flex-start">
							<Button
								variant={ preview ? 'secondary' : 'primary' }
								onClick={ runPreview }
								disabled={ previewing || starting || isRunning }
								isBusy={ previewing }
							>
								{ preview
									? __( 'Refresh preview', 'beehiiv-sync' )
									: __( 'Preview import', 'beehiiv-sync' ) }
							</Button>
						</HStack>
					</VStack>
				</CardBody>
			</Card>

			{ preview && ! run && (
				<PreviewResults
					preview={ preview }
					visibleItems={ visibleItems }
					selected={ selected }
					selectedCount={ selectedCount }
					onToggleRow={ toggleRow }
					allVisibleSelected={ allVisibleSelected }
					onToggleAllVisible={ toggleAllVisible }
					search={ search }
					onSearch={ setSearch }
					onImport={ startSelected }
					importing={ starting }
				/>
			) }

			{ run && (
				<VStack spacing={ 3 }>
					<RunProgress run={ run } />
					{ ! isRunning && (
						<HStack justify="flex-start">
							<Button variant="secondary" onClick={ resetForNewImport }>
								{ __( 'Start another import', 'beehiiv-sync' ) }
							</Button>
						</HStack>
					) }
				</VStack>
			) }
		</VStack>
	);
}

function ActionBadge( { action } ) {
	const meta = ACTION_META[ action ] || ACTION_META.skip;
	return (
		<span
			style={ {
				background: meta.color,
				color: 'white',
				padding: '1px 8px',
				borderRadius: 10,
				fontSize: 11,
				fontWeight: 600,
				textTransform: 'uppercase',
				whiteSpace: 'nowrap',
			} }
		>
			{ meta.label }
		</span>
	);
}

function PreviewResults( {
	preview,
	visibleItems,
	selected,
	selectedCount,
	onToggleRow,
	allVisibleSelected,
	onToggleAllVisible,
	search,
	onSearch,
	onImport,
	importing,
} ) {
	const s = preview.summary || {};
	const considered = s.total ?? 0;
	const skippedCount = ( s.unchanged ?? 0 ) + ( s.skip ?? 0 );

	return (
		<Card>
			<CardHeader>
				<HStack justify="space-between" alignment="center">
					<Button
						variant="primary"
						onClick={ onImport }
						disabled={ importing || selectedCount === 0 }
						isBusy={ importing }
					>
						{ sprintf(
							/* translators: %d: number of selected posts */
							__( 'Import %d selected', 'beehiiv-sync' ),
							selectedCount
						) }
					</Button>
					<span style={ { color: '#666', fontSize: 13 } }>
						{ sprintf(
							/* translators: 1: considered count, 2: already up-to-date/excluded count */
							__( '%1$d considered · %2$d already up to date or excluded', 'beehiiv-sync' ),
							considered,
							skippedCount
						) }
					</span>
				</HStack>
			</CardHeader>
			<CardBody>
				<VStack spacing={ 3 }>
					{ preview.truncated && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Preview was capped at the first 1000 posts. Narrow the filters to see the rest.',
								'beehiiv-sync'
							) }
						</Notice>
					) }

					{ preview.items.length === 0 ? (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'No posts match the current criteria — everything is already imported and up to date, or excluded by your import option.',
								'beehiiv-sync'
							) }
						</Notice>
					) : (
						<>
							<SearchControl
								value={ search }
								onChange={ onSearch }
								placeholder={ __( 'Search by title…', 'beehiiv-sync' ) }
								__nextHasNoMarginBottom
							/>
							<table className="widefat striped" style={ { borderCollapse: 'collapse' } }>
								<thead>
									<tr>
										<th style={ { width: 36 } }>
											<CheckboxControl
												checked={ allVisibleSelected }
												onChange={ onToggleAllVisible }
												__nextHasNoMarginBottom
											/>
										</th>
										<th>{ __( 'Title', 'beehiiv-sync' ) }</th>
										<th style={ { width: 110 } }>{ __( 'beehiiv status', 'beehiiv-sync' ) }</th>
										<th style={ { width: 120 } }>{ __( 'Publish date', 'beehiiv-sync' ) }</th>
										<th style={ { width: 90 } }>{ __( 'Action', 'beehiiv-sync' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ visibleItems.map( ( item ) => (
										<tr key={ item.beehiiv_id }>
											<td>
												<CheckboxControl
													checked={ !! selected[ item.beehiiv_id ] }
													onChange={ onToggleRow( item.beehiiv_id ) }
													__nextHasNoMarginBottom
												/>
											</td>
											<td>
												{ item.web_url ? (
													<a href={ item.web_url } target="_blank" rel="noreferrer">
														{ item.title || __( '(untitled)', 'beehiiv-sync' ) }
													</a>
												) : (
													item.title || __( '(untitled)', 'beehiiv-sync' )
												) }
												{ item.action === 'update' && item.existing_post_id && (
													<span style={ { color: '#888', fontSize: 12 } }>
														{ ' ' }
														{ sprintf( __( '(post #%d)', 'beehiiv-sync' ), item.existing_post_id ) }
													</span>
												) }
											</td>
											<td>{ item.beehiiv_status }</td>
											<td>{ formatDate( item.publish_date ) }</td>
											<td>
												<ActionBadge action={ item.action } />
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</>
					) }
				</VStack>
			</CardBody>
		</Card>
	);
}
