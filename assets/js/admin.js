document.addEventListener( 'DOMContentLoaded', function () {
	var charts = document.querySelectorAll( '.aisignal-markdown-converter-chart[data-chart-config]' );
	var index;

	function initializeChart( canvas ) {
		var rawConfig;
		var config;

		if ( 'undefined' === typeof window.Chart ) {
			return;
		}

		rawConfig = canvas.getAttribute( 'data-chart-config' );

		if ( ! rawConfig ) {
			return;
		}

		try {
			config = JSON.parse( rawConfig );

			if ( ! config || ! config.type || ! config.data ) {
				return;
			}

			new window.Chart( canvas, config );
		} catch ( exception ) {
			return;
		}
	}

	for ( index = 0; index < charts.length; index++ ) {
		initializeChart( charts[ index ] );
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! form || ! form.classList || ! form.classList.contains( 'aisignal-markdown-converter-crawler-clear-form' ) ) {
			return;
		}

		var message = form.getAttribute( 'data-confirm-message' );

		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
} );
