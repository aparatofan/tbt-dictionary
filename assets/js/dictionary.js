/**
 * TBT Dictionary — the [tbt_dictionary] page.
 *
 * Built from docs/prototypes/dictionary.html. The page loads once
 * (GET /state); search and filtering are client-side. Every change goes to
 * tbt-dictionary/v1, which takes the user from the session. Plain JS, no
 * build step, no dependencies.
 *
 * Typed text lives in state (st.quick, st.modal, st.edit), so a background
 * re-render — a word's AI fill-in arriving — never loses it.
 */
( function () {
	'use strict';

	const CFG = window.TBTDIC_CFG || {};
	const root = document.querySelector( '[data-tbtdic-app]' );
	if ( ! root || ! CFG.rest ) {
		return;
	}

	const LEARNT = Number( CFG.learnt ) > 0 ? Number( CFG.learnt ) : 10;
	// One tab in 0.1.0, so no strip; 0.2.0 adds Games, 0.3.0 Import.
	const TABS = [ [ 'groups', 'Groups' ] ];
	const ID = ( s ) => 'tbtdic-' + s;

	/* ---------- Helpers ---------- */

	const esc = ( s ) => String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	const fold = ( s ) => String( s == null ? '' : s ).toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).replace( /ł/g, 'l' );
	const plural = ( n, one, many ) => n + ' ' + ( n === 1 ? one : ( many || one + 's' ) );
	const wpct = ( w ) => Math.max( 0, Math.min( 100, ( Number( w.progress ) || 0 ) / LEARNT * 100 ) );
	const learned = ( w ) => ( Number( w.progress ) || 0 ) >= LEARNT;
	const fmtPct = ( p ) => String( Math.round( p * 10 ) / 10 ) + '%';
	const byId = ( id ) => document.getElementById( ID( id ) );

	const IC = {
		dots: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>',
		say: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/></svg>',
		search: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>',
		go: '<svg class="tbtdic-gcard__go" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
	};

	/* ---------- State ---------- */

	const st = {
		loaded: false,
		loadError: '',
		tab: 'groups',
		groups: [],
		words: [],
		view: 'list',
		g: null,
		q: '',
		lastG: null,
		menu: null,
		edit: null,
		flash: null,
		modal: null,
		quick: { w: '', tr: '', err: '', busy: false },
	};

	const group = ( id ) => st.groups.find( ( g ) => g.id === id ) || null;
	const word = ( id ) => st.words.find( ( w ) => w.id === id ) || null;
	const inGroup = ( gid ) => st.words.filter( ( w ) => w.group_id === gid );
	const groupByName = ( t ) => {
		const k = fold( String( t ).trim() );
		return k ? st.groups.find( ( g ) => fold( g.name.trim() ) === k ) || null : null;
	};
	function gpct( gid ) {
		const ws = inGroup( gid );
		return ws.length ? ws.reduce( ( t, w ) => t + wpct( w ), 0 ) / ws.length : 0;
	}

	/* ---------- REST ---------- */

	let noncePromise = null;

	function refreshNonce() {
		if ( ! noncePromise ) {
			noncePromise = fetch( CFG.ajax + ( CFG.ajax.indexOf( '?' ) < 0 ? '?' : '&' ) + 'action=tbtdic_nonce', {
				credentials: 'same-origin',
				cache: 'no-store',
			} ).then( ( res ) => res.json() ).then( ( data ) => {
				if ( ! data || ! data.success ) {
					throw new Error( 'nonce' );
				}
				CFG.nonce = data.data.nonce;
			} ).finally( () => {
				noncePromise = null;
			} );
		}
		return noncePromise;
	}

	/**
	 * Call a route. PATCH and DELETE go as POST with X-HTTP-Method-Override,
	 * which WordPress honours and which hosts that block those verbs let
	 * through. Rejects with { code, message, status, data }. An expired nonce
	 * is refreshed and the call retried once.
	 */
	function api( method, path, body, retried ) {
		const opts = {
			method: method === 'GET' ? 'GET' : 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'X-WP-Nonce': CFG.nonce || '' },
		};
		if ( method === 'PATCH' || method === 'DELETE' ) {
			opts.headers[ 'X-HTTP-Method-Override' ] = method;
		}
		if ( method !== 'GET' ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body || {} );
		}
		return fetch( CFG.rest + path, opts ).then( ( res ) => res.json().catch( () => ( {} ) ).then( ( data ) => {
			if ( res.ok ) {
				return data;
			}
			if ( data && data.code === 'rest_cookie_invalid_nonce' && ! retried ) {
				return refreshNonce().then( () => api( method, path, body, true ) );
			}
			throw {
				code: ( data && data.code ) || 'http_' + res.status,
				message: ( data && data.message ) || '',
				status: res.status,
				data: ( data && data.data ) || {},
			};
		} ), () => {
			throw { code: 'network', message: '', status: 0, data: {} };
		} );
	}

	function errorText( err, fallback ) {
		if ( err && err.status === 401 ) {
			return 'You have been logged out. Reload the page and log in again.';
		}
		if ( err && err.status === 0 ) {
			return 'Could not reach the site. Check your connection and try again.';
		}
		return ( err && err.message ) || fallback || 'Something went wrong. Try again.';
	}

	/* ---------- Store updates ---------- */

	function putGroup( g ) {
		if ( ! g ) {
			return;
		}
		const i = st.groups.findIndex( ( x ) => x.id === g.id );
		if ( i < 0 ) {
			st.groups.push( g );
		} else {
			st.groups[ i ] = g;
		}
	}

	function putWord( w ) {
		if ( ! w ) {
			return;
		}
		const i = st.words.findIndex( ( x ) => x.id === w.id );
		if ( i < 0 ) {
			st.words.unshift( w );
		} else {
			st.words[ i ] = w;
		}
	}

	/* ---------- Toast ---------- */

	let toastTimer = null;
	function toast( html ) {
		const el = byId( 'toast' );
		if ( ! el ) {
			return;
		}
		el.querySelector( '[data-toast-text]' ).innerHTML = html;
		el.classList.add( 'tbtdic-toast--on' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( () => el.classList.remove( 'tbtdic-toast--on' ), 2400 );
	}

	/* ---------- Audio (TBT Flashcards' TTS proxy) ---------- */

	const AUDIO = CFG.audio && CFG.audio.url && CFG.audio.action && CFG.audio.nonce && window.fetch && window.URL && URL.createObjectURL ? CFG.audio : null;
	const audioUrls = new Map();
	let audioEl = null;

	function fetchAudio( text ) {
		if ( audioUrls.has( text ) ) {
			return Promise.resolve( audioUrls.get( text ) );
		}
		const body = new URLSearchParams();
		body.append( 'action', AUDIO.action );
		body.append( 'nonce', AUDIO.nonce );
		body.append( 'text', text );
		return fetch( AUDIO.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( ( res ) => {
			const type = res.headers.get( 'Content-Type' ) || '';
			if ( ! res.ok || type.indexOf( 'audio/' ) !== 0 ) {
				throw new Error( 'TTS proxy returned ' + res.status + ' ' + type );
			}
			return res.blob();
		} ).then( ( blob ) => {
			const url = URL.createObjectURL( blob );
			audioUrls.set( text, url );
			return url;
		} );
	}

	function speak( btn, w ) {
		if ( ! AUDIO || ! w ) {
			return;
		}
		btn.setAttribute( 'aria-busy', 'true' );
		fetchAudio( w.speak || w.term ).then( ( url ) => {
			if ( ! audioEl ) {
				audioEl = new Audio();
			}
			audioEl.pause();
			audioEl.src = url;
			const p = audioEl.play();
			return p && p.catch ? p.catch( () => {} ) : null;
		} ).catch( ( err ) => {
			// eslint-disable-next-line no-console
			console.warn( 'TBT Dictionary audio:', err );
		} ).finally( () => {
			btn.removeAttribute( 'aria-busy' );
		} );
	}

	/* ---------- Views ---------- */

	function prog( p ) {
		const full = p >= 100;
		return '<span class="tbtdic-prog"><span class="tbtdic-meter' + ( full ? ' tbtdic-meter--full' : '' ) + '" aria-hidden="true"><i style="width:' + p + '%"></i></span>' +
			'<span class="tbtdic-prog__v">' + ( full ? 'Learned' : fmtPct( p ) ) + '</span></span>';
	}

	function abar() {
		return '<div class="tbtdic-abar"><h2 class="tbtdic-abar__title">My dictionary</h2>' +
			'<div class="tbtdic-search">' + IC.search + '<label class="tbtdic-sr" for="' + ID( 'q' ) + '">Search your words</label>' +
			'<input id="' + ID( 'q' ) + '" type="text" placeholder="Search your words…" value="' + esc( st.q ) + '" autocomplete="off">' +
			( st.q ? '<button class="tbtdic-search__x" type="button" data-act="clear" aria-label="Clear search">×</button>' : '' ) + '</div>' +
			'<span class="tbtdic-line" aria-hidden="true"></span>' +
			'<button class="tbtdic-cta tbtdic-cta--ghost" type="button" data-act="new-group">Add a group</button>' +
			'<button class="tbtdic-cta" type="button" data-act="new-word">Add a word</button></div>';
	}

	function tabs() {
		if ( TABS.length < 2 ) {
			return '';
		}
		return '<div class="tbtdic-tabs" role="tablist">' + TABS.map( ( t ) => '<button type="button" role="tab" data-tab="' + t[ 0 ] + '" aria-selected="' + ( st.tab === t[ 0 ] && ! st.q ) + '">' + esc( t[ 1 ] ) + '</button>' ).join( '' ) + '</div>';
	}

	function stats() {
		const l = st.words.filter( learned ).length;
		return '<div class="tbtdic-stats">' +
			'<div class="tbtdic-stat"><div class="tbtdic-stat__v">' + st.words.length + '</div><div class="tbtdic-stat__k">words in your dictionary</div></div>' +
			'<div class="tbtdic-stat"><div class="tbtdic-stat__v">' + l + '</div><div class="tbtdic-stat__k">words learned</div></div>' +
			'<div class="tbtdic-stat"><div class="tbtdic-stat__v">' + st.groups.length + '</div><div class="tbtdic-stat__k">groups</div></div></div>';
	}

	function groupMenu( key, g ) {
		return '<div class="tbtdic-menuwrap"><button class="tbtdic-icon" type="button" data-menu="' + key + '" aria-label="More for ' + esc( g.name ) + '" aria-haspopup="true" aria-expanded="' + ( st.menu === key ) + '">' + IC.dots + '</button>' +
			( st.menu === key ? '<div class="tbtdic-menu" role="menu">' +
				'<button type="button" role="menuitem" data-act="rename" data-id="' + g.id + '">Rename</button>' +
				'<button type="button" role="menuitem" data-act="reset-group" data-id="' + g.id + '">Reset progress</button>' +
				'<button type="button" role="menuitem" class="tbtdic-menu__no" data-act="del-group" data-id="' + g.id + '">Delete group</button></div>' : '' ) +
			'</div>';
	}

	function groupsList() {
		// Oldest first.
		const gs = st.groups.slice().sort( ( a, b ) => ( a.created_at < b.created_at ? -1 : a.created_at > b.created_at ? 1 : a.id - b.id ) );
		if ( ! gs.length ) {
			return stats() + '<section class="tbtdic-stage"><h2 class="tbtdic-stage__title">Start your dictionary</h2>' +
				'<p class="tbtdic-muted">Make a group for a topic (e.g. “Work” or “Idioms”) and add the new words you meet in lessons.</p>' +
				'<div><button class="tbtdic-btn" type="button" data-act="new-group">Add a group</button></div></section>';
		}
		return stats() + '<div class="tbtdic-cards">' + gs.map( ( g ) => {
			const ws = inGroup( g.id );
			const l = ws.filter( learned ).length;
			return '<article class="tbtdic-gcard">' +
				'<button class="tbtdic-gcard__open" type="button" data-open="' + g.id + '"><h3 class="tbtdic-gcard__name">' + esc( g.name ) + IC.go + '</h3>' +
				'<span class="tbtdic-gcard__meta">' + plural( ws.length, 'word' ) + ' · ' + l + ' learned</span></button>' +
				'<div class="tbtdic-gcard__foot">' + prog( gpct( g.id ) ) + groupMenu( 'g' + g.id, g ) + '</div></article>';
		} ).join( '' ) + '</div>';
	}

	function editRow( w ) {
		const e = st.edit;
		return '<div class="tbtdic-wrow"><div class="tbtdic-wedit">' +
			'<div class="tbtdic-fld"><label for="' + ID( 'e_w' ) + '">Word</label><input class="tbtdic-inp tbtdic-inp--sm" id="' + ID( 'e_w' ) + '" data-edit="term" value="' + esc( e.term ) + '" maxlength="120"></div>' +
			'<div class="tbtdic-fld"><label for="' + ID( 'e_tr' ) + '">Translation</label><input class="tbtdic-inp tbtdic-inp--sm" id="' + ID( 'e_tr' ) + '" data-edit="translation" value="' + esc( e.translation ) + '" maxlength="190"></div>' +
			'<div class="tbtdic-fld"><label for="' + ID( 'e_ph' ) + '">Phonetic</label><input class="tbtdic-inp tbtdic-inp--sm tbtdic-inp--mono" id="' + ID( 'e_ph' ) + '" data-edit="phonetic" value="' + esc( e.phonetic ) + '" maxlength="80"></div>' +
			'<div class="tbtdic-fld"><label for="' + ID( 'e_ex' ) + '">Example</label><input class="tbtdic-inp tbtdic-inp--sm" id="' + ID( 'e_ex' ) + '" data-edit="example" value="' + esc( e.example ) + '" maxlength="300"></div>' +
			( e.err ? '<p class="tbtdic-err" role="alert">' + esc( e.err ) + '</p>' : '' ) +
			'<div class="tbtdic-acts"><button class="tbtdic-btn" type="button" data-act="save-edit" data-id="' + w.id + '"' + ( e.busy ? ' disabled' : '' ) + '>Save</button>' +
			'<button class="tbtdic-btn2" type="button" data-act="cancel-edit">Cancel</button></div>' +
			'</div><span></span><span></span></div>';
	}

	function wordRow( w, showGroup ) {
		if ( st.edit && st.edit.id === w.id ) {
			return editRow( w );
		}
		const pending = w.ai_status === 'pending';
		const g = showGroup ? group( w.group_id ) : null;
		let tr = '';
		if ( w.translation ) {
			tr = esc( w.translation );
		} else if ( ! pending ) {
			tr = '<span class="tbtdic-missing">No translation yet · <button class="tbtdic-lnk" type="button" data-act="edit" data-id="' + w.id + '">add one</button></span>';
		}
		let extra = '';
		if ( pending ) {
			extra = '<span class="tbtdic-shim"><i aria-hidden="true"></i>Adding phonetics, an example and audio…</span>';
		} else if ( w.example ) {
			extra = '<div class="tbtdic-wex">“' + esc( w.example ) + '”</div>';
		}
		const key = 'w' + w.id;
		return '<div class="tbtdic-wrow' + ( st.flash === w.id ? ' tbtdic-wrow--new' : '' ) + '">' +
			'<div class="tbtdic-wmain"><div class="tbtdic-wline"><span class="tbtdic-wterm">' + esc( w.term ) + '</span>' +
				( w.phonetic ? '<span class="tbtdic-ipa">' + esc( w.phonetic ) + '</span>' : '' ) +
				( AUDIO && ! pending ? '<button class="tbtdic-say" type="button" data-say="' + w.id + '" aria-label="Listen to ' + esc( w.term ) + '">' + IC.say + '</button>' : '' ) +
				( g ? '<span class="tbtdic-tag tbtdic-ingrp">' + esc( g.name ) + '</span>' : '' ) + '</div>' +
				( tr ? '<div class="tbtdic-wtr">' + tr + '</div>' : '' ) + extra + '</div>' +
			prog( wpct( w ) ) +
			'<div class="tbtdic-menuwrap"><button class="tbtdic-icon" type="button" data-menu="' + key + '" aria-label="More for ' + esc( w.term ) + '" aria-haspopup="true" aria-expanded="' + ( st.menu === key ) + '">' + IC.dots + '</button>' +
				( st.menu === key ? '<div class="tbtdic-menu" role="menu">' +
					'<button type="button" role="menuitem" data-act="edit" data-id="' + w.id + '">Edit</button>' +
					'<button type="button" role="menuitem" data-act="move" data-id="' + w.id + '">Move to another group</button>' +
					'<button type="button" role="menuitem" data-act="reset-word" data-id="' + w.id + '">Reset progress</button>' +
					'<button type="button" role="menuitem" class="tbtdic-menu__no" data-act="del-word" data-id="' + w.id + '">Delete</button></div>' : '' ) +
			'</div></div>';
	}

	function newestFirst( a, b ) {
		return a.created_at < b.created_at ? 1 : a.created_at > b.created_at ? -1 : b.id - a.id;
	}

	function groupView() {
		const g = group( st.g );
		if ( ! g ) {
			st.view = 'list';
			return groupsList();
		}
		const ws = inGroup( g.id ).sort( newestFirst );
		const l = ws.filter( learned ).length;
		const q = st.quick;
		return '<button class="tbtdic-back" type="button" data-act="home">← All groups</button>' +
			'<div class="tbtdic-ghead"><div class="tbtdic-ghead__t"><h2 class="tbtdic-ghead__title">' + esc( g.name ) + '</h2>' +
				'<span class="tbtdic-summary">' + plural( ws.length, 'word' ) + ' · ' + l + ' learned</span></div>' +
				prog( gpct( g.id ) ) + groupMenu( 'h' + g.id, g ) + '</div>' +
			'<section class="tbtdic-stage">' +
				'<div class="tbtdic-adder">' +
					'<div class="tbtdic-fld"><label for="' + ID( 'nw' ) + '">English word or phrase</label><input class="tbtdic-inp" id="' + ID( 'nw' ) + '" data-quick="w" autocomplete="off" maxlength="120" placeholder="e.g. resilient" value="' + esc( q.w ) + '"></div>' +
					'<div class="tbtdic-fld"><label for="' + ID( 'ntr' ) + '">Translation <span>(optional, AI fills it in)</span></label><input class="tbtdic-inp" id="' + ID( 'ntr' ) + '" data-quick="tr" autocomplete="off" maxlength="190" placeholder="leave empty or type your own" value="' + esc( q.tr ) + '"></div>' +
					'<button class="tbtdic-btn tbtdic-btn--tall" type="button" data-act="add-word"' + ( q.busy ? ' disabled' : '' ) + '>Add word</button>' +
				'</div>' +
				// Under the word field, outside the grid so the button stays level with the inputs.
				( q.err ? '<p class="tbtdic-err" role="alert">' + esc( q.err ) + '</p>' : '' ) +
				'<p class="tbtdic-hint"><kbd class="tbtdic-kbd">Enter</kbd> moves to the translation, <kbd class="tbtdic-kbd">Enter</kbd> again saves.</p>' +
				'<div class="tbtdic-words">' + ( ws.length ? ws.map( ( w ) => wordRow( w, false ) ).join( '' ) : '<p class="tbtdic-empty">No words yet. Add the first one above.</p>' ) + '</div>' +
			'</section>';
	}

	function searchView() {
		const raw = st.q.trim();
		const q = fold( raw );
		const ws = st.words.filter( ( w ) => fold( w.term ).includes( q ) || fold( w.translation ).includes( q ) || fold( w.example ).includes( q ) )
			.sort( ( a, b ) => a.term.localeCompare( b.term ) );
		return '<p class="tbtdic-summary">' + plural( ws.length, 'word' ) + ' match “' + esc( raw ) + '” · <button class="tbtdic-lnk" type="button" data-act="clear">Clear search</button></p>' +
			'<section class="tbtdic-stage"><div class="tbtdic-words">' + ( ws.length ? ws.map( ( w ) => wordRow( w, true ) ).join( '' ) : '<p class="tbtdic-empty">No words match your search.</p>' ) + '</div></section>';
	}

	/* ---------- Dialogs ---------- */

	function pickerHint() {
		const M = st.modal;
		const t = M ? String( M.gname || '' ).trim() : '';
		if ( ! t ) {
			return 'Type a group name or pick one from the list.';
		}
		const g = groupByName( t );
		if ( g ) {
			return plural( inGroup( g.id ).length, 'word' ) + ' in this group.';
		}
		return '<b>New group:</b> “' + esc( t ) + '” will be created when you add the word.';
	}

	function acts( cancelLabel, act, label, opts ) {
		const o = opts || {};
		return '<div class="tbtdic-acts"><button class="tbtdic-btn2" type="button" data-act="close">' + cancelLabel + '</button>' +
			'<button class="tbtdic-btn' + ( o.danger ? ' tbtdic-btn--danger' : '' ) + '" type="button" data-act="' + act + '"' + ( o.id ? ' data-id="' + o.id + '"' : '' ) + ( st.modal && st.modal.busy ? ' disabled' : '' ) + '>' + label + '</button></div>';
	}

	function errLine() {
		return st.modal && st.modal.err ? '<p class="tbtdic-err" role="alert">' + esc( st.modal.err ) + '</p>' : '';
	}

	function modalHTML() {
		const M = st.modal;
		if ( ! M ) {
			return '';
		}
		let h = '';
		if ( M.t === 'new-group' || M.t === 'rename' ) {
			const g = M.t === 'rename' ? group( M.id ) : null;
			h = '<h2 class="tbtdic-modal__title" id="' + ID( 'mt' ) + '">' + ( g ? 'Rename group' : 'New group' ) + '</h2>' +
				'<div class="tbtdic-fld"><label for="' + ID( 'mg' ) + '">Group name</label><input class="tbtdic-inp" id="' + ID( 'mg' ) + '" data-modal="name" maxlength="60" autocomplete="off" value="' + esc( M.name ) + '" placeholder="e.g. Work, Travel, Idioms">' + errLine() + '</div>' +
				acts( 'Cancel', g ? 'save-rename' : 'save-group', g ? 'Save' : 'Add group', { id: g ? g.id : 0 } );
		} else if ( M.t === 'add-word' ) {
			const names = st.groups.map( ( g ) => g.name ).sort( ( a, b ) => a.localeCompare( b ) );
			h = '<h2 class="tbtdic-modal__title" id="' + ID( 'mt' ) + '">Add a word</h2>' +
				'<div class="tbtdic-fld"><label for="' + ID( 'mgrp' ) + '">Group</label>' +
					'<input class="tbtdic-inp" id="' + ID( 'mgrp' ) + '" data-modal="gname" list="' + ID( 'mgrp-list' ) + '" maxlength="60" autocomplete="off" value="' + esc( M.gname ) + '" placeholder="Type or pick a group">' +
					'<datalist id="' + ID( 'mgrp-list' ) + '">' + names.map( ( n ) => '<option value="' + esc( n ) + '"></option>' ).join( '' ) + '</datalist>' +
					'<span class="tbtdic-hint" id="' + ID( 'mgrp-hint' ) + '" aria-live="polite">' + pickerHint() + '</span></div>' +
				'<div class="tbtdic-fld"><label for="' + ID( 'mw' ) + '">English word or phrase</label><input class="tbtdic-inp" id="' + ID( 'mw' ) + '" data-modal="w" maxlength="120" autocomplete="off" placeholder="e.g. resilient" value="' + esc( M.w ) + '">' + errLine() + '</div>' +
				'<div class="tbtdic-fld"><label for="' + ID( 'mtr' ) + '">Translation <span>(optional, AI fills it in)</span></label><input class="tbtdic-inp" id="' + ID( 'mtr' ) + '" data-modal="tr" maxlength="190" autocomplete="off" placeholder="leave empty or type your own" value="' + esc( M.tr ) + '"></div>' +
				'<p class="tbtdic-hint"><kbd class="tbtdic-kbd">Enter</kbd> moves to the next field and saves on the last one. The window stays open for the next word.</p>' +
				( M.added.length ? '<div class="tbtdic-added">' + M.added.slice( 0, 4 ).map( ( a ) => '<span>✓ <b>' + esc( a.w ) + '</b> → ' + esc( a.g ) + '</span>' ).join( '' ) +
					( M.added.length > 4 ? '<span class="tbtdic-muted">and ' + ( M.added.length - 4 ) + ' more</span>' : '' ) + '</div>' : '' ) +
				acts( M.added.length ? 'Done' : 'Cancel', 'modal-add', 'Add word' );
		} else if ( M.t === 'del-group' ) {
			const g = group( M.id );
			const n = g ? inGroup( g.id ).length : 0;
			h = '<h2 class="tbtdic-modal__title" id="' + ID( 'mt' ) + '">Delete “' + esc( g ? g.name : '' ) + '”?</h2>' +
				'<p class="tbtdic-muted">' + ( n ? 'Its ' + plural( n, 'word' ) + ' will be deleted too. ' : '' ) + 'This can’t be undone. Points you earned stay.</p>' + errLine() +
				acts( 'Cancel', 'yes-del-group', 'Delete', { id: M.id, danger: true } );
		} else if ( M.t === 'del-word' ) {
			const w = word( M.id );
			h = '<h2 class="tbtdic-modal__title" id="' + ID( 'mt' ) + '">Delete “' + esc( w ? w.term : '' ) + '”?</h2>' +
				'<p class="tbtdic-muted">This can’t be undone. Points you earned stay.</p>' + errLine() +
				acts( 'Cancel', 'yes-del-word', 'Delete', { id: M.id, danger: true } );
		} else if ( M.t === 'reset-group' ) {
			const g = group( M.id );
			h = '<h2 class="tbtdic-modal__title" id="' + ID( 'mt' ) + '">Reset progress?</h2>' +
				'<p class="tbtdic-muted">Every word in “' + esc( g ? g.name : '' ) + '” goes back to 0%.</p>' + errLine() +
				acts( 'Cancel', 'yes-reset-group', 'Reset', { id: M.id } );
		} else if ( M.t === 'move' ) {
			const w = word( M.id );
			const others = st.groups.filter( ( g ) => ! w || g.id !== w.group_id ).sort( ( a, b ) => a.name.localeCompare( b.name ) );
			h = '<h2 class="tbtdic-modal__title" id="' + ID( 'mt' ) + '">Move “' + esc( w ? w.term : '' ) + '”</h2>' +
				( others.length
					? '<div class="tbtdic-fld"><label for="' + ID( 'mv' ) + '">To group</label><select class="tbtdic-select" id="' + ID( 'mv' ) + '">' +
						others.map( ( g ) => '<option value="' + g.id + '">' + esc( g.name ) + '</option>' ).join( '' ) + '</select></div>' + errLine() +
						acts( 'Cancel', 'yes-move', 'Move', { id: M.id } )
					: '<p class="tbtdic-muted">You have no other group yet. Add a group first.</p><div class="tbtdic-acts"><button class="tbtdic-btn2" type="button" data-act="close">Close</button></div>' );
		}
		return '<div class="tbtdic-scrim" data-act="scrim"><div class="tbtdic-modal" role="dialog" aria-modal="true" aria-labelledby="' + ID( 'mt' ) + '">' + h + '</div></div>';
	}

	/* ---------- Render ---------- */

	const appEl = document.createElement( 'div' );
	appEl.className = 'tbtdic-app';
	const modalEl = document.createElement( 'div' );
	const toastEl = document.createElement( 'div' );
	toastEl.className = 'tbtdic-toast';
	toastEl.id = ID( 'toast' );
	toastEl.setAttribute( 'role', 'status' );
	toastEl.setAttribute( 'aria-live', 'polite' );
	toastEl.innerHTML = '<span class="tbtdic-toast__star" aria-hidden="true">★</span><span data-toast-text></span>';

	function render( focusId ) {
		const a = document.activeElement;
		const keepId = a && root.contains( a ) && a.id && a.tagName === 'INPUT' ? a.id : null;
		let pos = null;
		try {
			pos = keepId ? a.selectionStart : null;
		} catch ( e ) {
			pos = null;
		}

		let body;
		if ( ! st.loaded ) {
			body = st.loadError
				? '<section class="tbtdic-stage tbtdic-message"><h2 class="tbtdic-stage__title">Your dictionary did not load</h2><p class="tbtdic-muted">' + esc( st.loadError ) + '</p><div><button class="tbtdic-btn" type="button" data-act="reload">Try again</button></div></section>'
				: '<p class="tbtdic-loading" role="status">Loading your dictionary…</p>';
			appEl.innerHTML = body;
			modalEl.innerHTML = '';
			return;
		}
		if ( st.q.trim() ) {
			body = searchView();
		} else {
			body = st.view === 'group' ? groupView() : groupsList();
		}
		appEl.innerHTML = abar() + tabs() + body;
		modalEl.innerHTML = modalHTML();

		const target = focusId ? byId( focusId ) : ( keepId ? document.getElementById( keepId ) : null );
		if ( target ) {
			target.focus();
			if ( ! focusId && null !== pos ) {
				try {
					target.setSelectionRange( pos, pos );
				} catch ( e ) {}
			}
		} else if ( st.modal && ! modalEl.contains( document.activeElement ) ) {
			const f = modalEl.querySelector( 'input, select, .tbtdic-btn' );
			if ( f ) {
				f.focus();
			}
		}
	}

	/* ---------- Loading ---------- */

	function load() {
		st.loadError = '';
		render();
		api( 'GET', 'state' ).then( ( data ) => {
			st.groups = Array.isArray( data.groups ) ? data.groups : [];
			st.words = Array.isArray( data.words ) ? data.words : [];
			st.loaded = true;
			render();
			// A word left pending (the page closed before its fill-in) gets it now.
			st.words.filter( ( w ) => w.ai_status === 'pending' ).forEach( ( w ) => fill( w.id ) );
		} ).catch( ( err ) => {
			st.loadError = errorText( err, 'Could not load your dictionary. Check your connection and try again.' );
			render();
		} );
	}

	/* ---------- Actions ---------- */

	// One fill-in at a time keeps the AI calls (and the daily count) orderly.
	let fillChain = Promise.resolve();
	function fill( id ) {
		fillChain = fillChain.then( () => api( 'POST', 'words/' + id + '/fill' ).then( ( data ) => {
			if ( data.word && word( id ) ) {
				// Keep anything the student is editing on this word.
				putWord( data.word );
				render();
			}
		} ).catch( () => {
			const w = word( id );
			if ( w && w.ai_status === 'pending' ) {
				w.ai_status = 'failed';
				render();
			}
		} ) );
	}

	// The new row's highlight plays once, not on every later re-render.
	let flashTimer = null;
	function flashRow( id ) {
		st.flash = id;
		clearTimeout( flashTimer );
		flashTimer = setTimeout( () => {
			st.flash = null;
		}, 1700 );
	}

	/**
	 * POST /words. Resolves with the response; rejects with the error.
	 *
	 * @param {Object} payload group_id or group_name, term, translation.
	 */
	function createWord( payload ) {
		return api( 'POST', 'words', payload ).then( ( data ) => {
			putGroup( data.group );
			putWord( data.word );
			if ( data.group ) {
				st.lastG = data.group.id;
			}
			flashRow( data.word ? data.word.id : null );
			const pts = Number( data.points ) || 0;
			if ( pts > 0 ) {
				toast( '+' + plural( pts, 'point' ) + ' <small>· new word</small>' );
			}
			if ( data.word ) {
				fill( data.word.id );
			}
			return data;
		} );
	}

	function quickAdd() {
		const q = st.quick;
		const w = q.w.trim();
		if ( ! w ) {
			byId( 'nw' ) && byId( 'nw' ).focus();
			return;
		}
		if ( q.busy ) {
			return;
		}
		q.busy = true;
		q.err = '';
		render();
		createWord( { group_id: st.g, term: w, translation: q.tr.trim() } ).then( () => {
			q.w = '';
			q.tr = '';
			q.busy = false;
			render( 'nw' );
		} ).catch( ( err ) => {
			q.busy = false;
			q.err = errorText( err, 'Could not save that word. Try again.' );
			render( 'nw' );
		} );
	}

	function modalAdd() {
		const M = st.modal;
		const gname = String( M.gname || '' ).trim();
		const w = String( M.w || '' ).trim();
		if ( ! gname ) {
			byId( 'mgrp' ).focus();
			return;
		}
		if ( ! w ) {
			byId( 'mw' ).focus();
			return;
		}
		if ( M.busy ) {
			return;
		}
		const g = groupByName( gname );
		M.busy = true;
		M.err = '';
		render();
		createWord( g ? { group_id: g.id, term: w, translation: String( M.tr || '' ).trim() } : { group_name: gname, term: w, translation: String( M.tr || '' ).trim() } ).then( ( data ) => {
			if ( st.modal !== M ) {
				return;
			}
			M.added.unshift( { w: data.word ? data.word.term : w, g: data.group ? data.group.name : gname } );
			M.gname = data.group ? data.group.name : gname;
			M.w = '';
			M.tr = '';
			M.busy = false;
			render( 'mw' );
		} ).catch( ( err ) => {
			if ( st.modal !== M ) {
				return;
			}
			M.busy = false;
			M.err = errorText( err, 'Could not save that word. Try again.' );
			render( 'mw' );
		} );
	}

	function openGroup( id ) {
		Object.assign( st, { tab: 'groups', view: 'group', g: id, menu: null, edit: null, q: '' } );
		st.quick = { w: '', tr: '', err: '', busy: false };
		st.lastG = id;
		render( 'nw' );
		const top = root.getBoundingClientRect().top + window.pageYOffset - 24;
		if ( window.pageYOffset > top ) {
			window.scrollTo( 0, Math.max( 0, top ) );
		}
	}

	function openModal( m ) {
		st.modal = Object.assign( { err: '', busy: false }, m );
		st.menu = null;
		render();
	}

	/** Run a dialog's request; close it on success, show the error in it otherwise. */
	function modalCall( promise, after ) {
		const M = st.modal;
		M.busy = true;
		M.err = '';
		render();
		promise.then( ( data ) => {
			if ( st.modal === M ) {
				st.modal = null;
			}
			after( data );
		} ).catch( ( err ) => {
			if ( st.modal === M ) {
				M.busy = false;
				M.err = errorText( err );
				render();
			}
		} );
	}

	function saveEdit( id ) {
		const e = st.edit;
		if ( ! e || e.id !== id || e.busy ) {
			return;
		}
		if ( ! e.term.trim() ) {
			e.err = 'The word cannot be empty.';
			render( 'e_w' );
			return;
		}
		e.busy = true;
		e.err = '';
		render();
		api( 'PATCH', 'words/' + id, { term: e.term, translation: e.translation, phonetic: e.phonetic, example: e.example } ).then( ( data ) => {
			putWord( data.word );
			st.edit = null;
			render();
		} ).catch( ( err ) => {
			if ( st.edit === e ) {
				e.busy = false;
				e.err = errorText( err );
				render( 'e_w' );
			}
		} );
	}

	function startEdit( id ) {
		const w = word( id );
		if ( ! w ) {
			return;
		}
		st.edit = { id: id, term: w.term, translation: w.translation, phonetic: w.phonetic, example: w.example, err: '', busy: false };
		st.menu = null;
		render( w.translation ? 'e_w' : 'e_tr' );
	}

	function clearSearch() {
		st.q = '';
		render( 'q' );
	}

	/* ---------- Events ---------- */

	root.addEventListener( 'click', ( e ) => {
		const t = e.target;

		// Close an open menu on any click outside it.
		if ( st.menu && ! t.closest( '.tbtdic-menu' ) && ! t.closest( '[data-menu]' ) ) {
			st.menu = null;
			render();
			if ( ! t.closest( 'button, input, select, label, a' ) ) {
				return;
			}
		}

		const m = t.closest( '[data-menu]' );
		if ( m ) {
			st.menu = st.menu === m.dataset.menu ? null : m.dataset.menu;
			render();
			const first = st.menu && appEl.querySelector( '.tbtdic-menu button' );
			if ( first ) {
				first.focus();
			}
			return;
		}
		const o = t.closest( '[data-open]' );
		if ( o ) {
			openGroup( Number( o.dataset.open ) );
			return;
		}
		const sy = t.closest( '[data-say]' );
		if ( sy ) {
			speak( sy, word( Number( sy.dataset.say ) ) );
			return;
		}
		const b = t.closest( '[data-act]' );
		if ( ! b ) {
			return;
		}
		const id = Number( b.dataset.id ) || 0;
		const A = b.dataset.act;
		if ( A === 'scrim' && t !== b ) {
			return;
		}

		switch ( A ) {
			case 'reload':
				load();
				break;
			case 'home':
				Object.assign( st, { tab: 'groups', view: 'list', g: null, menu: null, edit: null, q: '' } );
				render();
				break;
			case 'clear':
				clearSearch();
				break;
			case 'new-group':
				openModal( { t: 'new-group', name: '' } );
				break;
			case 'new-word': {
				const g = group( st.view === 'group' && ! st.q.trim() ? st.g : st.lastG );
				openModal( { t: 'add-word', gname: g ? g.name : '', w: '', tr: '', added: [] } );
				render( g ? 'mw' : 'mgrp' );
				break;
			}
			case 'modal-add':
				modalAdd();
				break;
			case 'rename': {
				const g = group( id );
				openModal( { t: 'rename', id: id, name: g ? g.name : '' } );
				break;
			}
			case 'del-group':
			case 'reset-group':
			case 'del-word':
			case 'move':
				openModal( { t: A, id: id } );
				break;
			case 'close':
			case 'scrim':
				st.modal = null;
				render();
				break;
			case 'save-group': {
				const name = String( st.modal.name || '' ).trim();
				if ( ! name ) {
					byId( 'mg' ).focus();
					return;
				}
				modalCall( api( 'POST', 'groups', { name: name } ), ( data ) => {
					putGroup( data.group );
					openGroup( data.group.id );
				} );
				break;
			}
			case 'save-rename': {
				const name = String( st.modal.name || '' ).trim();
				if ( ! name ) {
					byId( 'mg' ).focus();
					return;
				}
				modalCall( api( 'PATCH', 'groups/' + id, { name: name } ), ( data ) => {
					putGroup( data.group );
					render();
				} );
				break;
			}
			case 'yes-del-group':
				modalCall( api( 'DELETE', 'groups/' + id ), () => {
					st.words = st.words.filter( ( w ) => w.group_id !== id );
					st.groups = st.groups.filter( ( g ) => g.id !== id );
					if ( st.lastG === id ) {
						st.lastG = null;
					}
					Object.assign( st, { view: 'list', g: null } );
					render();
				} );
				break;
			case 'yes-reset-group':
				modalCall( api( 'POST', 'groups/' + id + '/reset' ), ( data ) => {
					st.words.forEach( ( w ) => {
						if ( w.group_id === id ) {
							w.progress = 0;
							w.percent = 0;
						}
					} );
					putGroup( data.group );
					render();
				} );
				break;
			case 'yes-del-word':
				modalCall( api( 'DELETE', 'words/' + id ), () => {
					st.words = st.words.filter( ( w ) => w.id !== id );
					render();
				} );
				break;
			case 'yes-move': {
				const to = Number( byId( 'mv' ).value );
				modalCall( api( 'PATCH', 'words/' + id, { group_id: to } ), ( data ) => {
					putWord( data.word );
					render();
					const g = group( to );
					toast( 'Moved to ' + esc( g ? g.name : '' ) );
				} );
				break;
			}
			case 'reset-word':
				st.menu = null;
				api( 'POST', 'words/' + id + '/reset' ).then( ( data ) => {
					putWord( data.word );
					render();
				} ).catch( ( err ) => toast( esc( errorText( err ) ) ) );
				render();
				break;
			case 'edit':
				startEdit( id );
				break;
			case 'cancel-edit':
				st.edit = null;
				render();
				break;
			case 'save-edit':
				saveEdit( id );
				break;
			case 'add-word':
				quickAdd();
				break;
		}
	} );

	root.addEventListener( 'input', ( e ) => {
		const t = e.target;
		if ( t.id === ID( 'q' ) ) {
			st.q = t.value;
			st.menu = null;
			st.edit = null;
			render();
			return;
		}
		if ( t.dataset.quick ) {
			st.quick[ t.dataset.quick ] = t.value;
			if ( st.quick.err ) {
				st.quick.err = '';
			}
			return;
		}
		if ( t.dataset.modal && st.modal ) {
			st.modal[ t.dataset.modal ] = t.value;
			if ( t.dataset.modal === 'gname' ) {
				const h = byId( 'mgrp-hint' );
				if ( h ) {
					h.innerHTML = pickerHint();
				}
			}
			return;
		}
		if ( t.dataset.edit && st.edit ) {
			st.edit[ t.dataset.edit ] = t.value;
		}
	} );

	root.addEventListener( 'keydown', ( e ) => {
		const id = e.target.id ? e.target.id.replace( /^tbtdic-/, '' ) : '';
		if ( e.key === 'Escape' ) {
			if ( st.modal ) {
				st.modal = null;
				render();
				return;
			}
			if ( st.menu ) {
				st.menu = null;
				render();
				return;
			}
			if ( st.edit ) {
				st.edit = null;
				render();
				return;
			}
			if ( id === 'q' && st.q ) {
				e.preventDefault();
				clearSearch();
				return;
			}
		}
		if ( e.key === 'Enter' && ! e.isComposing ) {
			const next = ( to ) => {
				e.preventDefault();
				if ( e.target.value.trim() ) {
					byId( to ).focus();
				}
			};
			switch ( id ) {
				case 'nw':
					next( 'ntr' );
					return;
				case 'ntr':
					e.preventDefault();
					quickAdd();
					return;
				case 'mgrp':
					next( 'mw' );
					return;
				case 'mw':
					next( 'mtr' );
					return;
				case 'mtr':
					e.preventDefault();
					modalAdd();
					return;
				case 'mg': {
					e.preventDefault();
					const btn = modalEl.querySelector( '[data-act="save-group"], [data-act="save-rename"]' );
					if ( btn ) {
						btn.click();
					}
					return;
				}
				default:
					if ( /^e_/.test( id ) && st.edit ) {
						e.preventDefault();
						saveEdit( st.edit.id );
					}
			}
		}
	} );

	// "/" focuses the search from anywhere on the page, outside a field.
	document.addEventListener( 'keydown', ( e ) => {
		if ( e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || st.modal || ! st.loaded ) {
			return;
		}
		const tag = ( e.target && e.target.tagName ) || '';
		if ( /INPUT|SELECT|TEXTAREA/.test( tag ) || ( e.target && e.target.isContentEditable ) ) {
			return;
		}
		const q = byId( 'q' );
		if ( q ) {
			e.preventDefault();
			q.focus();
		}
	} );

	root.innerHTML = '';
	root.appendChild( appEl );
	root.appendChild( modalEl );
	root.appendChild( toastEl );
	load();
}() );
