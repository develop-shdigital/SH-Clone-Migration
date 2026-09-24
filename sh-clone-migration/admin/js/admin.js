/**
 * SH Clone Migration admin application.
 *
 * No framework and no jQuery: the admin screens are small enough that plain
 * DOM code is easier to audit, and a migration UI must keep working even when
 * a site's other scripts are broken.
 */
( function () {
	'use strict';

	var data = window.shcmData || {};
	var strings = data.strings || {};

	/* ---------------------------------------------------------------- utils */

	function $( selector, scope ) {
		return ( scope || document ).querySelector( selector );
	}

	function $$( selector, scope ) {
		return Array.prototype.slice.call( ( scope || document ).querySelectorAll( selector ) );
	}

	function formatBytes( bytes ) {
		bytes = Number( bytes ) || 0;
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var index = 0;
		while ( bytes >= 1024 && index < units.length - 1 ) {
			bytes /= 1024;
			index++;
		}
		return ( index === 0 ? bytes : bytes.toFixed( 2 ) ) + ' ' + units[ index ];
	}

	function formatNumber( value ) {
		return ( Number( value ) || 0 ).toLocaleString();
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( String( value == null ? '' : value ) ) );
		return div.innerHTML;
	}

	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	/* ------------------------------------------------------------------ api */

	var transport = {
		/** Whether the standalone endpoint is known to work. */
		endpointOk: null
	};

	function buildBody( action, payload ) {
		var body = new FormData();
		body.append( 'action', 'shcm_' + action );
		body.append( 'nonce', data.nonce );
		Object.keys( payload || {} ).forEach( function ( key ) {
			var value = payload[ key ];
			if ( value === undefined || value === null ) {
				return;
			}
			if ( value instanceof Blob || value instanceof File ) {
				body.append( key, value );
			} else if ( typeof value === 'boolean' ) {
				body.append( key, value ? '1' : '0' );
			} else if ( Array.isArray( value ) || typeof value === 'object' ) {
				body.append( key, JSON.stringify( value ) );
			} else {
				body.append( key, value );
			}
		} );
		return body;
	}

	/**
	 * Call the server.
	 *
	 * Retries transient network failures with a backoff: a migration must not
	 * die because one request was dropped.
	 */
	function api( action, payload, options ) {
		options = options || {};
		var attempts = options.attempts === undefined ? 4 : options.attempts;
		var url = options.url || data.ajaxUrl;

		function attempt( remaining, delay ) {
			return fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				body: buildBody( action, payload )
			} ).then( function ( response ) {
				return response.text().then( function ( text ) {
					var json;
					try {
						json = JSON.parse( text );
					} catch ( e ) {
						throw new Error(
							'Unexpected server response (' + response.status + '). ' +
							text.slice( 0, 200 )
						);
					}
					if ( ! json.success ) {
						var message = json.data && json.data.message ? json.data.message : strings.genericError;
						var error = new Error( message );
						error.serverError = true;
						error.payload = json.data;
						throw error;
					}
					return json.data;
				} );
			} ).catch( function ( error ) {
				if ( error.serverError || remaining <= 0 ) {
					throw error;
				}
				return sleep( delay ).then( function () {
					return attempt( remaining - 1, delay * 2 );
				} );
			} );
		}

		return attempt( attempts, 2000 );
	}

	/**
	 * Tick a job, preferring the standalone endpoint for imports so that
	 * maintenance mode cannot block the request that is meant to end it.
	 */
	function tick( jobId, password, useEndpoint, token ) {
		var payload = { job_id: jobId, password: password, job_token: token || '' };

		if ( useEndpoint && transport.endpointOk !== false && data.endpointUrl ) {
			return api( 'tick', payload, { url: data.endpointUrl, attempts: 1 } )
				.then( function ( result ) {
					transport.endpointOk = true;
					return result;
				} )
				.catch( function ( error ) {
					if ( error.serverError ) {
						throw error;
					}
					transport.endpointOk = false;
					return api( 'tick', payload );
				} );
		}
		return api( 'tick', payload );
	}

	/* -------------------------------------------------------------- progress */

	function ProgressView( root ) {
		this.root = root;
		this.bar = $( '[data-role="overall-bar"]', root );
		this.label = $( '[data-role="overall-label"]', root );
		this.message = $( '[data-role="overall-message"]', root );
		this.stages = $( '[data-role="stages"]', root );
		this.facts = $( '[data-role="facts"]', root );
		this.groups = null;
		this.log = $( '[data-role="log"]', root );
		this.title = $( '#shcm-progress-title' );
	}

	ProgressView.prototype.show = function () {
		this.root.classList.remove( 'shcm-hidden' );
	};

	ProgressView.prototype.render = function ( job ) {
		var progress = Number( job.progress ) || 0;
		this.bar.style.width = progress + '%';
		this.label.textContent = progress.toFixed( 1 ) + '%';
		this.message.textContent = job.message || '';

		if ( job.status === 'completed' ) {
			this.bar.parentNode.classList.add( 'shcm-bar--done' );
		} else if ( job.status === 'failed' || job.status === 'cancelled' ) {
			this.bar.parentNode.classList.add( 'shcm-bar--failed' );
		}

		this.renderStages( job );
		this.renderFacts( job );

		if ( this.log && job.log ) {
			this.log.textContent = job.log.join( '\n' );
			this.log.scrollTop = this.log.scrollHeight;
		}
	};

	ProgressView.prototype.renderStages = function ( job ) {
		if ( ! this.stages ) {
			return;
		}
		var list = job.stage_list || [];
		var currentIndex = -1;
		list.forEach( function ( stage, index ) {
			if ( stage.key === job.stage ) {
				currentIndex = index;
			}
		} );

		var html = list.map( function ( stage, index ) {
			var state = 'is-pending';
			var percent = 0;
			if ( index < currentIndex || job.status === 'completed' ) {
				state = 'is-done';
				percent = 100;
			} else if ( index === currentIndex ) {
				state = 'is-active';
				percent = Math.round( ( Number( job.stage_progress ) || 0 ) * 100 );
			}
			var icon = state === 'is-done' ? 'dashicons-yes-alt' : ( state === 'is-active' ? 'dashicons-update' : 'dashicons-marker' );
			return '<li class="shcm-stage ' + state + '">' +
				'<span class="shcm-stage__icon dashicons ' + icon + '"></span>' +
				'<span class="shcm-stage__label">' + escapeHtml( stage.label ) + '</span>' +
				'<span class="shcm-bar"><span class="shcm-bar__fill" style="width:' + percent + '%"></span></span>' +
				'<span class="shcm-stage__percent">' + percent + '%</span>' +
				'</li>';
		} ).join( '' );

		this.stages.innerHTML = html;
	};

	ProgressView.prototype.renderFacts = function ( job ) {
		if ( ! this.facts ) {
			return;
		}
		var report = job.report || {};
		var facts = [];

		if ( job.type === 'export' && report.database_included !== null && report.database_included !== undefined ) {
			// Always shown for an export, zero included: a missing database
			// must be visible, not silently absent from the list.
			facts.push( [ 'Tables', report.database_included === false ? 'not included' : formatNumber( report.tables ), ! report.database_included || ! report.tables ] );
		} else if ( report.tables ) {
			facts.push( [ 'Tables', formatNumber( report.tables ) ] );
		}
		if ( report.database && report.database.rows ) {
			facts.push( [ 'Rows', formatNumber( report.database.rows ) ] );
		}
		if ( report.file_totals && report.file_totals.files ) {
			facts.push( [ 'Files', formatNumber( report.file_totals.files ) ] );
			facts.push( [ 'Source size', formatBytes( report.file_totals.bytes ) ] );
		}
		if ( report.file_totals && report.file_totals.skipped ) {
			facts.push( [ 'Skipped', formatNumber( report.file_totals.skipped ), true ] );
		}
		if ( report.archive_size ) {
			facts.push( [ 'Archive', formatBytes( report.archive_size ) + ' (' + formatNumber( report.archive_size ) + ' bytes)' ] );
		}
		if ( report.urls && report.urls.stats ) {
			facts.push( [ 'Values updated', formatNumber( report.urls.stats.values_changed ) ] );
		}

		this.facts.innerHTML = facts.map( function ( fact ) {
			return '<span' + ( fact[ 2 ] ? ' class="shcm-text-danger"' : '' ) + '>' + escapeHtml( fact[ 0 ] ) + ': <strong>' + escapeHtml( fact[ 1 ] ) + '</strong></span>';
		} ).join( '' );

		this.renderGroups( report );
	};

	/**
	 * Per-group progress, measured against the totals the scan produced.
	 *
	 * The scan counts every file before anything is written, so these bars
	 * show real fractions rather than an animation.
	 */
	ProgressView.prototype.renderGroups = function ( report ) {
		var container = this.groups;
		if ( ! container ) {
			container = document.createElement( 'div' );
			container.className = 'shcm-groups';
			this.facts.parentNode.insertBefore( container, this.facts );
			this.groups = container;
		}

		var totals = ( report.file_totals && report.file_totals.groups ) || {};
		var done = report.files_progress || {};
		var names = Object.keys( totals );

		if ( ! names.length ) {
			container.innerHTML = '';
			return;
		}

		var order = [ 'plugins', 'themes', 'mu-plugins', 'uploads', 'languages', 'other', 'core' ];
		names.sort( function ( a, b ) {
			var ai = order.indexOf( a );
			var bi = order.indexOf( b );
			return ( ai < 0 ? 99 : ai ) - ( bi < 0 ? 99 : bi );
		} );

		container.innerHTML = names.map( function ( name ) {
			var total = totals[ name ] || { files: 0, bytes: 0 };
			var made = done[ name ] || { files: 0, bytes: 0 };
			var percent = total.bytes > 0
				? Math.min( 100, ( made.bytes / total.bytes ) * 100 )
				: ( total.files > 0 ? Math.min( 100, ( made.files / total.files ) * 100 ) : 0 );

			return '<div class="shcm-group">' +
				'<span class="shcm-group__label">' + escapeHtml( name ) + '</span>' +
				'<span class="shcm-bar"><span class="shcm-bar__fill" style="width:' + percent + '%"></span></span>' +
				'<span class="shcm-group__count">' + formatNumber( made.files ) + ' / ' + formatNumber( total.files ) + '</span>' +
				'<span class="shcm-group__percent">' + percent.toFixed( 0 ) + '%</span>' +
				'</div>';
		} ).join( '' );
	};

	/* ------------------------------------------------------------ job runner */

	function JobRunner( options ) {
		this.options = options || {};
		this.view = options.view;
		this.password = '';
		this.jobId = null;
		this.token = '';
		this.stopped = false;
	}

	JobRunner.prototype.start = function ( action, payload, password ) {
		var self = this;
		this.password = password || '';
		this.stopped = false;
		this.view.show();

		return api( action, payload )
			.then( function ( job ) {
				self.jobId = job.id;
				// The token authorises finishing this job even after a restore
				// has replaced the user account this session belongs to.
				self.token = job.token || '';
				return self.loop( job );
			} )
			.catch( function ( error ) {
				self.fail( error );
			} );
	};

	JobRunner.prototype.resume = function ( jobId, password ) {
		var self = this;
		this.jobId = jobId;
		this.password = password || '';
		this.stopped = false;
		this.view.show();

		return tick( jobId, this.password, this.options.useEndpoint, this.token )
			.then( function ( job ) {
				return self.loop( job );
			} )
			.catch( function ( error ) {
				self.fail( error );
			} );
	};

	JobRunner.prototype.loop = function ( job ) {
		var self = this;
		this.view.render( job );

		if ( this.stopped ) {
			return job;
		}

		if ( job.status === 'completed' || job.status === 'failed' || job.status === 'cancelled' ) {
			if ( this.options.onFinish ) {
				this.options.onFinish( job );
			}
			return job;
		}

		return sleep( 250 )
			.then( function () {
				return tick( self.jobId, self.password, self.options.useEndpoint, self.token );
			} )
			.then( function ( next ) {
				return self.loop( next );
			} )
			.catch( function ( error ) {
				self.fail( error );
			} );
	};

	JobRunner.prototype.cancel = function () {
		var self = this;
		if ( ! this.jobId ) {
			return Promise.resolve();
		}
		this.stopped = true;
		return api( 'cancel', { job_id: this.jobId, job_token: this.token } ).then( function ( job ) {
			self.view.render( job );
			if ( self.options.onFinish ) {
				self.options.onFinish( job );
			}
		} );
	};

	JobRunner.prototype.fail = function ( error ) {
		this.stopped = true;
		if ( this.options.onError ) {
			this.options.onError( error );
		}
	};

	/* ------------------------------------------------------------ result view */

	function showResult( html, tone ) {
		var panel = $( '#shcm-result-panel' );
		if ( ! panel ) {
			window.alert( html.replace( /<[^>]+>/g, '' ) );
			return;
		}
		panel.classList.remove( 'shcm-hidden' );
		panel.className = 'shcm-panel';
		$( '[data-role="result-body"]', panel ).innerHTML = html;
		var title = $( '[data-role="result-title"]', panel );
		if ( tone === 'error' ) {
			title.textContent = strings.failed || 'Failed';
			title.style.color = '#d63638';
		} else {
			title.textContent = '';
		}
		panel.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function renderChecks( checks ) {
		if ( ! checks || ! checks.length ) {
			return '';
		}
		return '<ul class="shcm-checklist">' + checks.map( function ( check ) {
			var cls = check.pass ? 'is-pass' : ( check.level === 'warning' ? 'is-warn' : 'is-fail' );
			var state = check.pass ? 'PASS' : ( check.level === 'warning' ? 'NOTE' : 'FAIL' );
			return '<li><span class="shcm-check-state ' + cls + '">' + state + '</span>' +
				'<span>' + escapeHtml( check.label ) + '</span>' +
				( check.detail ? '<span class="shcm-check-detail">' + escapeHtml( check.detail ) + '</span>' : '' ) +
				'</li>';
		} ).join( '' ) + '</ul>';
	}

	function renderReplaceReport( report ) {
		if ( ! report || ! report.stats ) {
			return '';
		}
		var s = report.stats;
		var html = '<div class="shcm-facts">' +
			'<span>Tables scanned: <strong>' + formatNumber( s.tables_scanned ) + '</strong></span>' +
			'<span>Rows scanned: <strong>' + formatNumber( s.rows_scanned ) + '</strong></span>' +
			'<span>Values changed: <strong>' + formatNumber( s.values_changed ) + '</strong></span>' +
			'<span>Serialized values repaired: <strong>' + formatNumber( s.serialized_repaired ) + '</strong></span>' +
			'<span>Unparsable values skipped: <strong>' + formatNumber( s.serialized_failed ) + '</strong></span>' +
			'<span>Source references left: <strong>' + formatNumber( s.remaining_refs ) + '</strong></span>' +
			'</div>';

		if ( report.samples && report.samples.length ) {
			html += '<h3>References kept for review</h3><div class="shcm-samples"><table><tbody>' +
				report.samples.map( function ( sample ) {
					return '<tr><td><code>' + escapeHtml( sample.table ) + '.' + escapeHtml( sample.column ) + '</code>' +
						( sample.key ? ' <span class="description">' + escapeHtml( sample.key ) + '</span>' : '' ) +
						'</td><td>' + escapeHtml( sample.excerpt ) + '</td></tr>';
				} ).join( '' ) + '</tbody></table></div>';
		}

		if ( report.failures && report.failures.length ) {
			html += '<h3>Serialized values that could not be parsed</h3><div class="shcm-samples"><table><tbody>' +
				report.failures.map( function ( failure ) {
					return '<tr><td><code>' + escapeHtml( failure.table ) + '.' + escapeHtml( failure.column ) + '</code></td>' +
						'<td>' + escapeHtml( failure.key ) + '</td></tr>';
				} ).join( '' ) + '</tbody></table></div>';
		}

		return html;
	}

	function renderWarnings( job ) {
		if ( ! job.warnings || ! job.warnings.length ) {
			return '';
		}
		var total = Number( job.warnings_total ) || job.warnings.length;
		var note = total > job.warnings.length
			? '<p class="description">Showing the last ' + formatNumber( job.warnings.length ) + ' of ' + formatNumber( total ) +
				' warnings. The migration log lists every one of them.</p>'
			: '';
		return '<h3>Warnings (' + formatNumber( total ) + ')</h3>' + note + '<ul class="ul-disc">' + job.warnings.map( function ( warning ) {
			return '<li>' + escapeHtml( warning.message ) + '</li>';
		} ).join( '' ) + '</ul>';
	}

	/**
	 * What an export put into its archive, in words: the database (or its
	 * absence), the files per group, the exact size and the SHA-256 to check
	 * a downloaded copy against.
	 */
	function renderArchiveSummary( job ) {
		var report = job.report || {};
		var rows = [];
		var name = report.archive || '';

		rows.push( [ 'Archive size', formatBytes( report.archive_size ) + ' &mdash; exactly <strong>' + formatNumber( report.archive_size ) + ' bytes</strong>' ] );

		if ( report.sha256 ) {
			rows.push( [ 'SHA-256', '<code class="shcm-hash">' + escapeHtml( report.sha256 ) + '</code>' ] );
		}

		var db = report.database || {};
		if ( report.database_included === false || ! db.included ) {
			rows.push( [ 'Database', '<span class="shcm-text-danger">NOT included in this archive</span>' ] );
		} else if ( ! db.tables ) {
			rows.push( [ 'Database', '<span class="shcm-text-danger">No tables were exported</span>' ] );
		} else {
			rows.push( [ 'Database', 'Included &mdash; <strong>' + formatNumber( db.tables ) + ' tables, ' + formatNumber( db.rows ) + ' rows</strong>, ' +
				formatBytes( db.sql_bytes ) + ' of SQL (table prefix <code>' + escapeHtml( db.prefix || '' ) + '</code>)' ] );
		}

		var groups = report.entry_groups || {};
		var order = [ 'plugins', 'themes', 'mu-plugins', 'uploads', 'languages', 'other', 'core' ];
		var parts = [];
		var files = 0;
		Object.keys( groups ).sort( function ( a, b ) {
			var ai = order.indexOf( a );
			var bi = order.indexOf( b );
			return ( ai < 0 ? 99 : ai ) - ( bi < 0 ? 99 : bi );
		} ).forEach( function ( group ) {
			if ( group === 'meta' || group === 'database' ) {
				return;
			}
			files += Number( groups[ group ].entries ) || 0;
			parts.push( escapeHtml( group ) + ' ' + formatNumber( groups[ group ].entries ) + ' (' + formatBytes( groups[ group ].bytes ) + ')' );
		} );
		// Skipped by the scan (unreadable, over the size limit) plus skipped
		// while copying (vanished, unreadable or still changing).
		var skipped = ( report.files_exported && report.files_exported.skipped ? Number( report.files_exported.skipped ) : 0 ) +
			( report.file_totals && report.file_totals.skipped ? Number( report.file_totals.skipped ) : 0 );
		rows.push( [ 'Files', '<strong>' + formatNumber( files ) + '</strong>' + ( parts.length ? ': ' + parts.join( ', ' ) : '' ) +
			( skipped ? ' &mdash; <span class="shcm-text-danger">' + formatNumber( skipped ) + ' skipped (see the warnings and the log)</span>' : '' ) ] );

		if ( report.verify_mode === 'full' ) {
			rows.push( [ 'Verification', 'The archive was read back and every one of its ' + formatNumber( report.verified_entries ) + ' entries matched its checksum.' ] );
		} else if ( report.verify_mode === 'quick' ) {
			rows.push( [ 'Verification', 'Structure check only (quick mode in the settings); entry checksums were not read back.' ] );
		}

		var html = '<table class="widefat shcm-summary"><tbody>' + rows.map( function ( row ) {
			return '<tr><th scope="row">' + row[ 0 ] + '</th><td>' + row[ 1 ] + '</td></tr>';
		} ).join( '' ) + '</tbody></table>';

		html += '<details class="shcm-verify-help"><summary>How to check the downloaded file</summary>' +
			'<p>The downloaded file must be exactly <strong>' + formatNumber( report.archive_size ) + ' bytes</strong>' +
			( report.sha256 ? ' and its SHA-256 must be the one shown above' : '' ) + '. To compute it:</p>' +
			'<p>Windows (PowerShell): <code>Get-FileHash .\\' + escapeHtml( name ) + ' -Algorithm SHA256</code><br>' +
			'macOS: <code>shasum -a 256 ' + escapeHtml( name ) + '</code><br>' +
			'Linux: <code>sha256sum ' + escapeHtml( name ) + '</code></p>' +
			'<p>The import checks every entry again before it changes anything, so a damaged copy is always refused.</p>' +
			'</details>';

		if ( report.size_visible === false ) {
			html += '<div class="shcm-alert shcm-alert--warning">This server may hide the file size from browsers and download managers ' +
				'(they then say the size is unknown and cannot resume). The download is still complete when its size and SHA-256 match. ' +
				'See <em>System status</em> for the one-time server rule that fixes this.</div>';
		}

		return html;
	}

	/* --------------------------------------------------------------- export */

	function initExport() {
		var button = $( '#shcm-start-export' );
		if ( ! button ) {
			return;
		}

		var panel = $( '#shcm-progress-panel' );
		var view = new ProgressView( panel );
		var runner = new JobRunner( {
			view: view,
			onFinish: function ( job ) {
				$( '#shcm-cancel-job' ).disabled = true;
				if ( job.status === 'completed' ) {
					var report = job.report || {};
					var url = data.downloadUrl + '&archive=' + encodeURIComponent( report.archive || '' );
					var dbMissing = report.database_included === false || ! report.database || ! report.database.tables;
					showResult(
						'<div class="shcm-alert shcm-alert--' + ( dbMissing ? 'warning' : 'success' ) + '"><strong>Migration Ready.</strong> ' +
						escapeHtml( report.archive || '' ) +
						'</div>' +
						renderArchiveSummary( job ) +
						'<p><a class="button button-primary button-hero" href="' + url + '">Download .wpress</a> ' +
						'<a class="button" href="' + data.logUrl + '&job_id=' + encodeURIComponent( job.id ) + '">Download log</a></p>' +
						renderWarnings( job )
					);
				} else if ( job.status === 'failed' ) {
					showResult(
						'<div class="shcm-alert shcm-alert--danger"><strong>' +
						escapeHtml( job.error ? job.error.message : 'Migration failed.' ) + '</strong>' +
						( job.error && job.error.suggestion ? '<p>' + escapeHtml( job.error.suggestion ) + '</p>' : '' ) +
						'</div>' + renderWarnings( job ),
						'error'
					);
				}
			},
			onError: function ( error ) {
				showResult( '<div class="shcm-alert shcm-alert--danger">' + escapeHtml( error.message ) + '</div>', 'error' );
			}
		} );

		button.addEventListener( 'click', function () {
			button.disabled = true;
			$( '#shcm-export-hero' ).classList.add( 'is-running' );

			var password = ( $( '#shcm-export-password' ) || {} ).value || '';
			runner.start(
				'start_export',
				{
					name: ( $( '#shcm-export-name' ) || {} ).value || '',
					password: password,
					include_core: $( '#shcm-export-include-core' ).checked,
					include_foreign_tables: $( '#shcm-export-include-foreign' ).checked,
					exclusions: ( $( '#shcm-export-exclusions' ) || {} ).value || ''
				},
				password
			);
		} );

		var cancel = $( '#shcm-cancel-job' );
		if ( cancel ) {
			cancel.addEventListener( 'click', function () {
				if ( window.confirm( strings.confirmCancel ) ) {
					runner.cancel();
				}
			} );
		}

		maybeResume( runner, 'export' );
	}

	/* --------------------------------------------------------------- import */

	function initImport() {
		var dropzone = $( '#shcm-dropzone' );
		if ( ! dropzone ) {
			return;
		}

		var fileInput = $( '#shcm-file-input' );
		var selected = null;

		$( '#shcm-choose-file' ).addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			fileInput.click();
		} );
		dropzone.addEventListener( 'click', function () {
			fileInput.click();
		} );
		dropzone.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				fileInput.click();
			}
		} );
		fileInput.addEventListener( 'change', function () {
			if ( fileInput.files.length ) {
				uploadFile( fileInput.files[ 0 ] );
			}
		} );

		[ 'dragenter', 'dragover' ].forEach( function ( type ) {
			dropzone.addEventListener( type, function ( event ) {
				event.preventDefault();
				dropzone.classList.add( 'is-over' );
			} );
		} );
		[ 'dragleave', 'drop' ].forEach( function ( type ) {
			dropzone.addEventListener( type, function ( event ) {
				event.preventDefault();
				dropzone.classList.remove( 'is-over' );
			} );
		} );
		dropzone.addEventListener( 'drop', function ( event ) {
			if ( event.dataTransfer.files.length ) {
				uploadFile( event.dataTransfer.files[ 0 ] );
			}
		} );

		var uploadPanel = $( '#shcm-upload-progress' );
		var uploadBar = $( '[data-role="upload-bar"]', uploadPanel );
		var uploadLabel = $( '[data-role="upload-label"]', uploadPanel );
		var currentUpload = null;

		$( '#shcm-upload-abort' ).addEventListener( 'click', function () {
			if ( currentUpload ) {
				currentUpload.aborted = true;
				api( 'upload_abort', { upload_id: currentUpload.id } );
				uploadPanel.classList.add( 'shcm-hidden' );
				currentUpload = null;
			}
		} );

		function uploadFile( file ) {
			if ( ! /\.wpress$/i.test( file.name ) ) {
				showResult( '<div class="shcm-alert shcm-alert--danger">Only .wpress archives can be imported.</div>', 'error' );
				return;
			}

			uploadPanel.classList.remove( 'shcm-hidden' );
			uploadBar.style.width = '0%';
			uploadLabel.textContent = strings.uploading + ' 0%';

			api( 'upload_begin', { filename: file.name, size: file.size } ).then( function ( meta ) {
				currentUpload = { id: meta.id, aborted: false };
				return sendChunks( file, meta.id, 0 );
			} ).then( function () {
				if ( ! currentUpload || currentUpload.aborted ) {
					return null;
				}
				uploadLabel.textContent = strings.uploadComplete;
				return api( 'upload_finish', { upload_id: currentUpload.id } );
			} ).then( function ( archive ) {
				if ( ! archive ) {
					return;
				}
				currentUpload = null;
				addArchiveToList( archive );
			} ).catch( function ( error ) {
				uploadPanel.classList.add( 'shcm-hidden' );
				showResult( '<div class="shcm-alert shcm-alert--danger">' + escapeHtml( error.message ) + '</div>', 'error' );
			} );
		}

		function sendChunks( file, uploadId, offset ) {
			if ( currentUpload && currentUpload.aborted ) {
				return Promise.resolve();
			}
			if ( offset >= file.size ) {
				return Promise.resolve();
			}

			var size = Math.min( data.chunkSize || 5242880, file.size - offset );
			var chunk = file.slice( offset, offset + size );

			return api( 'upload_chunk', { upload_id: uploadId, offset: offset, chunk: chunk } )
				.then( function ( meta ) {
					var received = Number( meta.received ) || ( offset + size );
					var percent = Math.min( 100, ( received / file.size ) * 100 );
					uploadBar.style.width = percent + '%';
					uploadLabel.textContent = strings.uploading + ' ' + percent.toFixed( 1 ) + '% (' +
						formatBytes( received ) + ' / ' + formatBytes( file.size ) + ')';
					return sendChunks( file, uploadId, received );
				} )
				.catch( function ( error ) {
					// An out of sequence error tells us exactly where to resume.
					return api( 'upload_status', { upload_id: uploadId } ).then( function ( meta ) {
						if ( Number( meta.received ) !== offset ) {
							return sendChunks( file, uploadId, Number( meta.received ) );
						}
						throw error;
					} );
				} );
		}

		function addArchiveToList( archive ) {
			var list = $( '#shcm-archive-list' );
			var empty = $( '#shcm-no-archives' );
			if ( empty ) {
				empty.remove();
			}
			var label = document.createElement( 'label' );
			label.className = 'shcm-archive';
			label.setAttribute( 'data-archive', archive.name );
			label.innerHTML = '<input type="radio" name="shcm_archive" value="' + escapeHtml( archive.name ) + '">' +
				'<span class="shcm-archive__body"><span class="shcm-archive__name">' + escapeHtml( archive.name ) + '</span>' +
				'<span class="shcm-archive__meta">' + formatBytes( archive.size ) + ' &middot; just uploaded</span></span>';
			list.insertBefore( label, list.firstChild );
			label.querySelector( 'input' ).checked = true;
			onArchiveSelected( archive.name );
			bindArchiveInputs();
			uploadPanel.classList.add( 'shcm-hidden' );
		}

		function bindArchiveInputs() {
			$$( '#shcm-archive-list input[type="radio"]' ).forEach( function ( input ) {
				input.onchange = function () {
					onArchiveSelected( input.value );
				};
			} );
		}

		function onArchiveSelected( name ) {
			selected = name;
			$$( '.shcm-archive' ).forEach( function ( item ) {
				item.classList.toggle( 'is-selected', item.getAttribute( 'data-archive' ) === name );
			} );
			$( '#shcm-import-options' ).classList.remove( 'shcm-hidden' );
			loadDetails( name );
			updateStartState();
		}

		function loadDetails( name ) {
			var box = $( '#shcm-archive-details' );
			box.classList.remove( 'shcm-hidden' );
			box.innerHTML = '<p>Reading archive…</p>';

			api( 'archive_details', { archive: name, password: ( $( '#shcm-import-password' ) || {} ).value || '' } )
				.then( function ( result ) {
					var m = result.manifest;
					var a = result.archive;
					var contents = databaseLine( a ) + filesLine( a );
					var common = row( 'Archive', formatBytes( a.size ) + ' (' + formatNumber( a.size ) + ' bytes)' ) +
						( a.sha256 ? row( 'SHA-256', a.sha256 ) : '' ) +
						( a.complete ? '' : row( 'Status', 'incomplete: this archive has no footer and cannot be imported', true ) );
					if ( ! m ) {
						box.innerHTML = '<p><strong>Archive detected.</strong> ' + escapeHtml( a.name ) +
							( a.encrypted ? '<br>' + escapeHtml( strings.passwordNeeded ) : '' ) + '</p>' +
							'<dl>' + common + contents + '</dl>';
						return;
					}
					box.innerHTML = '<dl>' +
						row( 'Source', m.site && m.site.home ) +
						row( 'WordPress', m.wordpress && m.wordpress.version ) +
						row( 'PHP', m.php && m.php.version ) +
						row( 'Database server', m.database && m.database.server ) +
						row( 'Table prefix', m.wordpress && m.wordpress.table_prefix ) +
						contents +
						( a.complete ? row( 'Content size', formatBytes( m.files && m.files.size ) ) : '' ) +
						common +
						row( 'Active theme', m.active_theme && m.active_theme.stylesheet ) +
						row( 'Active plugins', formatNumber( ( m.active_plugins || [] ).length ) ) +
						'</dl>';
				} )
				.catch( function ( error ) {
					box.innerHTML = '<div class="shcm-alert shcm-alert--warning">' + escapeHtml( error.message ) + '</div>';
				} );
		}

		function row( label, value, danger ) {
			if ( value === undefined || value === null || value === '' ) {
				return '';
			}
			return '<dt>' + escapeHtml( label ) + '</dt><dd' + ( danger ? ' class="shcm-text-danger"' : '' ) + '>' + escapeHtml( value ) + '</dd>';
		}

		/**
		 * What the archive says about its database. The footer (readable even
		 * when the archive is encrypted) records what was actually written;
		 * archives from version 1.0.0 only record a table count there. An
		 * archive without a footer did not finish, so nothing is known about
		 * what it holds: the manifest's counts are only what was planned.
		 */
		function databaseLine( a ) {
			if ( ! a.complete ) {
				return row( 'Database', 'unknown: the archive is incomplete', true );
			}
			var db = a.database_contents;
			if ( db ) {
				if ( ! db.included || ! db.tables ) {
					return row( 'Database', 'NOT included: importing this archive leaves the database of this site unchanged', true );
				}
				return row( 'Database', 'Included: ' + formatNumber( db.tables ) + ' tables, ' + formatNumber( db.rows ) + ' rows, ' +
					formatBytes( db.sql_bytes ) + ' of SQL' );
			}
			return row( 'Tables', a.tables ? formatNumber( a.tables ) : 'not recorded', ! a.tables );
		}

		/**
		 * Files written to the archive, from its footer.
		 */
		function filesLine( a ) {
			if ( ! a.complete ) {
				return row( 'Files', 'unknown: the archive is incomplete', true );
			}
			var text = formatNumber( a.files || 0 );
			if ( a.files_skipped ) {
				return row( 'Files', text + ' (' + formatNumber( a.files_skipped ) + ' skipped during the export, see its log)', true );
			}
			return row( 'Files', text );
		}

		function updateStartState() {
			var button = $( '#shcm-start-import' );
			button.disabled = ! ( selected && $( '#shcm-confirm' ).checked );
		}

		bindArchiveInputs();
		$( '#shcm-confirm' ).addEventListener( 'change', updateStartState );

		var passwordField = $( '#shcm-import-password' );
		if ( passwordField ) {
			passwordField.addEventListener( 'change', function () {
				if ( selected ) {
					loadDetails( selected );
				}
			} );
		}

		var adopt = $( '#shcm-adopt-archive' );
		if ( adopt ) {
			adopt.addEventListener( 'click', function () {
				api( 'adopt_archive', { path: $( '#shcm-adopt-path' ).value } )
					.then( addArchiveToList )
					.catch( function ( error ) {
						showResult( '<div class="shcm-alert shcm-alert--danger">' + escapeHtml( error.message ) + '</div>', 'error' );
					} );
			} );
		}

		var panel = $( '#shcm-progress-panel' );
		var view = new ProgressView( panel );
		var runner = new JobRunner( {
			view: view,
			useEndpoint: true,
			onFinish: function ( job ) {
				$( '#shcm-cancel-job' ).disabled = true;
				if ( job.status === 'completed' ) {
					var report = job.report || {};
					var session = report.session && report.session.restored === false;
					showResult(
						'<div class="shcm-alert shcm-alert--success"><strong>Migration completed.</strong> ' +
						'This site now serves the content of ' + escapeHtml( ( report.summary && report.summary.source ) || '' ) + '.</div>' +
						renderChecks( report.verification ) +
						( report.urls ? '<h3>URL replacement</h3>' + renderReplaceReport( report.urls ) : '' ) +
						renderWarnings( job ) +
						( session ? '<div class="shcm-alert shcm-alert--warning">Sign in again with the credentials from the source site.</div>' : '' ) +
						'<p><a class="button button-primary" href="' + escapeHtml( data.homeUrl ) + '/wp-admin/">Open the restored site</a> ' +
						'<a class="button" href="' + data.logUrl + '&job_id=' + encodeURIComponent( job.id ) + '">Download log</a></p>'
					);
				} else if ( job.status === 'failed' ) {
					var rollback = ( job.report && job.report.rollback ) || {};
					showResult(
						'<div class="shcm-alert shcm-alert--danger"><strong>' +
						escapeHtml( job.error ? job.error.message : 'Restore failed.' ) + '</strong>' +
						( job.error && job.error.suggestion ? '<p>' + escapeHtml( job.error.suggestion ) + '</p>' : '' ) +
						'</div>' + renderWarnings( job ) +
						( rollback.available
							? '<div class="shcm-alert shcm-alert--warning"><strong>A rollback point is available.</strong>' +
								' <p>' + escapeHtml( rollback.name ) + ' holds this site\'s database as it was before the restore started.</p>' +
								' <button type="button" class="button button-primary" data-rollback="' + escapeHtml( job.id ) + '">Attempt rollback</button></div>'
							: '' ) +
						'<p><a class="button" href="' + data.logUrl + '&job_id=' + encodeURIComponent( job.id ) + '">Download log</a></p>',
						'error'
					);
					bindRollback( runner );
				}
			},
			onError: function ( error ) {
				showResult( '<div class="shcm-alert shcm-alert--danger">' + escapeHtml( error.message ) + '</div>', 'error' );
			}
		} );

		$( '#shcm-start-import' ).addEventListener( 'click', function () {
			if ( ! window.confirm( strings.confirmImport ) ) {
				return;
			}
			this.disabled = true;
			$( '#shcm-import-options' ).classList.add( 'shcm-hidden' );

			var password = ( $( '#shcm-import-password' ) || {} ).value || '';
			runner.start(
				'start_import',
				{
					archive: selected,
					password: password,
					confirmed: true,
					destination_url: $( '#shcm-destination-url' ).value,
					import_mode: $( '#shcm-import-mode' ).value,
					verify_archive: $( '#shcm-verify-archive' ).checked,
					create_rollback_point: $( '#shcm-rollback' ).checked,
					replace_urls: $( '#shcm-replace-urls' ).checked,
					replace_bare_domain: $( '#shcm-replace-bare' ).checked,
					skip_core: $( '#shcm-skip-core' ).checked,
					delete_archive_after_import: $( '#shcm-delete-after' ).checked
				},
				password
			);
		} );

		$( '#shcm-cancel-job' ).addEventListener( 'click', function () {
			if ( window.confirm( strings.confirmCancel ) ) {
				runner.cancel();
			}
		} );

		maybeResume( runner, 'import' );
	}

	/**
	 * Wire the "attempt rollback" button a failed restore offers.
	 *
	 * @param {JobRunner} runner The runner that reported the failure.
	 */
	function bindRollback( runner ) {
		var button = document.querySelector( '[data-rollback]' );
		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			if ( ! window.confirm( 'Restore the database snapshot taken before this migration?' ) ) {
				return;
			}
			button.disabled = true;
			$( '#shcm-result-panel' ).classList.add( 'shcm-hidden' );
			$( '#shcm-cancel-job' ).disabled = false;
			runner.start( 'start_rollback', { job_id: button.getAttribute( 'data-rollback' ) }, '' );
		} );
	}

	/* -------------------------------------------------------------- backups */

	function initBackups() {
		var table = $( '#shcm-backups-table' );
		if ( table ) {
			table.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( 'button[data-action]' );
				if ( ! button ) {
					return;
				}
				var row = button.closest( 'tr' );
				var archive = row.getAttribute( 'data-archive' );

				if ( button.getAttribute( 'data-action' ) === 'delete' ) {
					if ( ! window.confirm( strings.confirmDelete ) ) {
						return;
					}
					api( 'delete_archive', { archive: archive } ).then( function () {
						row.remove();
					} ).catch( function ( error ) {
						window.alert( error.message );
					} );
					return;
				}

				if ( button.getAttribute( 'data-action' ) === 'verify' ) {
					verifyArchive( archive, row, button );
				}
			} );
		}

		$$( 'button[data-action="delete-job"]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var row = button.closest( 'tr' );
				api( 'delete_job', { job_id: row.getAttribute( 'data-job' ) } ).then( function () {
					row.remove();
				} );
			} );
		} );
	}

	function verifyArchive( archive, row, button ) {
		var output = $( '[data-role="verify-result"]', row );
		var password = '';
		button.disabled = true;
		output.className = 'shcm-verify-result';
		output.textContent = strings.verifying + '…';

		function step( state ) {
			return api( 'verify_archive', { archive: archive, state: state, password: password } )
				.then( function ( result ) {
					if ( ! result.done ) {
						output.textContent = strings.verifying + ' ' +
							formatNumber( result.checked ) + ' / ' + formatNumber( result.expected );
						return step( result.state );
					}
					button.disabled = false;
					if ( result.ok ) {
						output.className = 'shcm-verify-result is-ok';
						output.textContent = strings.verified + ' (' + formatNumber( result.checked ) + ' entries, ' +
							formatBytes( result.bytes ) + ' of uncompressed content)';
					} else {
						output.className = 'shcm-verify-result is-fail';
						output.textContent = result.errors.join( ' ' );
					}
					return result;
				} );
		}

		step( {} ).catch( function ( error ) {
			button.disabled = false;
			output.className = 'shcm-verify-result is-fail';
			if ( /encrypted/i.test( error.message ) ) {
				password = window.prompt( strings.passwordNeeded ) || '';
				if ( password ) {
					output.textContent = strings.verifying + '…';
					step( {} ).catch( function ( inner ) {
						output.textContent = inner.message;
					} );
					return;
				}
			}
			output.textContent = error.message;
		} );
	}

	/* ---------------------------------------------------------------- tools */

	function initTools() {
		var preview = $( '#shcm-preview-replace' );
		if ( ! preview ) {
			return;
		}

		var panel = $( '#shcm-progress-panel' );
		var view = new ProgressView( panel );

		function run( dryRun ) {
			var runner = new JobRunner( {
				view: view,
				onFinish: function ( job ) {
					var report = ( job.report && job.report.replace ) || null;
					if ( job.status === 'failed' ) {
						showResult( '<div class="shcm-alert shcm-alert--danger">' +
							escapeHtml( job.error ? job.error.message : 'Failed' ) + '</div>', 'error' );
						return;
					}
					showResult(
						'<div class="shcm-alert shcm-alert--' + ( dryRun ? 'info' : 'success' ) + '">' +
						( dryRun ? '<strong>Preview only.</strong> Nothing was written.' : '<strong>Replacement finished.</strong>' ) +
						'</div>' + renderReplaceReport( report )
					);
				},
				onError: function ( error ) {
					showResult( '<div class="shcm-alert shcm-alert--danger">' + escapeHtml( error.message ) + '</div>', 'error' );
				}
			} );

			runner.start( 'start_replace', {
				search: $( '#shcm-search' ).value,
				replace: $( '#shcm-replace' ).value,
				dry_run: dryRun
			}, '' );

			var cancel = $( '#shcm-cancel-job' );
			if ( cancel ) {
				cancel.onclick = function () {
					runner.cancel();
				};
			}
		}

		preview.addEventListener( 'click', function () {
			run( true );
		} );
		$( '#shcm-run-replace' ).addEventListener( 'click', function () {
			if ( window.confirm( 'Run the replacement now? Take a backup first if you have not already.' ) ) {
				run( false );
			}
		} );
	}

	/* ------------------------------------------------------------- settings */

	function initSettings() {
		var form = $( '#shcm-settings-form' );
		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var feedback = $( '#shcm-settings-feedback' );
			var payload = {};

			$$( 'input, select, textarea', form ).forEach( function ( field ) {
				if ( ! field.name ) {
					return;
				}
				if ( field.type === 'checkbox' ) {
					payload[ field.name ] = field.checked;
				} else {
					payload[ field.name ] = field.value;
				}
			} );

			feedback.className = 'shcm-save-feedback';
			feedback.textContent = '…';

			api( 'save_settings', { settings: payload } ).then( function () {
				feedback.textContent = 'Saved.';
				setTimeout( function () {
					feedback.textContent = '';
				}, 3000 );
			} ).catch( function ( error ) {
				feedback.className = 'shcm-save-feedback is-error';
				feedback.textContent = error.message;
			} );
		} );
	}

	/* --------------------------------------------------------------- resume */

	function maybeResume( runner, type ) {
		var params = new URLSearchParams( window.location.search );
		var jobId = params.get( 'job' );

		if ( jobId ) {
			runner.resume( jobId, '' );
			return;
		}

		api( 'resumable', {} ).then( function ( result ) {
			var job = result.job;
			if ( ! job || job.type !== type ) {
				return;
			}
			var panel = $( '#shcm-progress-panel' );
			panel.classList.remove( 'shcm-hidden' );
			new ProgressView( panel ).render( job );

			var message = 'An interrupted migration was detected (' + job.id + ', ' +
				Number( job.progress ).toFixed( 1 ) + '%).';
			var box = document.createElement( 'div' );
			box.className = 'shcm-alert shcm-alert--info';
			box.innerHTML = escapeHtml( message ) +
				' <button type="button" class="button button-small" data-resume>Resume Migration</button>' +
				' <button type="button" class="button button-small" data-cancel>Cancel Migration</button>';
			panel.parentNode.insertBefore( box, panel );

			box.querySelector( '[data-resume]' ).addEventListener( 'click', function () {
				var password = '';
				if ( job.params && job.params.encrypted ) {
					password = window.prompt( strings.passwordNeeded ) || '';
				}
				box.remove();
				runner.resume( job.id, password );
			} );
			box.querySelector( '[data-cancel]' ).addEventListener( 'click', function () {
				api( 'cancel', { job_id: job.id } ).then( function () {
					box.remove();
					panel.classList.add( 'shcm-hidden' );
				} );
			} );
		} ).catch( function () {
			/* A missing resumable job is not an error worth showing. */
		} );
	}

	/* ----------------------------------------------------------------- boot */

	document.addEventListener( 'DOMContentLoaded', function () {
		initExport();
		initImport();
		initBackups();
		initTools();
		initSettings();
	} );
}() );
