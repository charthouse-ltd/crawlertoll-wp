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
		boundary: { height: 8, cursor: 'pointer', background: 'transparent' },
		boundaryHover: { background: '#d6ecfa' },
		counts: { fontSize: 12, color: '#555', margin: '8px 0 0' },
		warn: { fontSize: 12, color: '#996500', background: '#fcf3d7', borderRadius: 4, padding: '6px 8px', marginTop: 8 },
		note: { fontSize: 12, color: '#666', marginTop: 8 },
	};

	function CutPanel() {
		var sel = wp.data.useSelect( function ( select ) {
			var meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
			var be = select( 'core/block-editor' );
			return {
				isPremium: !! meta._crawlertoll_premium,
				cut: typeof meta._crawlertoll_cut === 'number' ? meta._crawlertoll_cut : parseInt( meta._crawlertoll_cut, 10 ) || 0,
				blocks: be ? be.getBlocks() : [],
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
		var n = blocks.length;
		var cut = dragCut !== null ? dragCut : sel.cut; // explicit > live-drag > stored
		if ( cut > n ) {
			cut = 0; // stored index no longer resolves — treat as auto, mirroring split_for_post
		}

		function persist( idx ) {
			editPost( { meta: { _crawlertoll_cut: idx } } );
		}

		// Drag: pointer Y → nearest boundary between rows (midpoint rule).
		function boundaryFromY( clientY ) {
			var idx = 0;
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
			return idx;
		}

		function onPointerDown( e ) {
			e.preventDefault();
			e.target.setPointerCapture && e.target.setPointerCapture( e.pointerId );
			setDragging( true );
			setDragCut( sel.cut > 0 && sel.cut <= n ? sel.cut : 1 );
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
			persist( idx >= 1 && idx <= n ? idx : 0 );
		}

		var rows = [];
		blocks.forEach( function ( block, i ) {
			var label = blockLabel( block, i );
			var isFree = cut === 0 ? i === 0 : i < cut;
			var rowStyle = Object.assign( {}, styles.row, isFree ? styles.rowFree : styles.rowSealed );
			rows.push(
				el(
					'div',
					{
						key: label.key,
						style: rowStyle,
						ref: function ( node ) {
							rowRefs.current[ i ] = node;
						},
					},
					el( 'span', { style: styles.badge }, label.name ),
					label.snippet || el( 'em', null, __( '(no text)', 'crawlertoll' ) )
				)
			);
			// A cut boundary sits after every row. The ACTIVE cut gets the drag
			// handle; the others are click targets ("move the cut here").
			if ( cut > 0 && i === cut - 1 ) {
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
							'aria-valuenow': cut,
							tabIndex: 0,
							onPointerDown: onPointerDown,
							onPointerMove: onPointerMove,
							onPointerUp: onPointerUp,
							onKeyDown: function ( e ) {
								if ( e.key === 'ArrowDown' && cut < n ) {
									e.preventDefault();
									persist( cut + 1 );
								} else if ( e.key === 'ArrowUp' && cut > 1 ) {
									e.preventDefault();
									persist( cut - 1 );
								}
							},
						},
						el( 'div', { style: styles.dividerLine } ),
						el( 'span', { style: styles.dividerGrip }, '✂ ' + __( 'cut', 'crawlertoll' ) )
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

		var freeBlocks = cut === 0 ? blocks.slice( 0, 1 ) : blocks.slice( 0, cut );
		var sealedBlocks = cut === 0 ? blocks.slice( 1 ) : blocks.slice( cut );
		var freeWords = wordCount( freeBlocks );
		var sealedWords = wordCount( sealedBlocks );

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
						? __( 'Automatic cut (after the first block, or at a <!--more--> marker). ', 'crawlertoll' )
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
} )( window.wp );
