import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import Connect from './pages/Connect';
import { api } from './api';

export default function App() {
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );

	const refresh = async () => {
		try {
			setStatus( await api.getCredentialsStatus() );
		} catch ( e ) {
			setError( e.message );
		}
	};

	useEffect( () => {
		refresh();
	}, [] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	if ( ! status ) {
		return <Spinner />;
	}

	return (
		<div className="beehiiv-sync">
			<h1>{ __( 'Beehiiv Sync', 'beehiiv-sync' ) }</h1>
			<Connect status={ status } onChange={ refresh } />
		</div>
	);
}
