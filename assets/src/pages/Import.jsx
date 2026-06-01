import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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
	__experimentalHeading as Heading,
	__experimentalDivider as Divider,
} from '@wordpress/components';
import { api } from '../api';
import RunProgress from '../components/RunProgress';

const WP_STATUSES = [
	{ value: 'draft', label: __( 'Draft', 'beehiiv-sync' ) },
	{ value: 'publish', label: __( 'Published', 'beehiiv-sync' ) },
	{ value: 'pending', label: __( 'Pending Review', 'beehiiv-sync' ) },
	{ value: 'private', label: __( 'Private', 'beehiiv-sync' ) },
];

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

// apiFetch rejects with the parsed REST error ({ code, message, data }) on a
// WP_Error response, but with a bare Error (or nothing useful) on a network
// failure or non-JSON 5xx. Always fall back to a readable message so the error
// Notice is never blank.
function apiErrorMessage( e, fallback ) {
	if ( e && typeof e.message === 'string' && e.message.trim() !== '' ) {
		return e.message;
	}
	return fallback;
}

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
	// Lookup data
	const [ postTypes, setPostTypes ] = useState( [] );
	const [ taxonomies, setTaxonomies ] = useState( [] );
	const [ authors, setAuthors ] = useState( [] );
	const [ termOptions, setTermOptions ] = useState( [] );
	const [ loadingMeta, setLoadingMeta ] = useState( true );

	// Form state
	const [ audience, setAudience ] = useState( 'all' );
	const [ selectedStatuses, setSelectedStatuses ] = useState( {
		confirmed: true,
		draft: false,
		archived: false,
	} );
	const [ statusMap, setStatusMap ] = useState( {
		confirmed: 'draft',
		draft: 'draft',
		archived: 'draft',
	} );
	const [ postType, setPostType ] = useState( 'post' );
	const [ fixedTaxonomy, setFixedTaxonomy ] = useState( '' );
	const [ fixedTermId, setFixedTermId ] = useState( '' );
	const [ authorId, setAuthorId ] = useState( '' );
	const [ tagTarget, setTagTarget ] = useState( 'post_tag' );
	const [ importMode, setImportMode ] = useState( 'both' );

	// Preview state
	const [ preview, setPreview ] = useState( null );
	const [ selected, setSelected ] = useState( {} );
	const [ previewing, setPreviewing ] = useState( false );
	const [ previewElapsed, setPreviewElapsed ] = useState( 0 );

	// Run state
	const [ runId, setRunId ] = useState( null );
	const [ run, setRun ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ starting, setStarting ] = useState( false );
	const pollRef = useRef( null );

	// Load post types, taxonomies, authors + persisted defaults
	useEffect( () => {
		( async () => {
			try {
				const [ types, taxes, users, settings ] = await Promise.all( [
					api.getPostTypes(),
					api.getTaxonomies(),
					api.getAuthors(),
					api.getSettings(),
				] );

				const ptList = Object.values( types ).filter(
					( t ) => t.viewable !== false && t.slug !== 'attachment'
				);
				const taxList = Object.values( taxes );

				setPostTypes( ptList );
				setTaxonomies( taxList );
				setAuthors( users );

				// Seed form from persisted defaults
				const d = settings?.defaults || {};
				if ( d.post_type ) setPostType( d.post_type );
				if ( d.audience ) setAudience( d.audience );
				if ( d.tag_target ) setTagTarget( d.tag_target );
				if ( d.import_mode ) setImportMode( d.import_mode );
				if ( d.author_id ) setAuthorId( String( d.author_id ) );
				if ( d.fixed_taxonomy ) setFixedTaxonomy( d.fixed_taxonomy );
				if ( d.fixed_term_id ) setFixedTermId( String( d.fixed_term_id ) );
				if ( d.post_status_map ) setStatusMap( d.post_status_map );
			} catch ( e ) {
				setError( e.message );
			} finally {
				setLoadingMeta( false );
			}
		} )();
		return () => clearTimeout( pollRef.current );
	}, [] );

	// Filter taxonomies by selected post type
	const availableTaxonomies = useMemo(
		() => taxonomies.filter( ( t ) => Array.isArray( t.types ) && t.types.includes( postType ) ),
		[ taxonomies, postType ]
	);

	// Load terms when fixed taxonomy changes
	useEffect( () => {
		if ( ! fixedTaxonomy ) {
			setTermOptions( [] );
			return;
		}
		const tax = taxonomies.find( ( t ) => t.slug === fixedTaxonomy );
		if ( ! tax ) return;
		( async () => {
			try {
				const terms = await api.getTermsFor( tax.rest_base );
				setTermOptions( terms );
			} catch ( e ) {
				setError( e.message );
			}
		} )();
	}, [ fixedTaxonomy, taxonomies ] );

	// Tick an elapsed-seconds counter while a preview is in flight. The preview
	// is one blocking request that walks every page server-side, so this is the
	// only progress signal we can surface — but it tells the user it's working
	// and sets expectations for large publications.
	useEffect( () => {
		if ( ! previewing ) {
			setPreviewElapsed( 0 );
			return;
		}
		const started = Date.now();
		const id = setInterval( () => {
			setPreviewElapsed( Math.floor( ( Date.now() - started ) / 1000 ) );
		}, 1000 );
		return () => clearInterval( id );
	}, [ previewing ] );

	// Changing what to pull from beehiiv invalidates an existing preview.
	const statusesKey = JSON.stringify( selectedStatuses );
	useEffect( () => {
		setPreview( null );
		setSelected( {} );
	}, [ audience, statusesKey ] );

	const toggleStatus = ( key ) => ( checked ) => {
		setSelectedStatuses( ( prev ) => ( { ...prev, [ key ]: checked } ) );
	};

	const selectedBeehiivStatuses = () =>
		Object.entries( selectedStatuses )
			.filter( ( [ , v ] ) => v )
			.map( ( [ k ] ) => k );

	const basePayload = () => ( {
		audience,
		beehiiv_statuses: selectedBeehiivStatuses(),
		defaults: {
			post_type: postType,
			author_id: authorId ? Number( authorId ) : 0,
			tag_target: tagTarget,
			import_mode: importMode,
			fixed_taxonomy: fixedTaxonomy || '',
			fixed_term_id: fixedTermId ? Number( fixedTermId ) : 0,
			post_status_map: statusMap,
		},
	} );

	const runPreview = async () => {
		if ( selectedBeehiivStatuses().length === 0 ) {
			setError( __( 'Pick at least one Beehiiv status to import.', 'beehiiv-sync' ) );
			return;
		}

		setPreviewing( true );
		setError( null );
		setRun( null );
		setRunId( null );

		try {
			const result = await api.previewImport( basePayload() );
			setPreview( result );
			// Default-select everything actionable (new + update).
			const next = {};
			( result.items || [] ).forEach( ( item ) => {
				if ( item.selectable ) next[ item.beehiiv_id ] = true;
			} );
			setSelected( next );
		} catch ( e ) {
			setError(
				apiErrorMessage(
					e,
					__(
						'Preview failed. Beehiiv may be temporarily busy or returned an unexpected response — please wait a moment and try again.',
						'beehiiv-sync'
					)
				)
			);
			setPreview( null );
		} finally {
			setPreviewing( false );
		}
	};

	const toggleRow = ( id ) => ( checked ) => {
		setSelected( ( prev ) => ( { ...prev, [ id ]: checked } ) );
	};

	const selectableItems = preview ? preview.items.filter( ( i ) => i.selectable ) : [];
	const selectedCount = selectableItems.filter( ( i ) => selected[ i.beehiiv_id ] ).length;
	const allSelected = selectableItems.length > 0 && selectedCount === selectableItems.length;

	const toggleAll = ( checked ) => {
		const next = { ...selected };
		selectableItems.forEach( ( i ) => {
			next[ i.beehiiv_id ] = checked;
		} );
		setSelected( next );
	};

	const startSelected = async () => {
		const ids = selectableItems
			.filter( ( i ) => selected[ i.beehiiv_id ] )
			.map( ( i ) => i.beehiiv_id );

		if ( ids.length === 0 ) {
			setError( __( 'Select at least one post to import.', 'beehiiv-sync' ) );
			return;
		}

		setStarting( true );
		setError( null );
		// Render the progress card immediately so the user sees feedback before the first poll.
		setRun( {
			status: 'queued',
			current_stage: __( 'Starting…', 'beehiiv-sync' ),
			counts: { items_seen: 0, inserted: 0, updated: 0, skipped: 0, expected_total: ids.length },
			errors: [],
			last_event_at: Math.floor( Date.now() / 1000 ),
		} );

		try {
			const { run_id } = await api.startImport( { ...basePayload(), selected_ids: ids } );
			setRunId( run_id );
			// Aggressive polling at first (every 1s), then ease off to 2s after 30 seconds.
			let elapsed = 0;
			const tick = () => {
				poll( run_id );
				elapsed += 1000;
				const delay = elapsed < 30_000 ? 1000 : 2000;
				pollRef.current = setTimeout( tick, delay );
			};
			pollRef.current = setTimeout( tick, 500 );
		} catch ( e ) {
			setError(
				apiErrorMessage(
					e,
					__( 'Could not start the import. Please try again.', 'beehiiv-sync' )
				)
			);
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
			setError(
				apiErrorMessage(
					e,
					__( 'Lost contact with the import while checking its progress.', 'beehiiv-sync' )
				)
			);
			clearTimeout( pollRef.current );
		}
	};

	const resetForNewImport = () => {
		clearTimeout( pollRef.current );
		setRun( null );
		setRunId( null );
		setPreview( null );
		setSelected( {} );
	};

	if ( ! credentialsConfigured ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'Connect your Beehiiv credentials first.', 'beehiiv-sync' ) }
			</Notice>
		);
	}

	if ( loadingMeta ) {
		return <Spinner />;
	}

	const isRunning = run && ( run.status === 'queued' || run.status === 'running' );

	const postTypeOptions = postTypes.map( ( t ) => ( { value: t.slug, label: t.name } ) );
	const taxonomyOptions = [
		{ value: '', label: __( '— None —', 'beehiiv-sync' ) },
		...availableTaxonomies.map( ( t ) => ( { value: t.slug, label: t.name } ) ),
	];
	const termSelectOptions = [
		{ value: '', label: __( '— None —', 'beehiiv-sync' ) },
		...termOptions.map( ( t ) => ( { value: String( t.id ), label: t.name } ) ),
	];
	const authorOptions = [
		{ value: '', label: __( '— No author —', 'beehiiv-sync' ) },
		...authors.map( ( u ) => ( { value: String( u.id ), label: u.name } ) ),
	];

	return (
		<VStack spacing={ 4 }>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ /* Step 1 */ }
			<Card>
				<CardHeader>
					<Heading level={ 4 }>{ __( 'Step 1: Choose data from Beehiiv', 'beehiiv-sync' ) }</Heading>
				</CardHeader>
				<CardBody>
					<VStack spacing={ 4 }>
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

						<Divider />

						<div className="bs-status-grid">
							<div className="bs-status-header">
								<span>{ __( 'Beehiiv Status', 'beehiiv-sync' ) }</span>
								<span>{ __( 'WordPress Status', 'beehiiv-sync' ) }</span>
							</div>
							{ BEEHIIV_STATUSES.map( ( bs ) => (
								<div key={ bs.value } className="bs-status-row">
									<CheckboxControl
										label={ bs.label }
										checked={ !! selectedStatuses[ bs.value ] }
										onChange={ toggleStatus( bs.value ) }
										__nextHasNoMarginBottom
									/>
									<SelectControl
										value={ statusMap[ bs.value ] }
										options={ WP_STATUSES }
										onChange={ ( v ) =>
											setStatusMap( ( prev ) => ( { ...prev, [ bs.value ]: v } ) )
										}
										disabled={ ! selectedStatuses[ bs.value ] }
										__nextHasNoMarginBottom
									/>
								</div>
							) ) }
						</div>
					</VStack>
				</CardBody>
			</Card>

			{ /* Step 2 */ }
			<Card>
				<CardHeader>
					<Heading level={ 4 }>{ __( 'Step 2: Import data to WordPress', 'beehiiv-sync' ) }</Heading>
				</CardHeader>
				<CardBody>
					<VStack spacing={ 5 }>
						<div className="bs-form-row">
							<SelectControl
								label={ __( 'Post type', 'beehiiv-sync' ) }
								value={ postType }
								options={ postTypeOptions }
								onChange={ ( v ) => {
									setPostType( v );
									setFixedTaxonomy( '' );
									setFixedTermId( '' );
								} }
								__nextHasNoMarginBottom
							/>
							<SelectControl
								label={ __( 'Assign to taxonomy', 'beehiiv-sync' ) }
								value={ fixedTaxonomy }
								options={ taxonomyOptions }
								onChange={ ( v ) => {
									setFixedTaxonomy( v );
									setFixedTermId( '' );
								} }
								__nextHasNoMarginBottom
							/>
							<SelectControl
								label={ __( 'Term', 'beehiiv-sync' ) }
								value={ fixedTermId }
								options={ termSelectOptions }
								onChange={ setFixedTermId }
								disabled={ ! fixedTaxonomy }
								__nextHasNoMarginBottom
							/>
						</div>
						<div className="bs-form-row">
							<SelectControl
								label={ __( 'Author', 'beehiiv-sync' ) }
								value={ authorId }
								options={ authorOptions }
								onChange={ setAuthorId }
								__nextHasNoMarginBottom
							/>
							<SelectControl
								label={ __( 'Import Beehiiv tags as', 'beehiiv-sync' ) }
								value={ tagTarget }
								options={ [
									{ value: 'post_tag', label: __( 'Post Tag', 'beehiiv-sync' ) },
									{ value: 'category', label: __( 'Category', 'beehiiv-sync' ) },
									{ value: 'none', label: __( 'Skip tags', 'beehiiv-sync' ) },
								] }
								onChange={ setTagTarget }
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
						</div>
					</VStack>
				</CardBody>
			</Card>

			{ /* Step 3: preview, then import */ }
			{ ! run && (
				<HStack justify="flex-start" spacing={ 3 }>
					<Button
						variant={ preview ? 'secondary' : 'primary' }
						onClick={ runPreview }
						disabled={ previewing || starting }
						isBusy={ previewing }
					>
						{ preview
							? __( 'Refresh preview', 'beehiiv-sync' )
							: __( 'Preview import', 'beehiiv-sync' ) }
					</Button>
					{ preview && (
						<Button
							variant="primary"
							onClick={ startSelected }
							disabled={ starting || selectedCount === 0 }
							isBusy={ starting }
						>
							{ sprintf(
								/* translators: %d: number of selected posts */
								__( 'Import %d selected', 'beehiiv-sync' ),
								selectedCount
							) }
						</Button>
					) }
				</HStack>
			) }

			{ previewing && (
				<Notice status="info" isDismissible={ false }>
					<HStack justify="flex-start" spacing={ 2 } expanded={ false }>
						<Spinner />
						<span>
							{ sprintf(
								/* translators: %d: seconds elapsed */
								__(
									'Fetching posts from Beehiiv and checking what would import… (%ds)',
									'beehiiv-sync'
								),
								previewElapsed
							) }
							{ previewElapsed >= 10 && (
								<>
									{ ' ' }
									{ __(
										'Large publications can take up to a minute.',
										'beehiiv-sync'
									) }
								</>
							) }
						</span>
					</HStack>
				</Notice>
			) }

			{ preview && ! run && (
				<PreviewTable
					preview={ preview }
					selected={ selected }
					onToggleRow={ toggleRow }
					allSelected={ allSelected }
					onToggleAll={ toggleAll }
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

function PreviewTable( { preview, selected, onToggleRow, allSelected, onToggleAll } ) {
	const s = preview.summary || {};
	return (
		<Card>
			<CardHeader>
				<HStack justify="space-between">
					<Heading level={ 4 }>{ __( 'Preview', 'beehiiv-sync' ) }</Heading>
					<HStack spacing={ 3 } justify="flex-end">
						<Summary label={ __( 'New', 'beehiiv-sync' ) } value={ s.new ?? 0 } color="#00a32a" />
						<Summary label={ __( 'Update', 'beehiiv-sync' ) } value={ s.update ?? 0 } color="#2271b1" />
						<Summary label={ __( 'Unchanged', 'beehiiv-sync' ) } value={ s.unchanged ?? 0 } color="#888" />
						<Summary label={ __( 'Skip', 'beehiiv-sync' ) } value={ s.skip ?? 0 } color="#888" />
					</HStack>
				</HStack>
			</CardHeader>
			<CardBody>
				<VStack spacing={ 3 }>
					{ preview.truncated && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Preview was capped at the first 1000 posts. Narrow the status/content filters to see the rest.',
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
					<table className="widefat striped" style={ { borderCollapse: 'collapse' } }>
						<thead>
							<tr>
								<th style={ { width: 36 } }>
									<CheckboxControl
										checked={ allSelected }
										onChange={ onToggleAll }
										__nextHasNoMarginBottom
									/>
								</th>
								<th>{ __( 'Title', 'beehiiv-sync' ) }</th>
								<th style={ { width: 110 } }>{ __( 'Beehiiv Status', 'beehiiv-sync' ) }</th>
								<th style={ { width: 120 } }>{ __( 'Publish date', 'beehiiv-sync' ) }</th>
								<th style={ { width: 90 } }>{ __( 'Action', 'beehiiv-sync' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ preview.items.map( ( item ) => (
								<tr key={ item.beehiiv_id }>
									<td>
										<CheckboxControl
											checked={ !! selected[ item.beehiiv_id ] }
											disabled={ ! item.selectable }
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
					) }
				</VStack>
			</CardBody>
		</Card>
	);
}

function Summary( { label, value, color } ) {
	return (
		<span style={ { fontSize: 13 } }>
			<span style={ { color: '#666' } }>{ label }:</span>{ ' ' }
			<strong style={ { color } }>{ value }</strong>
		</span>
	);
}
