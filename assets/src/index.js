import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

const mount = document.getElementById( 'beehiiv-sync-app' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
