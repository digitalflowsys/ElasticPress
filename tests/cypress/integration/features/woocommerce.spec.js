describe('WooCommerce Feature', () => {
	const userData = {
		username: 'testuser',
		email: 'testuser@example.com',
		firstName: 'John',
		lastName: 'Doe',
		address: '123 Main St',
		city: 'Culver City',
		postCode: '90230',
		phoneNumber: '1234567890',
	};

	before(() => {
		cy.deactivatePlugin('woocommerce', 'wpCli');
	});

	after(() => {
		cy.deactivatePlugin('woocommerce', 'wpCli');
	});

	it('Can auto-activate the feature', () => {
		cy.login();

		cy.activatePlugin('woocommerce');

		cy.visitAdminPage('admin.php?page=elasticpress');
		cy.get('.ep-feature-woocommerce').should('have.class', 'feature-active');
	});

	it('Can automatically start a sync if activate the feature', () => {
		cy.login();

		cy.maybeDisableFeature('woocommerce');

		cy.visitAdminPage('admin.php?page=elasticpress');
		cy.get('.ep-feature-woocommerce .settings-button').click();
		cy.get('.ep-feature-woocommerce [name="settings[active]"][value="1"]').click();
		cy.get('.ep-feature-woocommerce .button-primary').click();
		cy.on('window:confirm', () => {
			return true;
		});

		cy.get('.ep-sync-panel').last().as('syncPanel');
		cy.get('@syncPanel').find('.components-form-toggle').click();
		cy.get('@syncPanel')
			.find('.ep-sync-messages', { timeout: Cypress.config('elasticPressIndexTimeout') })
			.should('contain.text', 'Mapping sent')
			.should('contain.text', 'Sync complete');

		cy.wpCli('elasticpress list-features').its('stdout').should('contain', 'woocommerce');
	});

	it('Can fetch orders from Elasticsearch', () => {
		cy.login();

		cy.maybeEnableFeature('protected_content');
		cy.maybeEnableFeature('woocommerce');

		// this is required to sync the orders to Elasticsearch.
		cy.wpCli('elasticpress index --setup --yes');

		cy.visitAdminPage('edit.php?post_type=shop_order');
		cy.get('#debug-menu-target-EP_Debug_Bar_ElasticPress .ep-query-debug').should(
			'contain.text',
			'Query Response Code: HTTP 200',
		);
	});

	it('Can fetch products from Elasticsearch in WP Dashboard', () => {
		cy.login();

		cy.maybeEnableFeature('protected_content');
		cy.maybeEnableFeature('woocommerce');

		cy.visitAdminPage('edit.php?post_type=product');
		cy.get('#debug-menu-target-EP_Debug_Bar_ElasticPress .ep-query-debug').should(
			'contain.text',
			'Query Response Code: HTTP 200',
		);
	});

	it('Can fetch products from Elasticsearch in product category archives', () => {
		cy.login();

		cy.maybeEnableFeature('woocommerce');

		cy.visit('/product-category/uncategorized');
		cy.get('#debug-menu-target-EP_Debug_Bar_ElasticPress .ep-query-debug').should(
			'contain.text',
			'Query Response Code: HTTP 200',
		);
	});

	it('Can fetch products from Elasticsearch in product rivers', () => {
		cy.login();

		cy.maybeEnableFeature('woocommerce');

		cy.visit('/shop/?filter_size=small');
		cy.get('#debug-menu-target-EP_Debug_Bar_ElasticPress .ep-query-debug').should(
			'contain.text',
			'Query Response Code: HTTP 200',
		);
	});

	it('Can not display other users orders on the My Account Order page', () => {
		cy.login();

		cy.activatePlugin('woocommerce');
		cy.activatePlugin('enable-debug-bar');

		cy.maybeEnableFeature('protected_content');
		cy.maybeEnableFeature('woocommerce');

		// enable payment gateway.
		cy.visitAdminPage('admin.php?page=wc-settings&tab=checkout&section=cod');
		cy.get('#woocommerce_cod_enabled').check();
		cy.get('.button-primary.woocommerce-save-button').click();

		cy.logout();

		// create new user.
		cy.createUser({
			username: userData.username,
			email: userData.email,
			login: true,
		});

		// add product to cart.
		cy.visit('product/fantastic-silk-knife');
		cy.get('.single_add_to_cart_button').click();

		// checkout and place order.
		cy.visit('checkout');
		cy.get('#billing_first_name').type(userData.firstName);
		cy.get('#billing_last_name').type(userData.lastName);
		cy.get('#billing_address_1').type(userData.address);
		cy.get('#billing_city').type(userData.city);
		cy.get('#billing_postcode').type(userData.postCode);
		cy.get('#billing_phone').type(userData.phoneNumber);
		cy.get('#place_order').click();

		// ensure order is placed.
		cy.url().should('include', '/checkout/order-received');

		// ensure order is visible to user.
		cy.visit('my-account/orders');
		cy.get('.woocommerce-orders-table tbody tr').should('have.length', 1);

		// Test orderby parameter set to `date` in query.
		cy.get('#debug-menu-target-EP_Debug_Bar_ElasticPress .ep-query-debug')
			.should('contain.text', 'shop_order')
			.should('contain.text', "'orderby' => 'date'");

		cy.logout();

		cy.createUser({
			username: 'buyer',
			email: 'buyer@example.com',
			login: true,
		});

		// ensure no order is show.
		cy.visit('my-account/orders');
		cy.get('.woocommerce-orders-table tbody tr').should('have.length', 0);

		cy.get('#debug-menu-target-EP_Debug_Bar_ElasticPress .ep-query-debug')
			.should('contain.text', 'shop_order')
			.should('contain.text', 'Query Response Code: HTTP 200');
	});

	it('Can search orders from ElasticPress in WP Dashboard', () => {
		cy.login();

		cy.activatePlugin('woocommerce');
		cy.maybeEnableFeature('protected_content');
		cy.maybeEnableFeature('woocommerce');

		cy.visitAdminPage('edit.php?post_type=shop_order');

		// search order by user's name.
		cy.get('#post-search-input')
			.clear()
			.type(`${userData.firstName} ${userData.lastName}{enter}`);

		cy.get('#debug-menu-target-EP_Debug_Bar_ElasticPress .ep-query-debug').should(
			'contain.text',
			'Query Response Code: HTTP 200',
		);

		cy.get('.order_number .order-view').should(
			'contain.text',
			`${userData.firstName} ${userData.lastName}`,
		);

		// search order by user's address.
		cy.get('#post-search-input').clear().type(`${userData.address}{enter}`);
		cy.get('#debug-menu-target-EP_Debug_Bar_ElasticPress .ep-query-debug').should(
			'contain.text',
			'Query Response Code: HTTP 200',
		);

		cy.get('.order_number .order-view').should(
			'contain.text',
			`${userData.firstName} ${userData.lastName}`,
		);

		// search order by product.
		cy.get('#post-search-input').clear().type(`fantastic-silk-knife{enter}`);
		cy.get('#debug-menu-target-EP_Debug_Bar_ElasticPress .ep-query-debug').should(
			'contain.text',
			'Query Response Code: HTTP 200',
		);

		cy.get('.order_number .order-view').should(
			'contain.text',
			`${userData.firstName} ${userData.lastName}`,
		);
	});

	it('Can Search Product by Variation SKU', () => {
		cy.login();
		cy.activatePlugin('woocommerce', 'wpCli');
		cy.maybeEnableFeature('woocommerce');

		cy.updateWeighting({
			product: {
				'meta._variations_skus.value': {
					weight: 1,
					enabled: true,
				},
			},
		}).then(() => {
			cy.wpCli('elasticpress index --setup --yes').then(() => {
				/**
				 * Give Elasticsearch some time. Apparently, if the visit happens right after the index, it won't find anything.
				 *
				 */
				// eslint-disable-next-line cypress/no-unnecessary-waiting
				cy.wait(2000);
				cy.visit('/?s=awesome-aluminum-shoes-variation-sku');
				cy.contains(
					'.site-content article:nth-of-type(1) h2',
					'Awesome Aluminum Shoes',
				).should('exist');
			});
		});
	});
});
