import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	TextControl,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { api } from '../api';

export default function Connect( { status, onChange } ) {
	const [ apiKey, setApiKey ] = useState( '' );
	const [ publicationId, setPublicationId ] = useState( status.publication_id || '' );
	const [ busy, setBusy ] = useState( false );
	const [ message, setMessage ] = useState( null );

	const submit = async () => {
		setBusy( true );
		setMessage( null );
		try {
			const result = await api.storeCredentials( {
				api_key: apiKey,
				publication_id: publicationId,
			} );
			setApiKey( '' );
			setMessage( {
				status: 'success',
				text: __( 'Connected to ', 'beehiiv-sync' ) + ( result.publication?.name || publicationId ),
			} );
			onChange();
		} catch ( e ) {
			setMessage( { status: 'error', text: e.message } );
		} finally {
			setBusy( false );
		}
	};

	const test = async () => {
		setBusy( true );
		setMessage( null );
		try {
			const result = await api.testCredentials( {} );
			setMessage( {
				status: 'success',
				text: __( 'OK: ', 'beehiiv-sync' ) + ( result.publication?.name || publicationId ),
			} );
		} catch ( e ) {
			setMessage( { status: 'error', text: e.message } );
		} finally {
			setBusy( false );
		}
	};

	const disconnect = async () => {
		setBusy( true );
		try {
			await api.forgetCredentials();
			setPublicationId( '' );
			setMessage( null );
			onChange();
		} finally {
			setBusy( false );
		}
	};

	return (
		<Card>
			<CardHeader>
				<strong>{ __( 'Connection', 'beehiiv-sync' ) }</strong>
			</CardHeader>
			<CardBody>
				<VStack spacing={ 4 }>
					{ message && (
						<Notice status={ message.status } isDismissible={ false }>
							{ message.text }
						</Notice>
					) }

					{ status.configured ? (
						<>
							<p>
								{ __( 'Connected to publication: ', 'beehiiv-sync' ) }
								<code>{ status.publication_id }</code>
							</p>
							<HStack justify="flex-start">
								<Button variant="secondary" onClick={ test } disabled={ busy }>
									{ __( 'Test connection', 'beehiiv-sync' ) }
								</Button>
								<Button variant="tertiary" isDestructive onClick={ disconnect } disabled={ busy }>
									{ __( 'Disconnect', 'beehiiv-sync' ) }
								</Button>
							</HStack>
						</>
					) : (
						<>
							<TextControl
								label={ __( 'Beehiiv API key', 'beehiiv-sync' ) }
								type="password"
								value={ apiKey }
								onChange={ setApiKey }
								autoComplete="off"
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __( 'Publication ID', 'beehiiv-sync' ) }
								value={ publicationId }
								onChange={ setPublicationId }
								help={ __( 'Found in your beehiiv dashboard URL.', 'beehiiv-sync' ) }
								__nextHasNoMarginBottom
							/>
							<HStack justify="flex-start">
								<Button
									variant="primary"
									onClick={ submit }
									disabled={ busy || ! apiKey || ! publicationId }
								>
									{ __( 'Save & connect', 'beehiiv-sync' ) }
								</Button>
							</HStack>
						</>
					) }
				</VStack>
			</CardBody>
		</Card>
	);
}
