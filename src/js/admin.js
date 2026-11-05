/**
 * CPT-Taxonomy Syncer - Settings page
 *
 * Adds and removes pair rows, and runs the batched manual sync with a
 * progress bar that is announced to assistive technology.
 */

import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import domReady from '@wordpress/dom-ready';

import './admin.scss';

const BATCH_DELAY_MS = 100;
const PROGRESS_FADE_MS = 2000;

const config = window.cptTaxSyncerAdmin ?? {
	restNamespace: 'cpt-tax-syncer/v1',
};

/**
 * Sends a request to one of the plugin's REST routes.
 *
 * apiFetch adds the REST nonce and root URL in the admin.
 *
 * @param {string} route  Route below the namespace, e.g. '/batch-sync/init'.
 * @param {string} method HTTP method.
 * @param {Object} data   Body (POST) or query (GET) parameters.
 * @return {Promise<Object>} The decoded response.
 */
function request( route, method, data ) {
	return apiFetch( {
		path: `/${ config.restNamespace }${ route }`,
		method,
		data,
	} );
}

/**
 * Keeps the pairs table editable: add rows from the template, remove rows,
 * and renumber the field names so PHP receives a dense array.
 *
 * @param {HTMLTableElement} table The pairs table.
 */
function setupPairsTable( table ) {
	const tbody = table.querySelector( 'tbody' );
	const template = document.getElementById( 'cpt-tax-syncer-pair-template' );

	const renumber = () => {
		Array.from( tbody.querySelectorAll( 'tr' ) ).forEach(
			( row, index ) => {
				row.querySelectorAll( '[name]' ).forEach( ( field ) => {
					field.name = field.name.replace(
						/\[\d+\]/,
						`[${ index }]`
					);
				} );
				row.querySelectorAll( '[id], [for]' ).forEach( ( element ) => {
					for ( const attribute of [ 'id', 'htmlFor' ] ) {
						if ( element[ attribute ] ) {
							element[ attribute ] = element[ attribute ].replace(
								/pair-\d+-/,
								`pair-${ index }-`
							);
						}
					}
				} );
			}
		);
	};

	table
		.querySelector( '.cpt-tax-syncer-add-pair' )
		?.addEventListener( 'click', () => {
			tbody.querySelector( '.cpt-tax-syncer-no-pairs' )?.remove();
			const index = tbody.querySelectorAll( 'tr' ).length;
			tbody.insertAdjacentHTML(
				'beforeend',
				template.innerHTML.replace( /{{index}}/g, String( index ) )
			);
			tbody.querySelector( 'tr:last-child select' )?.focus();
		} );

	tbody.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.cpt-tax-syncer-remove-pair' );
		if ( ! button ) {
			return;
		}

		button.closest( 'tr' ).remove();

		if ( ! tbody.querySelector( 'tr' ) ) {
			tbody.insertAdjacentHTML(
				'beforeend',
				`<tr class="cpt-tax-syncer-no-pairs"><td colspan="4">${ __(
					'No pairs configured yet.',
					'cpt-taxonomy-syncer'
				) }</td></tr>`
			);
		}

		renumber();
		table.querySelector( '.cpt-tax-syncer-add-pair' )?.focus();
	} );
}

/**
 * Shows the result notice, announces it, and moves focus to it.
 *
 * @param {string}  message The message.
 * @param {boolean} isError Whether it is an error.
 */
function showResult( message, isError ) {
	const notice = document.getElementById( 'cpt-tax-syncer-sync-result' );
	if ( ! notice ) {
		return;
	}

	notice.classList.toggle( 'notice-error', isError );
	notice.classList.toggle( 'notice-success', ! isError );
	notice.querySelector( 'p' ).textContent = message;
	notice.hidden = false;
	notice.setAttribute( 'tabindex', '-1' );
	notice.focus();
	speak( message, isError ? 'assertive' : 'polite' );
}

/**
 * Builds (or reuses) the progress bar under a sync button.
 *
 * @param {HTMLButtonElement} button The sync button.
 * @return {{container: HTMLElement, bar: HTMLElement, text: HTMLElement}} Progress elements.
 */
function createProgress( button ) {
	let container = button.parentElement.querySelector(
		'.cpt-tax-syncer-progress'
	);

	if ( ! container ) {
		container = document.createElement( 'div' );
		container.className = 'cpt-tax-syncer-progress';
		container.innerHTML =
			'<div class="cpt-tax-syncer-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">' +
			'<div class="cpt-tax-syncer-progress__bar"></div></div>' +
			'<div class="cpt-tax-syncer-progress__text" aria-live="polite"></div>';
		button.parentElement.appendChild( container );
	}

	container.hidden = false;

	return {
		container,
		track: container.querySelector( '.cpt-tax-syncer-progress__track' ),
		bar: container.querySelector( '.cpt-tax-syncer-progress__bar' ),
		text: container.querySelector( '.cpt-tax-syncer-progress__text' ),
	};
}

/**
 * Processes batches until the server reports the run complete.
 *
 * @param {string} batchId  The batch ID from /batch-sync/init.
 * @param {Object} progress Progress elements from createProgress().
 * @return {Promise<Object>} The final batch response.
 */
async function processBatches( batchId, progress ) {
	let response;

	do {
		response = await request( '/batch-sync/process', 'POST', {
			batch_id: batchId,
		} );

		const percentage = Math.round( response.percentage || 0 );
		progress.bar.style.width = `${ percentage }%`;
		progress.track.setAttribute( 'aria-valuenow', String( percentage ) );
		progress.text.textContent = `${ response.message } (${ response.processed }/${ response.total })`;

		if ( ! response.complete ) {
			await new Promise( ( resolve ) =>
				setTimeout( resolve, BATCH_DELAY_MS )
			);
		}
	} while ( ! response.complete );

	return response;
}

/**
 * Runs one manual sync from a button click.
 *
 * The button is marked aria-disabled rather than disabled so keyboard
 * focus stays on it for the whole run.
 *
 * @param {HTMLButtonElement} button The sync button.
 */
async function startSync( button ) {
	if ( button.getAttribute( 'aria-disabled' ) === 'true' ) {
		return;
	}

	const originalLabel = button.textContent.trim();
	const progress = createProgress( button );
	let batchId = null;

	button.setAttribute( 'aria-disabled', 'true' );
	button.textContent = __( 'Initializing…', 'cpt-taxonomy-syncer' );

	try {
		const init = await request( '/batch-sync/init', 'POST', {
			cpt_slug: button.dataset.cpt,
			taxonomy_slug: button.dataset.taxonomy,
			operation: button.dataset.operation,
		} );
		batchId = init.batch_id;
		button.textContent = __( 'Syncing…', 'cpt-taxonomy-syncer' );

		const result = await processBatches( batchId, progress );
		showResult( result.message, false );
		setTimeout( () => {
			progress.container.hidden = true;
		}, PROGRESS_FADE_MS );
	} catch ( error ) {
		progress.container.hidden = true;
		showResult(
			sprintf(
				/* translators: %s: error message */
				__( 'Error: %s', 'cpt-taxonomy-syncer' ),
				error?.message || __( 'Unknown error', 'cpt-taxonomy-syncer' )
			),
			true
		);
	} finally {
		if ( batchId ) {
			request( '/batch-sync/cleanup', 'POST', {
				batch_id: batchId,
			} ).catch( () => {} );
		}
		button.removeAttribute( 'aria-disabled' );
		button.textContent = originalLabel;
	}
}

domReady( () => {
	const table = document.getElementById( 'cpt-tax-syncer-pairs' );
	if ( table ) {
		setupPairsTable( table );
	}

	document
		.querySelectorAll( '.cpt-tax-syncer-sync-button' )
		.forEach( ( button ) =>
			button.addEventListener( 'click', () => startSync( button ) )
		);
} );
