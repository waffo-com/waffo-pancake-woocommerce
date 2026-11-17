/* Registers Waffo Pancake as a payment method in the WooCommerce Checkout block. */
( function () {
	const settings = window.wc.wcSettings.getSetting( 'waffo_pancake_data', {} );
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const title = decodeEntities( settings.title || 'Waffo Pancake' );
	const Content = function () {
		return createElement( 'div', null, decodeEntities( settings.description || '' ) );
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'waffo_pancake',
		label: createElement( 'span', null, title ),
		ariaLabel: title,
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: function () {
			return true;
		},
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
