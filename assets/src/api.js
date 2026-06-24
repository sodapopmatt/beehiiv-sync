import apiFetch from '@wordpress/api-fetch';

const config = window.beehiivSync || { apiUrl: '', nonce: '' };

const NS = '/beehiiv-sync/v1';

apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

function ns( path ) {
	return `${ NS }${ path }`;
}

export const api = {
	getCredentialsStatus: () => apiFetch( { path: ns( '/credentials' ) } ),
	storeCredentials: ( payload ) =>
		apiFetch( { path: ns( '/credentials' ), method: 'POST', data: payload } ),
	forgetCredentials: () => apiFetch( { path: ns( '/credentials' ), method: 'DELETE' } ),
	testCredentials: ( payload ) =>
		apiFetch( { path: ns( '/credentials/test' ), method: 'POST', data: payload } ),
	getSettings: () => apiFetch( { path: ns( '/settings' ) } ),
	updateSettings: ( payload ) =>
		apiFetch( { path: ns( '/settings' ), method: 'PUT', data: payload } ),
	previewImport: ( payload ) =>
		apiFetch( { path: ns( '/import/preview' ), method: 'POST', data: payload } ),
	startImport: ( payload ) =>
		apiFetch( { path: ns( '/import' ), method: 'POST', data: payload } ),
	getImportStatus: ( runId ) =>
		apiFetch( { path: ns( `/import/${ encodeURIComponent( runId ) }` ) } ),

	// WordPress core REST for populating dropdowns.
	getPostTypes: () => apiFetch( { path: '/wp/v2/types?context=edit' } ),
	getTaxonomies: () => apiFetch( { path: '/wp/v2/taxonomies?context=edit' } ),
	getAuthors: () =>
		apiFetch( { path: '/wp/v2/users?context=edit&who=authors&per_page=100' } ),
	getTermsFor: ( taxonomyRestBase ) =>
		apiFetch( {
			path: `/wp/v2/${ taxonomyRestBase }?per_page=100&_fields=id,name,slug,parent`,
		} ),

	getDebugLog: () => apiFetch( { path: ns( '/diagnostics/log' ) } ),
	clearDebugLog: () => apiFetch( { path: ns( '/diagnostics/log' ), method: 'DELETE' } ),
	getLogEnabled: () => apiFetch( { path: ns( '/diagnostics/log/enabled' ) } ),
	setLogEnabled: ( enabled ) =>
		apiFetch( { path: ns( '/diagnostics/log/enabled' ), method: 'POST', data: { enabled } } ),
	getDiagnosticSample: ( status = 'draft', audience = 'all' ) =>
		apiFetch( {
			path: ns( `/diagnostics/sample?status=${ status }&audience=${ audience }` ),
		} ),
};
