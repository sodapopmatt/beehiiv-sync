import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
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

	const toggleStatus = ( key ) => ( checked ) => {
		setSelectedStatuses( ( prev ) => ( { ...prev, [ key ]: checked } ) );
	};

	const start = async () => {
		const statuses = Object.entries( selectedStatuses )
			.filter( ( [ , v ] ) => v )
			.map( ( [ k ] ) => k );

		if ( statuses.length === 0 ) {
			setError( __( 'Pick at least one beehiiv status to import.', 'beehiiv-sync' ) );
			return;
		}

		setStarting( true );
		setError( null );
		// Render the progress card immediately so the user sees feedback before the first poll.
		setRun( {
			status: 'queued',
			current_stage: __( 'Starting…', 'beehiiv-sync' ),
			counts: { items_seen: 0, inserted: 0, updated: 0, skipped: 0, expected_total: 0 },
			errors: [],
			last_event_at: Math.floor( Date.now() / 1000 ),
		} );

		const payload = {
			audience,
			beehiiv_statuses: statuses,
			defaults: {
				post_type: postType,
				author_id: authorId ? Number( authorId ) : 0,
				tag_target: tagTarget,
				import_mode: importMode,
				fixed_taxonomy: fixedTaxonomy || '',
				fixed_term_id: fixedTermId ? Number( fixedTermId ) : 0,
				post_status_map: statusMap,
			},
		};

		try {
			const { run_id } = await api.startImport( payload );
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

	if ( ! credentialsConfigured ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'Connect your beehiiv credentials first.', 'beehiiv-sync' ) }
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
					<Heading level={ 4 }>{ __( 'Step 1: Choose data from beehiiv', 'beehiiv-sync' ) }</Heading>
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

						<div className="bs-section-label">
							{ __( 'Beehiiv status → WordPress status', 'beehiiv-sync' ) }
						</div>
						<div>
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
								label={ __( 'Import beehiiv tags as', 'beehiiv-sync' ) }
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

			<HStack justify="flex-start">
				<Button
					variant="primary"
					onClick={ start }
					disabled={ starting || isRunning }
					isBusy={ starting }
				>
					{ isRunning
						? __( 'Importing…', 'beehiiv-sync' )
						: __( 'Start import', 'beehiiv-sync' ) }
				</Button>
			</HStack>

			{ run && <RunProgress run={ run } /> }
		</VStack>
	);
}
