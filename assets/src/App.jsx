import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner, TabPanel } from '@wordpress/components';
import Connect from './pages/Connect';
import Import from './pages/Import';
import Logs from './pages/Logs';
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

	const tabs = [
		{ name: 'connect', title: __( 'Connect', 'beehiiv-sync' ) },
		{ name: 'import', title: __( 'Import', 'beehiiv-sync' ) },
		{ name: 'logs', title: __( 'Logs', 'beehiiv-sync' ) },
	];

	return (
		<div className="beehiiv-sync">
			<h1>{ __( 'Beehiiv Sync', 'beehiiv-sync' ) }</h1>
			<TabPanel tabs={ tabs } initialTabName={ status.configured ? 'import' : 'connect' }>
				{ ( tab ) => {
					if ( tab.name === 'connect' ) {
						return <Connect status={ status } onChange={ refresh } />;
					}
					if ( tab.name === 'logs' ) {
						return <Logs />;
					}
					return <Import credentialsConfigured={ status.configured } />;
				} }
			</TabPanel>
		</div>
	);
}
