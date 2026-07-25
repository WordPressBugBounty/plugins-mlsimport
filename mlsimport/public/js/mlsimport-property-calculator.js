/**
 * Standalone property mortgage calculator. Pure browser math. Recomputes the
 * estimated monthly payment — principal & interest plus property tax, home
 * insurance, PMI (when < 20% down) and HOA — and updates the payment-breakdown
 * bar and legend as inputs change. No jQuery.
 */
( function () {
	/**
	 * Format a number as a rounded, thousands-separated dollar string (e.g. "$ 1,234").
	 *
	 * @param {number} n Amount to format.
	 * @return {string} Formatted currency string.
	 */
	function money( n ) {
		return '$ ' + Math.round( n ).toLocaleString();
	}

	/**
	 * Read a numeric calculator input by its `data-calc` name, defaulting to 0 when absent/invalid.
	 *
	 * @param {Element} root Calculator widget root.
	 * @param {string}  name Value of the target element's `data-calc` attribute.
	 * @return {number} Parsed float, or 0 when missing or NaN.
	 */
	function num( root, name ) {
		var el = root.querySelector( '[data-calc="' + name + '"]' );
		var v  = el ? parseFloat( el.value ) : NaN;
		return isNaN( v ) ? 0 : v;
	}

	/**
	 * Recompute the estimated monthly payment and repaint the breakdown bar and legend.
	 *
	 * @param {Element} root Calculator widget root.
	 * @return {void}
	 */
	function compute( root ) {
		// Gather all raw inputs: price, down-payment %, rate %, term (years), tax, insurance, HOA.
		var price = num( root, 'price' );
		var down  = num( root, 'down' );
		var rate  = num( root, 'rate' );
		var term  = num( root, 'term' );
		var tax   = num( root, 'tax' );
		var ins   = num( root, 'ins' );
		var hoa   = num( root, 'hoa' );

		// Loan principal after down payment; monthly rate and total number of payments.
		var principal = Math.max( 0, price * ( 1 - down / 100 ) );
		var monthly   = rate / 100 / 12;
		var n         = term * 12;

		// Principal & interest: guard the degenerate cases (no term, zero interest) before the amortization formula.
		var pi;
		if ( n <= 0 ) {
			pi = 0;
		} else if ( monthly === 0 ) {
			pi = principal / n;
		} else {
			pi = principal * ( monthly * Math.pow( 1 + monthly, n ) ) / ( Math.pow( 1 + monthly, n ) - 1 );
		}

		// PMI applies only when the down payment is under 20% (0.5%/yr of principal, monthly).
		var pmi  = down < 20 ? principal * 0.005 / 12 : 0;
		// Collect every monthly segment keyed by its data attribute suffix.
		var segs = { pi: pi, tax: tax, ins: ins, pmi: pmi, hoa: hoa };

		// Total monthly payment and its display element.
		var total = pi + tax + ins + pmi + hoa;
		var out   = root.querySelector( '[data-calc="result"]' );
		if ( out ) {
			out.textContent = money( total ) + ' /mo';
		}

		// Sum the segments for proportional bar widths; avoid divide-by-zero.
		var sum = 0;
		Object.keys( segs ).forEach( function ( k ) {
			sum += segs[ k ];
		} );
		if ( sum <= 0 ) {
			sum = 1;
		}

		// For each segment, size its bar, print its amount, and hide empty ones.
		Object.keys( segs ).forEach( function ( k ) {
			// Treat sub-half-dollar segments as empty (hidden from bar and legend).
			var visible = segs[ k ] > 0.5;

			// Bar segment: width proportional to its share of the total; display toggled by visibility.
			var bar = root.querySelector( '[data-seg="' + k + '"]' );
			if ( bar ) {
				bar.style.width   = ( segs[ k ] / sum * 100 ) + '%';
				bar.style.display = visible ? 'block' : 'none';
			}
			// Amount label for this segment.
			var amt = root.querySelector( '[data-amt="' + k + '"]' );
			if ( amt ) {
				amt.textContent = money( segs[ k ] );
			}
			// Legend row: hidden when the segment is empty.
			var li = root.querySelector( '[data-legend="' + k + '"]' );
			if ( li ) {
				li.style.display = visible ? '' : 'none';
			}
		} );
	}

	/**
	 * Initialise every calculator on the page: recompute on any input and once up front.
	 *
	 * @return {void}
	 */
	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-mlsimport-calculator]' ), function ( root ) {
			// Recompute whenever any field within the widget changes.
			root.addEventListener( 'input', function () {
				compute( root );
			} );
			// Seed the initial result on load.
			compute( root );
		} );
	}

	// Defer init until the DOM is ready; run immediately if it already is.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
