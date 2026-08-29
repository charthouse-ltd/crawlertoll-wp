/**
 * CrawlerToll — visual paywall cut bar (access-tiers spec §5.2, 2026-08-28).
 *
 * A Gutenberg document sidebar panel that renders the post's top-level blocks
 * as an outline and lets the publisher DRAG a divider to set where the free
 * preview ends and the sealed body begins. Persists to the `_crawlertoll_cut`
 * post meta (block index; 0 = automatic). Runs entirely on WP core externals
 * (wp.*) — this executes inside Gutenberg's React tree, so it must not bundle
 * our own React (hook-dispatcher mismatch). No build step.
 *
 * Free feature: the cut is the free tier's "what's free" control too.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.editPost || ! wp.data || ! wp.element ) {
		return; // classic editor — the metabox covers this case
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;

	/**
	 * Best-effort text extraction from a block (recurses innerBlocks; reads the
	 * common rich-text attributes). Only used for outline labels + word counts.
	 */
	function blockText( block ) {
		var out = '';
		if ( ! block ) {
			return out;
		}
		var attrs = block.attributes || {};
		[ 'content', 'text', 'value', 'citation', 'caption' ].forEach( function ( key ) {
			var v = attrs[ key ];
			if ( typeof v === 'string' ) {
				out += ' ' + v;
			} else if ( v && typeof v.text === 'string' ) {
				out += ' ' + v.text;
			}
		} );
		( block.innerBlocks || [] ).forEach( function ( inner ) {
			out += ' ' + blockText( inner );
		} );
		return out;
	}

	function stripTags( html ) {
		var d = document.createElement( 'div' );
		d.innerHTML = html;
		return d.textContent || '';
	}

	function wordCount( blocks ) {
		var text = blocks
			.map( function ( b ) {
				return stripTags( blockText( b ) );
			} )
			.join( ' ' )
			.trim();
		if ( ! text ) {
			return 0;
		}
		return text.split( /\s+/ ).length;
	}

	function blockLabel( block, i ) {
		var name = ( block.name || '' ).replace( 'core/', '' );
		var text = stripTags( blockText( block ) ).trim().replace( /\s+/g, ' ' );
		if ( text.length > 60 ) {
			text = text.slice( 0, 57 ) + '…';
		}
		return { name: name || 'block', snippet: text, key: block.clientId || String( i ) };
	}

	var styles = {
		outline: { border: '1px solid #ddd', borderRadius: 6, overflow: 'hidden', margin: '8px 0' },
		row: { padding: '7px 10px', fontSize: 12, lineHeight: 1.4, borderBottom: '1px solid #f0f0f0', background: '#fff' },
		rowFree: { background: '#f0faf0' },
		rowSealed: { background: '#fdf6f6', color: '#777' },
		badge: {
			display: 'inline-block',
			fontSize: 10,
			fontWeight: 600,
			textTransform: 'uppercase',
			letterSpacing: '0.04em',
			marginRight: 6,
			padding: '1px 5px',
			borderRadius: 3,
			background: '#e0e0e0',
			color: '#444',
		},
		divider: {
			position: 'relative',
			height: 14,
			cursor: 'ns-resize',
			background: 'transparent',
			display: 'flex',
			alignItems: 'center',
			justifyContent: 'center',
			userSelect: 'none',
			touchAction: 'none',
		},
		dividerLine: { position: 'absolute', left: 0, right: 0, top: 6, height: 2, background: '#2271b1' },
		dividerLineAuto: { background: 'transparent', borderTop: '2px dashed #2271b1', height: 0 },
		dividerGrip: {
			position: 'relative',
			zIndex: 1,
			fontSize: 10,
			fontWeight: 700,
			color: '#fff',
			background: '#2271b1',
			borderRadius: 8,
			padding: '0 8px',
			lineHeight: '14px',
		},
		dividerGripAuto: { background: '#fff', color: '#2271b1', border: '1px solid #2271b1' },
		boundary: { height: 8, cursor: 'pointer', background: 'transparent' },
		boundaryHover: { background: '#d6ecfa' },
		counts: { fontSize: 12, color: '#555', margin: '8px 0 0' },
		warn: { fontSize: 12, color: '#996500', background: '#fcf3d7', borderRadius: 4, padding: '6px 8px', marginTop: 8 },
		note: { fontSize: 12, color: '#666', marginTop: 8 },
	};

	function CutPanel() {
		var sel = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var meta = editor.getEditedPostAttribute( 'meta' ) || {};
			var be = select( 'core/block-editor' );
			var raw = editor.getEditedPostContent ? editor.getEditedPostContent() : '';
			var isBlockMarkup = /<!--\s*wp:/.test( raw );
			var blocks = be ? be.getBlocks() : [];
			// Classic posts (no block markup) open in Gutenberg as ONE Classic
			// block — block granularity would make the bar useless. Mirror the
			// PHP paragraph_boundaries() unit model instead (split on blank
			// lines), so the drag index means the same thing on both sides.
			var units = null;
			if ( ! isBlockMarkup && raw.trim() !== '' ) {
				units = raw.split( /\n\s*\n/ ).map( function ( p ) {
					return p.trim();
				} ).filter( function ( p ) {
					return p !== '';
				} );
			}
			return {
				isPremium: !! meta._crawlertoll_premium,
				cut: typeof meta._crawlertoll_cut === 'number' ? meta._crawlertoll_cut : parseInt( meta._crawlertoll_cut, 10 ) || 0,
				blocks: blocks,
				classicUnits: units,
			};
		}, [] );
		var editPost = wp.data.useDispatch( 'core/editor' ).editPost;

		var dragging = useState( false );
		var isDragging = dragging[ 0 ];
		var setDragging = dragging[ 1 ];
		var previewCut = useState( null ); // live drag position, null = not dragging
		var dragCut = previewCut[ 0 ];
		var setDragCut = previewCut[ 1 ];
		var rowRefs = useRef( [] );

		if ( ! sel.isPremium ) {
			return el(
				wp.editPost.PluginDocumentSettingPanel,
				{ name: 'crawlertoll-cut', title: __( 'Paywall cut', 'crawlertoll' ), icon: 'lock' },
				el( 'p', { style: styles.note },
					__( 'Mark this post as premium (CrawlerToll panel) to choose where the free preview ends and the sealed, paid part begins.', 'crawlertoll' ) )
			);
		}

		var blocks = sel.blocks || [];
		// Normalized outline units: Gutenberg blocks, or classic paragraphs when
		// the post has no block markup (mirrors PHP paragraph_boundaries()).
		var units = [];
		if ( sel.classicUnits ) {
			sel.classicUnits.forEach( function ( p, i ) {
				var text = stripTags( p ).trim().replace( /\s+/g, ' ' );
				units.push( {
					name: '¶ ' + ( i + 1 ),
					snippet: text.length > 60 ? text.slice( 0, 57 ) + '…' : text,
					key: 'p' + i,
					words: text ? text.split( /\s+/ ).length : 0,
				} );
			} );
		} else {
			blocks.forEach( function ( block, i ) {
				var label = blockLabel( block, i );
				units.push( { name: label.name, snippet: label.snippet, key: label.key, words: wordCount( [ block ] ) } );
			} );
		}
		var n = units.length;
		var cut = dragCut !== null ? dragCut : sel.cut; // explicit > live-drag > stored
		if ( cut > n ) {
			cut = 0; // stored index no longer resolves — treat as auto, mirroring split_for_post
		}
		// Where the bar is DISPLAYED. In automatic mode (cut 0) the effective
		// cut sits after the first unit — render the bar there (dashed, labeled
		// "auto") so it is always visible and draggable, instead of vanishing.
		var displayCut = cut === 0 ? 1 : cut;

		function persist( idx ) {
			editPost( { meta: { _crawlertoll_cut: idx } } );
		}

		// Drag: pointer Y → nearest boundary between rows (midpoint rule).
		// Clamped to 1..n: dragging to the very top means "seal after the
		// first unit", NEVER automatic mode — auto is only reachable through
		// the explicit reset link. (Previously the top overshoot returned 0,
		// which silently reset to auto and unmounted the bar mid-drag —
		// Chris: "I dragged it up one paragraph then it disappeared".)
		function boundaryFromY( clientY ) {
			var idx = 1;
			for ( var i = 0; i < n; i++ ) {
				var node = rowRefs.current[ i ];
				if ( ! node ) {
					continue;
				}
				var rect = node.getBoundingClientRect();
				if ( clientY > rect.top + rect.height / 2 ) {
					idx = i + 1;
				}
			}
			return idx < 1 ? 1 : ( idx > n ? n : idx );
		}

		function onPointerDown( e ) {
			e.preventDefault();
			// Capture on the divider itself (currentTarget), not whichever
			// child (line/grip) the pointer landed on — robust during moves.
			var t = e.currentTarget;
			t.setPointerCapture && t.setPointerCapture( e.pointerId );
			setDragging( true );
			setDragCut( displayCut );
		}
		function onPointerMove( e ) {
			if ( isDragging ) {
				setDragCut( boundaryFromY( e.clientY ) );
			}
		}
		function onPointerUp( e ) {
			if ( ! isDragging ) {
				return;
			}
			setDragging( false );
			var idx = boundaryFromY( e.clientY );
			setDragCut( null );
			persist( idx );
		}

		var rows = [];
		units.forEach( function ( unit, i ) {
			var isFree = i < displayCut;
			var rowStyle = Object.assign( {}, styles.row, isFree ? styles.rowFree : styles.rowSealed );
			rows.push(
				el(
					'div',
					{
						key: unit.key,
						style: rowStyle,
						ref: function ( node ) {
							rowRefs.current[ i ] = node;
						},
					},
					el( 'span', { style: styles.badge }, unit.name ),
					unit.snippet || el( 'em', null, __( '(no text)', 'crawlertoll' ) )
				)
			);
			// A cut boundary sits after every row. The ACTIVE cut position gets
			// the drag handle — always rendered (dashed + "auto" label when the
			// cut is automatic); the others are click targets ("move cut here").
			if ( i === displayCut - 1 ) {
				var isAuto = cut === 0 && dragCut === null;
				var lineStyle = Object.assign( {}, styles.dividerLine, isAuto ? styles.dividerLineAuto : null );
				var gripStyle = Object.assign( {}, styles.dividerGrip, isAuto ? styles.dividerGripAuto : null );
				rows.push(
					el(
						'div',
						{
							key: 'cut',
							style: styles.divider,
							role: 'slider',
							'aria-label': __( 'Paywall cut position', 'crawlertoll' ),
							'aria-valuemin': 1,
							'aria-valuemax': n,
							'aria-valuenow': displayCut,
							tabIndex: 0,
							onPointerDown: onPointerDown,
							onPointerMove: onPointerMove,
							onPointerUp: onPointerUp,
							onKeyDown: function ( e ) {
								if ( e.key === 'ArrowDown' && displayCut < n ) {
									e.preventDefault();
									persist( displayCut + 1 );
								} else if ( e.key === 'ArrowUp' && displayCut > 1 ) {
									e.preventDefault();
									persist( displayCut - 1 );
								}
							},
						},
						el( 'div', { style: lineStyle } ),
						el( 'span', { style: gripStyle }, isAuto ? '✂ ' + __( 'auto', 'crawlertoll' ) : '✂ ' + __( 'cut', 'crawlertoll' ) )
					)
				);
			} else if ( n > 1 ) {
				rows.push(
					el( 'div', {
						key: 'b' + i,
						style: styles.boundary,
						title: __( 'Set the cut here', 'crawlertoll' ),
						onClick: function () {
							persist( i + 1 );
						},
					} )
				);
			}
		} );

		var freeUnits = cut === 0 ? units.slice( 0, 1 ) : units.slice( 0, cut );
		var sealedUnits = cut === 0 ? units.slice( 1 ) : units.slice( cut );
		var freeWords = freeUnits.reduce( function ( s, u ) { return s + u.words; }, 0 );
		var sealedWords = sealedUnits.reduce( function ( s, u ) { return s + u.words; }, 0 );

		var children = [];
		if ( n === 0 ) {
			children.push(
				el( 'p', { style: styles.note, key: 'empty' },
					__( 'Start writing to set the paywall cut.', 'crawlertoll' ) )
			);
		} else {
			children.push(
				el( 'p', { style: styles.note, key: 'intro' },
					__( 'Drag the ✂ bar (or click between blocks) to choose what readers see free. Everything below the bar is sealed until they pay or use a free read.', 'crawlertoll' ) ),
				el( 'div', { style: styles.outline, key: 'outline' }, rows ),
				el( 'p', { style: styles.counts, key: 'counts' },
					( cut === 0
						? __( 'Automatic cut (after the first block/paragraph, or at a <!--more--> marker). ', 'crawlertoll' )
						: '' ) +
					__( 'Free: ', 'crawlertoll' ) + freeWords + __( ' words · Sealed: ', 'crawlertoll' ) + sealedWords + __( ' words', 'crawlertoll' ) )
			);
			if ( cut === n ) {
				children.push(
					el( 'p', { style: styles.warn, key: 'warn-allfree' },
						__( 'The whole article is free — nothing left to sell. Drag the bar up.', 'crawlertoll' ) )
				);
			}
			if ( cut > 0 && sealedWords < 20 && cut !== n ) {
				children.push(
					el( 'p', { style: styles.warn, key: 'warn-thin' },
						__( 'Very little is sealed — readers may not find this worth paying for.', 'crawlertoll' ) )
				);
			}
			if ( cut > 0 ) {
				children.push(
					el(
						wp.components.Button,
						{ key: 'reset', variant: 'link', style: { marginTop: 4 }, onClick: function () { persist( 0 ); } },
						__( 'Reset to automatic cut', 'crawlertoll' )
					)
				);
			}
		}

		return el(
			wp.editPost.PluginDocumentSettingPanel,
			{ name: 'crawlertoll-cut', title: __( 'Paywall cut', 'crawlertoll' ), icon: 'lock' },
			children
		);
	}

	wp.plugins.registerPlugin( 'crawlertoll-cut', { render: CutPanel, icon: 'lock' } );

	// ── In-canvas cut marker ───────────────────────────────────────────────
	// Renders a dashed "sealed from here" line directly in the editor canvas
	// above the first sealed block, so the publisher sees the cut while
	// writing — not only in the sidebar. Block posts only: classic posts are
	// a single Classic block, so their paragraph-level cut stays in the
	// sidebar outline. The marker is non-editable chrome, never post content.
	if ( wp.hooks && wp.compose ) {
		var markerStyles = {
			wrap: { display: 'flex', alignItems: 'center', gap: 8, margin: '2px 0 6px', userSelect: 'none' },
			line: { flex: 1, borderTop: '2px dashed #b32d2e' },
			tag: { fontSize: 11, fontWeight: 600, color: '#b32d2e', whiteSpace: 'nowrap', fontFamily: 'sans-serif' },
		};
		var withCutMarker = wp.compose.createHigherOrderComponent( function ( BlockListBlock ) {
			return function ( props ) {
				var info = wp.data.useSelect( function ( select ) {
					var editor = select( 'core/editor' );
					var be = select( 'core/block-editor' );
					if ( ! editor || ! be ) {
						return { premium: false, cut: 0, blockMarkup: false, isTopLevel: false, index: -1, total: 0 };
					}
					var meta = editor.getEditedPostAttribute( 'meta' ) || {};
					var raw = editor.getEditedPostContent ? editor.getEditedPostContent() : '';
					var rootId = be.getBlockRootClientId ? be.getBlockRootClientId( props.clientId ) : null;
					return {
						premium: !! meta._crawlertoll_premium,
						cut: typeof meta._crawlertoll_cut === 'number' ? meta._crawlertoll_cut : parseInt( meta._crawlertoll_cut, 10 ) || 0,
						blockMarkup: /<!--\s*wp:/.test( raw ),
						isTopLevel: ! rootId,
						index: be.getBlockIndex ? be.getBlockIndex( props.clientId ) : -1,
						total: be.getBlockCount ? be.getBlockCount() : ( be.getBlocks() || [] ).length,
					};
				}, [ props.clientId ] );

				var marker = null;
				// The cut seals AFTER unit N → the first sealed block sits at
				// 0-based index N (automatic mode: after block 1 → index 1).
				var sealedFrom = info.cut > 0 ? info.cut : 1;
				if ( info.premium && info.blockMarkup && info.isTopLevel && info.index === sealedFrom && sealedFrom < info.total ) {
					marker = el(
						'div',
						{ style: markerStyles.wrap, contentEditable: 'false' },
						el( 'span', { style: markerStyles.line } ),
						el(
							'span',
							{ style: markerStyles.tag },
							'🔒 ' + ( info.cut > 0
								? __( 'Sealed from here — readers pay or use a free read', 'crawlertoll' )
								: __( 'Automatic cut — sealed from here', 'crawlertoll' ) )
						),
						el( 'span', { style: markerStyles.line } )
					);
				}
				return el( wp.element.Fragment, null, marker, el( BlockListBlock, props ) );
			};
		}, 'withCrawlerTollCutMarker' );
		wp.hooks.addFilter( 'editor.BlockListBlock', 'crawlertoll/cut-marker', withCutMarker );
	}
} )( window.wp );
