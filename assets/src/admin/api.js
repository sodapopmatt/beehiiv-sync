const config = window.beehiivSync || { apiUrl: '', nonce: '' };

async function request( path, { method = 'GET', body } = {} ) {
	const response = await fetch( `${ config.apiUrl }${ path }`, {
		method,
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		body: body ? JSON.stringify( body ) : undefined,
	} );

	const text = await response.text();
	const data = text ? JSON.parse( text ) : null;

	if ( ! response.ok ) {
		const message = data?.message || `Request failed (${ response.status }).`;
		throw new Error( message );
	}
	return data;
}

export const api = {
	getCredentialsStatus: () => request( '/credentials' ),
	storeCredentials: ( payload ) => request( '/credentials', { method: 'POST', body: payload } ),
	forgetCredentials: () => request( '/credentials', { method: 'DELETE' } ),
	testCredentials: ( payload ) => request( '/credentials/test', { method: 'POST', body: payload } ),
	getSettings: () => request( '/settings' ),
	updateSettings: ( payload ) => request( '/settings', { method: 'PUT', body: payload } ),
};
