/**
 * DashboardSplash component.
 *
 * Site Kit by Google, Copyright 2019 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * External dependencies
 */
import {
	addPerformanceMonitoring,
	clearAppLocalStorage,
} from 'GoogleUtil';
import Notification from 'GoogleComponents/notifications/notification';

const { setLocaleData } = wp.i18n;
const { Component, render, Fragment } = wp.element;
const { doAction } = wp.hooks;

/**
 * Internal dependencies
 */
import DashboardSplashApp from './components/dashboard-splash/dashboard-splash-app';
import NotificationCounter from './components/notifications/notification-counter';

class GoogleSitekitDashboardSplash extends Component {
	constructor( props ) {
		super( props );
		this.state = {
			hasError: false,
		};

		// Set up translations.
		setLocaleData( googlesitekit.locale, 'google-site-kit' );

		if ( window.googlesitekit.admin.debug ) {
			addPerformanceMonitoring();
		}
	}

	componentDidCatch( error, info ) {
		this.setState( {
			hasError: true,
			error,
			info,
		} );
	}

	componentDidMount() {
		doAction( 'googlesitekit.rootAppDidMount' );
	}

	render() {
		const {
			hasError,
			error,
			info,
		} = this.state;

		if ( hasError ) {
			return <Notification
				id={ 'googlesitekit-error' }
				key={ 'googlesitekit-error' }
				title={ error }
				description={ info.componentStack }
				dismiss={ '' }
				isDismissable={ false }
				format="small"
				type="win-error"
			/>;
		}

		return (
			<Fragment>
				<NotificationCounter />
				<DashboardSplashApp />
			</Fragment>
		);
	}
}

// Initialize the app once the DOM is ready.
wp.domReady( function() {
	if ( googlesitekit.admin.resetSession ) {
		clearAppLocalStorage();
	}

	const dashboardSplash = document.getElementById( 'js-googlesitekit-dashboard-splash' );
	if ( null !== dashboardSplash ) {
		// Render the Dashboard Splash App.
		render( <GoogleSitekitDashboardSplash />, dashboardSplash );

		/**
		 * Action triggered when the Dashboard Splash App is loaded.
		 */
		doAction( 'googlesitekit.moduleLoaded', 'Splash' );
	}
} );
