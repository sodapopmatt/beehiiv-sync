import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalHeading as Heading,
	__experimentalDivider as Divider,
} from '@wordpress/components';
import { api } from '../api';

const WP_STATUSES = [
	{ value: 'draft', label: __( 'Draft', 'beehiiv-sync' ) },
	{ value: 'publish', label: __( 'Published', 'beehiiv-sync' ) },
	{ value: 'pending', label: __( 'Pending Review', 'beehiiv-sync' ) },
	{ value: 'private', label: __( 'Private', 'beehiiv-sync' ) },
	{ value: 'future', label: __( 'Scheduled', 'beehiiv-sync' ) },
];

const BEEHIIV_STATUSES = [
	{ value: 'confirmed', label: __( 'Published (confirmed)', 'beehiiv-sync' ) },
	{ value: 'draft', label: __( 'Drafts', 'beehiiv-sync' ) },
	{ value: 'archived', label: __( 'Archived', 'beehiiv-sync' ) },
];

export default function Settings() {
	// Lookup data
	const [ postTypes, setPostTypes ] = useState( [] );
	const [ taxonomies, setTaxonomies ] = useState( [] );
	const [ authors, setAuthors ] = useState( [] );
	const [ termOptions, setTermOptions ] = useState( [] );
	const [ loading, setLoading ] = useState( true );

	// Form state
	const [ postType, setPostType ] = useState( 'post' );
	const [ authorId, setAuthorId ] = useState( '' );
	const [ tagTarget, setTagTarget ] = useState( 'post_tag' );
	const [ fixedTaxonomy, setFixedTaxonomy ] = useState( '' );
	const [ fixedTermId, setFixedTermId ] = useState( '' );
	const [ statusMap, setStatusMap ] = useState( {
		confirmed: 'draft',
		draft: 'draft',
		archived: 'draft',
	} );

	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState( null );

	useEffect( () => {
		( async () => {
			try {
				const [ types, taxes, users, settings ] = await Promise.all( [
					api.getPostTypes(),
					api.getTaxonomies(),
					api.getAuthors(),
					api.getSettings(),
				] );

				setPostTypes(
					Object.values( types ).filter(
						( t ) => t.viewable !== false && t.slug !== 'attachment'
					)
				);
				setTaxonomies( Object.values( taxes ) );
				setAuthors( users );

				const d = settings?.defaults || {};
				if ( d.post_type ) setPostType( d.post_type );
				if ( d.author_id ) setAuthorId( String( d.author_id ) );
				if ( d.tag_target ) setTagTarget( d.tag_target );
				if ( d.fixed_taxonomy ) setFixedTaxonomy( d.fixed_taxonomy );
				if ( d.fixed_term_id ) setFixedTermId( String( d.fixed_term_id ) );
				if ( d.post_status_map ) setStatusMap( d.post_status_map );
			} catch ( e ) {
				setMessage( { status: 'error', text: e.message } );
			} finally {
				setLoading( false );
			}
		} )();
	}, [] );

	const availableTaxonomies = useMemo(
		() => taxonomies.filter( ( t ) => Array.isArray( t.types ) && t.types.includes( postType ) ),
		[ taxonomies, postType ]
	);

	useEffect( () => {
		if ( ! fixedTaxonomy ) {
			setTermOptions( [] );
			return;
		}
		const tax = taxonomies.find( ( t ) => t.slug === fixedTaxonomy );
		if ( ! tax ) return;
		( async () => {
			try {
				setTermOptions( await api.getTermsFor( tax.rest_base ) );
			} catch ( e ) {
				setMessage( { status: 'error', text: e.message } );
			}
		} )();
	}, [ fixedTaxonomy, taxonomies ] );

	const save = async () => {
		setSaving( true );
		setMessage( null );
		try {
			await api.updateSettings( {
				defaults: {
					post_type: postType,
					author_id: authorId ? Number( authorId ) : 0,
					tag_target: tagTarget,
					fixed_taxonomy: fixedTaxonomy || '',
					fixed_term_id: fixedTermId ? Number( fixedTermId ) : 0,
					post_status_map: statusMap,
				},
			} );
			setMessage( { status: 'success', text: __( 'Settings saved.', 'beehiiv-sync' ) } );
		} catch ( e ) {
			setMessage( { status: 'error', text: e.message } );
		} finally {
			setSaving( false );
		}
	};

	if ( loading ) {
		return <Spinner />;
	}

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
			{ message && (
				<Notice status={ message.status } onRemove={ () => setMessage( null ) }>
					{ message.text }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<Heading level={ 4 }>{ __( 'Import defaults', 'beehiiv-sync' ) }</Heading>
				</CardHeader>
				<CardBody>
					<VStack spacing={ 5 }>
						<p className="bs-help-text">
							{ __(
								'These defaults apply to every import. Set them once here; the Import tab just picks which posts to bring over.',
								'beehiiv-sync'
							) }
						</p>

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
						</div>

						<div className="bs-form-row">
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
							<div />
						</div>
					</VStack>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<Heading level={ 4 }>{ __( 'Status mapping', 'beehiiv-sync' ) }</Heading>
				</CardHeader>
				<CardBody>
					<VStack spacing={ 4 }>
						<p className="bs-help-text">
							{ __(
								'Choose the WordPress status each beehiiv status becomes when imported.',
								'beehiiv-sync'
							) }
						</p>
						<Divider />
						<div>
							{ BEEHIIV_STATUSES.map( ( bs ) => (
								<div key={ bs.value } className="bs-status-row">
									<span>{ bs.label }</span>
									<SelectControl
										value={ statusMap[ bs.value ] }
										options={ WP_STATUSES }
										onChange={ ( v ) =>
											setStatusMap( ( prev ) => ( { ...prev, [ bs.value ]: v } ) )
										}
										__nextHasNoMarginBottom
									/>
								</div>
							) ) }
						</div>
					</VStack>
				</CardBody>
			</Card>

			<HStack justify="flex-start">
				<Button variant="primary" onClick={ save } disabled={ saving } isBusy={ saving }>
					{ __( 'Save settings', 'beehiiv-sync' ) }
				</Button>
			</HStack>
		</VStack>
	);
}
